<?php

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\QRCode;
use CrazyGoat\ScanMePHP\QRCodeConfig;
use CrazyGoat\ScanMePHP\Renderer\HtmlTableRenderer;

echo "=== ScanMePHP - HTML Table Renderer ===\n\n";

echo "1. Default:\n";
$config = new QRCodeConfig(
    engine: new HtmlTableRenderer(),
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_table.html');
echo "Saved to examples/generated-assets/qrcode_table.html\n\n";

echo "2. With label:\n";
$config = new QRCodeConfig(
    engine: new HtmlTableRenderer(),
    label: 'ScanMePHP'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_table_label.html');
echo "Saved to examples/generated-assets/qrcode_table_label.html\n\n";

echo "3. Inverted:\n";
$config = new QRCodeConfig(
    engine: new HtmlTableRenderer(),
    label: 'Inverted',
    invert: true,
    foregroundColor: '#FFFFFF',
    backgroundColor: '#000000',
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_table_inverted.html');
echo "Saved to examples/generated-assets/qrcode_table_inverted.html\n\n";

echo "4. Full HTML page:\n";
$config = new QRCodeConfig(
    engine: new HtmlTableRenderer(fullHtml: true),
    label: 'ScanMePHP'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_table_full.html');
echo "Saved to examples/generated-assets/qrcode_table_full.html\n\n";

echo "=== Done! ===\n";
