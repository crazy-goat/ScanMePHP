<?php

declare(strict_types=1);

/**
 * When a symbology and a renderer do not fit, and how you find out.
 *
 * Renderers are swappable, including ones written outside this library, so the
 * facade cannot assume every renderer copes with every symbol. The alternative
 * to reporting a mismatch is emitting something that looks like a barcode and
 * does not scan, which is the worst possible outcome: it fails at the till,
 * not in the test suite.
 *
 * Run: php examples/06_compatibility.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\Compatibility;
use CrazyGoat\ScanMePHP\Exception\IncompatibleRendererException;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\RenderOptionsInterface;
use CrazyGoat\ScanMePHP\Renderer\Options\AsciiOptions;
use CrazyGoat\ScanMePHP\Renderer\RendererCapabilities;
use CrazyGoat\ScanMePHP\Renderer\RendererInterface;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbol;

$scanme = Scanme::create();
$registry = $scanme->getRegistry();

echo "=== The whole matrix, asked without encoding anything ===\n\n";

$formats = $registry->rendererFormats();

// Abbreviated so the grid fits a terminal; the legend keeps it honest.
$heading = static fn (string $format): string => str_replace(
    ['html-', 'ascii-half-blocks', 'ascii-blocks', 'ascii-dots'],
    ['', 'half', 'blk', 'dot'],
    $format
);

printf('%-13s', '');
foreach ($formats as $format) {
    printf('%-7s', $heading($format));
}
echo "\n";

foreach (array_keys($registry->describeGenerators()) as $symbology) {
    printf('%-13s', $symbology);
    foreach ($formats as $format) {
        printf('%-7s', $scanme->supports($symbology, $format) ? 'yes' : 'no');
    }
    echo "\n";
}

printf("\n  %s\n", implode('  ', array_map(
    static fn (string $format): string => $heading($format) . ' = ' . $format,
    $formats
)));

echo "\nEvery built-in pair fits today: all seven symbologies draw square\n";
echo "modules, and every renderer can print text. That is a fact about this\n";
echo "particular set, not a guarantee — MaxiCode's hexagons and any renderer\n";
echo "you write yourself are exactly what the machinery below is for.\n";

echo "\n=== A renderer that admits its limits ===\n\n";

// A plotter driver, a label-printer protocol, a fixed-width thermal head: all
// real reasons a renderer might not draw everything. This one declares that it
// draws only square modules and cannot print text at all.
$barsOnly = new class () implements RendererInterface {
    public function getFormat(): string
    {
        return 'bars-only';
    }

    public function getContentType(): string
    {
        return 'text/plain';
    }

    public function getCapabilities(): RendererCapabilities
    {
        return new RendererCapabilities(
            moduleShapes: [ModuleShape::Square],
            text: false,
            color: false,
        );
    }

    public function render(Symbol $symbol, ?RenderOptionsInterface $options = null): string
    {
        return substr($symbol->toModuleString(), 0, $symbol->getWidth()) . "\n";
    }
};

$registry->addRenderer($barsOnly);

// QR carries no human-readable text, so nothing is lost and it renders.
echo "  qrcode -> bars-only:\n";
printf("    %s\n\n", substr($scanme->render('ScanMePHP', 'qrcode', 'bars-only'), 0, 60) . '...');

// EAN-13 does carry text, and dropping it silently would produce a symbol
// missing half of what the standard requires be printed.
echo "  ean13 -> bars-only:\n";

try {
    $scanme->render('5901234123457', 'ean13', 'bars-only');
} catch (IncompatibleRendererException $e) {
    echo '    ' . $e->getMessage() . "\n\n";
}

// The message names the way out, and taking it is a decision the caller makes
// explicitly rather than one the library makes for them.
echo "  ean13 -> bars-only, showText: false:\n";
printf(
    "    %s\n",
    trim($scanme->render('5901234123457', 'ean13', 'bars-only', new AsciiOptions(showText: false)))
);

echo "\n=== Asking before rendering ===\n\n";

// Compatibility::check() answers the same question about an already-built
// symbol, for a caller who would rather branch than catch.
$symbol = $scanme->generate('5901234123457', 'ean13');

foreach ([null, new AsciiOptions(showText: false)] as $options) {
    $reasons = Compatibility::check($symbol, $barsOnly, $options);
    printf(
        "  showText %-5s %s\n",
        $options === null ? 'true' : 'false',
        $reasons === [] ? 'renders' : implode('; ', $reasons)
    );
}

echo "\n=== Data a symbology cannot take at all ===\n\n";

// A different failure: not the renderer, the encoder. The message names what
// the symbology does accept rather than only what was wrong with the input.
foreach ([['ean13', '12345'], ['upc-e', '036000291452'], ['code128', "tab\there"]] as [$symbology, $data]) {
    try {
        $scanme->generate($data, $symbology);
        printf("  %-12s %-16s encoded\n", $symbology, $data);
    } catch (UnsupportedDataException $e) {
        printf("  %-12s %-16s %s\n", $symbology, addcslashes($data, "\t"), $e->getMessage());
    }
}

echo "\nDone.\n";
