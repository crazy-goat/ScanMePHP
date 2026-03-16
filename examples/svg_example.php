<?php

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\QRCode;
use CrazyGoat\ScanMePHP\QRCodeConfig;
use CrazyGoat\ScanMePHP\Renderer\SvgRenderer;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\ModuleStyle;

echo "=== ScanMePHP - SVG QR Code Example ===\n\n";

echo "1. Basic SVG:\n";
$config = new QRCodeConfig(engine: new SvgRenderer());
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo "SVG output length: " . strlen($qr->render()) . " bytes\n\n";

echo "2. Save SVG to file:\n";
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode.svg');
echo "Saved to examples/generated-assets/qrcode.svg\n\n";

echo "3. SVG with label:\n";
$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    label: 'Scan Me!'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_with_label.svg');
echo "Saved to examples/generated-assets/qrcode_with_label.svg\n\n";

echo "4. SVG with rounded modules:\n";
$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    moduleStyle: ModuleStyle::Rounded,
    label: 'Rounded Style'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_rounded.svg');
echo "Saved to examples/generated-assets/qrcode_rounded.svg\n\n";

echo "5. SVG with dot modules:\n";
$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    moduleStyle: ModuleStyle::Dot,
    label: 'Dot Style'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_dot.svg');
echo "Saved to examples/generated-assets/qrcode_dot.svg\n\n";

echo "6. Dark mode (inverted):\n";
$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    invert: true,
    foregroundColor: '#FFFFFF',
    backgroundColor: '#000000',
    label: 'Dark Mode'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_dark.svg');
echo "Saved to examples/generated-assets/qrcode_dark.svg\n\n";

echo "7. High error correction level:\n";
$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    errorCorrectionLevel: ErrorCorrectionLevel::High,
    label: 'High ECC'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile(__DIR__ . '/generated-assets/qrcode_high_ecc.svg');
echo "Saved to examples/generated-assets/qrcode_high_ecc.svg\n\n";

echo "8. Data URI:\n";
$config = new QRCodeConfig(engine: new SvgRenderer());
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$dataUri = $qr->getDataUri();
echo "Data URI (first 100 chars): " . substr($dataUri, 0, 100) . "...\n\n";

echo "9. Base64:\n";
$base64 = $qr->toBase64();
echo "Base64 length: " . strlen($base64) . " bytes\n\n";

echo "10. Get minimum version:\n";
$minVersion = QRCode::getMinimumVersion(
    'https://github.com/crazy-goat/ScanMePHP/blob/main/README.md',
    ErrorCorrectionLevel::Medium
);
echo "Minimum version required: {$minVersion}\n\n";

echo "=== Done! ===\n";
