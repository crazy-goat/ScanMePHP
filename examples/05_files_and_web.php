<?php

declare(strict_types=1);

/**
 * Getting a symbol out of PHP: files, data URIs and HTTP responses.
 *
 * Run: php examples/05_files_and_web.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\Exception\FileWriteException;
use CrazyGoat\ScanMePHP\Format;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\SvgOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;

$scanme = Scanme::create();
$assets = __DIR__ . '/generated-assets';

if (!is_dir($assets)) {
    mkdir($assets, 0o775, true);
}

echo "=== Straight to a file ===\n\n";

// toFile() writes under LOCK_EX, so a concurrent request cannot read a
// half-written image, and it checks the directory first to fail with a reason
// rather than a file_put_contents() warning.
$scanme->toFile(
    $assets . '/ticket.png',
    'TICKET-2026-0042',
    Symbology::Code128,
    Format::Png,
    new PngOptions(moduleSize: 3, barHeight: 40)
);
printf("wrote ticket.png (%d bytes)\n\n", filesize($assets . '/ticket.png'));

echo "=== An unwritable destination says so ===\n\n";

try {
    $scanme->toFile('/definitely/not/a/directory/x.png', 'x', 'qrcode', 'png');
} catch (FileWriteException $e) {
    echo '  ' . $e->getMessage() . "\n\n";
}

echo "=== Data URIs, for an <img src> or a CSS background ===\n\n";

// Each renderer declares the options class it reads, and a bag of the wrong
// type is refused rather than partially applied — the options that would not
// fit are exactly the ones the caller cared about.
$bags = [Format::Svg->value => new SvgOptions(moduleSize: 4), Format::Png->value => new PngOptions(moduleSize: 4)];

foreach ($bags as $format => $options) {
    $uri = $scanme->dataUri('https://scanmephp.dev', Symbology::QrCode, $format, $options);
    printf("%-4s %s...\n", $format, substr($uri, 0, 60));
}

echo "\n=== Serving one over HTTP ===\n\n";

// The renderer knows its own MIME type, so a controller never hardcodes one.
$format = Format::Svg;
$body = $scanme->render('https://scanmephp.dev', Symbology::QrCode, $format, new SvgOptions(moduleSize: 6));

printf("Content-Type: %s\n", $scanme->getContentType($format));
printf("Content-Length: %d\n\n", \strlen($body));

echo "  In a controller that would be:\n\n";
echo "      return new Response(\n";
echo "          \$scanme->render(\$payload, Symbology::QrCode, Format::Svg),\n";
echo "          200,\n";
echo "          ['Content-Type' => \$scanme->getContentType(Format::Svg)],\n";
echo "      );\n\n";

echo "=== An embeddable page ===\n\n";

// fullDocument wraps the markup in a standalone HTML file; without it you get
// a fragment to drop into a template.
$fragment = $scanme->render('https://scanmephp.dev', Symbology::QrCode, Format::HtmlDiv);
printf("fragment:      %d bytes, starts with %s\n", \strlen($fragment), substr(trim($fragment), 0, 32));

echo "\nDone.\n";
