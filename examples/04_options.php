<?php

declare(strict_types=1);

/**
 * Options: what changes the modules, and what only changes the picture.
 *
 * The split is deliberate. Generator options change what is encoded — a higher
 * QR error correction level spends capacity and can grow the symbol. Render
 * options change how the same modules are drawn. Bags are routed by the
 * interface they implement, so order does not matter and either may be left
 * out; a bag nobody claims is an error rather than a silent no-op.
 *
 * Run: php examples/04_options.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Generator\Aztec\AztecOptions;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\DataMatrixOptions;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\ModuleStyle;
use CrazyGoat\ScanMePHP\Renderer\Options\AsciiOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\SvgOptions;
use CrazyGoat\ScanMePHP\Scanme;

$scanme = Scanme::create();
$assets = __DIR__ . '/generated-assets';

if (!is_dir($assets)) {
    mkdir($assets, 0o775, true);
}

$data = 'https://github.com/crazy-goat/ScanMePHP';

echo "=== Generator options change the symbol ===\n\n";

foreach (ErrorCorrectionLevel::cases() as $level) {
    $symbol = $scanme->generate($data, 'qrcode', new QrOptions(errorCorrection: $level));
    printf(
        "%-8s %2d x %2d modules (version %s)\n",
        $level->name,
        $symbol->getWidth(),
        $symbol->getHeight(),
        $symbol->getMetadataValue('version')
    );
}

echo "\n";

// A version floor is a request for a minimum, not an exact size: data that
// does not fit still grows the symbol.
$pinned = $scanme->generate('short', 'qrcode', new QrOptions(version: 7));
printf("version pinned to 7:  %d x %d modules\n\n", $pinned->getWidth(), $pinned->getHeight());

// Data Matrix has no error correction to choose — ECC200 fixes it — so its bag
// carries shape instead.
$square = $scanme->generate('ScanMePHP', 'data-matrix');
$oblong = $scanme->generate('ScanMePHP', 'data-matrix', new DataMatrixOptions(rectangular: true));
printf("data matrix square:      %d x %d\n", $square->getWidth(), $square->getHeight());
printf("data matrix rectangular: %d x %d\n\n", $oblong->getWidth(), $oblong->getHeight());

// Aztec has no levels either, but for a different reason: it has a percentage,
// and the percentage is a floor rather than a target. Raising it costs a larger
// symbol until the leftover capacity swamps the request — 40% and 80% land on
// the same symbol here, because once the data has moved up a size there is far
// more room to spare than either asked for.
foreach ([5, 40, 80] as $percent) {
    $symbol = $scanme->generate('BOARDING-4471', 'aztec', new AztecOptions(errorCorrectionPercent: $percent));
    printf(
        "aztec at %2d%%:  %d x %d modules, %d of %d codewords for recovery\n",
        $percent,
        $symbol->getWidth(),
        $symbol->getHeight(),
        $symbol->getMetadata()['totalWords'] - $symbol->getMetadata()['dataWords'],
        $symbol->getMetadata()['totalWords']
    );
}

// A size names one symbol, which is why the option is a size and not a layer
// count: four layers is a compact 27-module symbol and a full 31-module one.
$pinnedAztec = $scanme->generate('BOARDING-4471', 'aztec', new AztecOptions(size: 31));
printf("aztec pinned to 31:  %d x %d modules\n\n", $pinnedAztec->getWidth(), $pinnedAztec->getHeight());

echo "=== Render options change only the picture ===\n\n";

$symbol = $scanme->generate($data, 'qrcode');

$variants = [
    'plain' => new SvgOptions(moduleSize: 8),
    'coloured' => new SvgOptions(moduleSize: 8, foregroundColor: '#1B3A57', backgroundColor: '#F5F0E1'),
    'rounded' => new SvgOptions(moduleSize: 8, moduleStyle: ModuleStyle::Rounded),
    'dots' => new SvgOptions(moduleSize: 8, moduleStyle: ModuleStyle::Dot),
    'labelled' => new SvgOptions(moduleSize: 8, label: 'Scan me'),
    'tight' => new SvgOptions(moduleSize: 8, quietZone: 1),
    'inverted' => new SvgOptions(moduleSize: 8, invert: true),
];

foreach ($variants as $name => $options) {
    $svg = $scanme->renderSymbol($symbol, 'svg', $options);
    file_put_contents($assets . '/qrcode-' . $name . '.svg', $svg);
    printf("%-10s %6d bytes -> qrcode-%s.svg\n", $name, \strlen($svg), $name);
}

echo "\n=== The quiet zone defaults to what the symbology needs ===\n\n";

// Four modules for QR, eleven left and seven right for EAN-13: those widths
// are part of being scannable, not a matter of taste. An explicit value still
// wins — including a smaller one, which is the caller's call to make.
foreach (['qrcode' => $data, 'ean13' => '5901234123457', 'upc-e' => '04252614'] as $symbology => $payload) {
    $quietZone = $scanme->generate($payload, $symbology)->getQuietZone();
    printf(
        "%-8s left %2d  right %2d  top %d  bottom %d\n",
        $symbology,
        $quietZone->left,
        $quietZone->right,
        $quietZone->top,
        $quietZone->bottom
    );
}

echo "\n=== PNG compression trades size for time ===\n\n";

foreach ([0, 1, 6, 9] as $level) {
    $png = $scanme->renderSymbol($symbol, 'png', new PngOptions(moduleSize: 8, compressionLevel: $level));
    printf("level %d  %6d bytes\n", $level, \strlen($png));
}

echo "\n=== Suppressing the human-readable text ===\n\n";

// Linear symbologies carry their digits, and renderers print them by default.
// A layout that prints the number itself wants the bars alone.
echo $scanme->render('96385074', 'ean8', 'ascii-half-blocks', new AsciiOptions(barHeight: 6, sideMargin: 2));
echo "\n";
echo $scanme->render(
    '96385074',
    'ean8',
    'ascii-half-blocks',
    new AsciiOptions(barHeight: 6, sideMargin: 2, showText: false)
);

echo "\nDone.\n";
