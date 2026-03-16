<?php

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\QRCode;
use CrazyGoat\ScanMePHP\QRCodeConfig;
use CrazyGoat\ScanMePHP\Renderer\HalfBlocksRenderer;

echo "=== ScanMePHP - Half Blocks Renderer ===\n\n";

echo "1. Default:\n";
$config = new QRCodeConfig(
    engine: new HalfBlocksRenderer(sideMargin: 4),
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo $qr->render();
echo "\n\n";

echo "2. With label:\n";
$config = new QRCodeConfig(
    engine: new HalfBlocksRenderer(sideMargin: 4),
    label: 'ScanMePHP'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo $qr->render();
echo "\n\n";

echo "3. Inverted:\n";
$config = new QRCodeConfig(
    engine: new HalfBlocksRenderer(sideMargin: 4),
    label: 'Inverted',
    invert: true,
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo $qr->render();
echo "\n\n";

echo "4. Save to file:\n";
$config = new QRCodeConfig(
    engine: new HalfBlocksRenderer(),
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_halfblocks.txt');
echo "Saved to examples/generated-assets/qrcode_halfblocks.txt\n";

echo "\n=== Done! ===\n";
