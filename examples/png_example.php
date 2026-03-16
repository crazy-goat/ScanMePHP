<?php

require_once __DIR__ . '/../vendor/autoload.php';

use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\PngRenderer;
use ScanMePHP\ErrorCorrectionLevel;

echo "=== ScanMePHP - PNG QR Code Example ===\n\n";

echo "1. Basic PNG:\n";
$config = new QRCodeConfig(engine: new PngRenderer());
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo "PNG output length: " . strlen($qr->render()) . " bytes\n\n";

echo "2. Save PNG to file:\n";
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode.png');
echo "Saved to examples/generated-assets/qrcode.png\n\n";

echo "3. Custom module size (5px):\n";
$config = new QRCodeConfig(engine: new PngRenderer(moduleSize: 5));
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_small.png');
echo "Saved to examples/generated-assets/qrcode_small.png\n\n";

echo "4. Large module size (20px):\n";
$config = new QRCodeConfig(engine: new PngRenderer(moduleSize: 20));
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_large.png');
echo "Saved to examples/generated-assets/qrcode_large.png\n\n";

echo "5. High error correction:\n";
$config = new QRCodeConfig(
    engine: new PngRenderer(),
    errorCorrectionLevel: ErrorCorrectionLevel::High,
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_high_ecc.png');
echo "Saved to examples/generated-assets/qrcode_high_ecc.png\n\n";

echo "6. Data URI:\n";
$config = new QRCodeConfig(engine: new PngRenderer());
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$dataUri = $qr->getDataUri();
echo "Data URI (first 100 chars): " . substr($dataUri, 0, 100) . "...\n\n";

echo "7. Base64:\n";
$base64 = $qr->toBase64();
echo "Base64 length: " . strlen($base64) . " bytes\n\n";

echo "=== Done! ===\n";
