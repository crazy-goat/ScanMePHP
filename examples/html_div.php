<?php

require_once __DIR__ . '/../vendor/autoload.php';

use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\HtmlDivRenderer;

echo "=== ScanMePHP - HTML Div Renderer ===\n\n";

echo "1. Default:\n";
$config = new QRCodeConfig(
    engine: new HtmlDivRenderer(),
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_div.html');
echo "Saved to examples/generated-assets/qrcode_div.html\n\n";

echo "2. With label:\n";
$config = new QRCodeConfig(
    engine: new HtmlDivRenderer(),
    label: 'ScanMePHP'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_div_label.html');
echo "Saved to examples/generated-assets/qrcode_div_label.html\n\n";

echo "3. Inverted:\n";
$config = new QRCodeConfig(
    engine: new HtmlDivRenderer(),
    label: 'Inverted',
    invert: true,
    foregroundColor: '#FFFFFF',
    backgroundColor: '#000000',
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_div_inverted.html');
echo "Saved to examples/generated-assets/qrcode_div_inverted.html\n\n";

echo "4. Full HTML page:\n";
$config = new QRCodeConfig(
    engine: new HtmlDivRenderer(fullHtml: true),
    label: 'ScanMePHP'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_div_full.html');
echo "Saved to examples/generated-assets/qrcode_div_full.html\n\n";

echo "=== Done! ===\n";
