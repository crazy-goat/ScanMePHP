<?php

declare(strict_types=1);

/**
 * The shortest path from a payload to bytes.
 *
 * Everything this library does goes through one call: pick a symbology, pick
 * an output format, get a string. There is no builder to assemble, no engine
 * to inject, no object to keep alive between calls.
 *
 * Run: php examples/01_quickstart.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\Format;
use CrazyGoat\ScanMePHP\Renderer\Options\AsciiOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;

$scanme = Scanme::create();

echo "1. A QR code as SVG\n";
$svg = $scanme->render('https://github.com/crazy-goat/ScanMePHP', 'qrcode', 'svg');
printf("   %d bytes of SVG\n\n", \strlen($svg));

echo "2. The same payload as PNG\n";
$png = $scanme->render('https://github.com/crazy-goat/ScanMePHP', 'qrcode', 'png');
printf("   %d bytes of PNG\n\n", \strlen($png));

echo "3. A retail barcode — a different symbology, the same call\n";
$svg = $scanme->render('5901234123457', 'ean13', 'svg');
printf("   %d bytes of SVG\n\n", \strlen($svg));

// Strings work everywhere, but the enums exist so a typo is a compile-time
// concern rather than an exception at render time. Both forms are accepted by
// every method that takes a symbology or a format.
echo "4. Enums instead of strings\n";
$svg = $scanme->render('5901234123457', Symbology::Ean13, Format::Svg);
printf("   %d bytes of SVG\n\n", \strlen($svg));

echo "5. Straight to the terminal\n";
echo $scanme->render(
    'https://scanmephp.dev',
    Symbology::QrCode,
    Format::AsciiHalfBlocks,
    new AsciiOptions(sideMargin: 2)
);

echo "\nDone.\n";
