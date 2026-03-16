# PNG Renderer Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a native PNG renderer that generates valid 1-bit monochrome PNG files in pure PHP, with no extensions required.

**Architecture:** Two-class design — `PngRenderer` (implements `RendererInterface`, converts QR matrix to pixel bitmap) and `PngEncoder` (pure PNG binary format encoder, builds chunks from raw bitmap). Label option throws `RenderException` since bitmap text rendering is out of scope.

**Tech Stack:** Pure PHP 8.1+, `gzcompress()` (zlib, bundled), `crc32()` (built-in), `pack()` for binary encoding.

---

### Task 1: Create PngEncoder — PNG binary format encoder

**Files:**
- Create: `src/Renderer/PngEncoder.php`

**Step 1: Create PngEncoder with full PNG encoding logic**

```php
<?php

declare(strict_types=1);

namespace ScanMePHP\Renderer;

/**
 * Minimal PNG encoder for 1-bit monochrome images.
 *
 * Builds a valid PNG file from a boolean pixel grid:
 *   PNG Signature (8B) + IHDR (25B) + IDAT (variable) + IEND (12B)
 *
 * Uses color type 0 (grayscale), bit depth 1.
 * Each scanline: filter byte (0x00 = None) + packed pixel bits (MSB first).
 * In 1-bit grayscale: 0 = black, 1 = white.
 */
final class PngEncoder
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Encode a boolean pixel grid into a PNG binary string.
     *
     * @param bool[][] $bitmap 2D array [y][x], true = dark (black), false = light (white)
     * @param int $width Image width in pixels
     * @param int $height Image height in pixels
     * @return string Raw PNG binary data
     */
    public function encode(array $bitmap, int $width, int $height): string
    {
        return self::PNG_SIGNATURE
            . $this->buildIhdrChunk($width, $height)
            . $this->buildIdatChunk($bitmap, $width, $height)
            . $this->buildIendChunk();
    }

    /**
     * IHDR chunk: 13 bytes of image metadata.
     *
     * Layout: width(4B) + height(4B) + bitDepth(1B) + colorType(1B)
     *       + compression(1B) + filter(1B) + interlace(1B)
     */
    private function buildIhdrChunk(int $width, int $height): string
    {
        $data = pack('NN', $width, $height)  // width, height as 4-byte big-endian unsigned
            . "\x01"   // bit depth: 1
            . "\x00"   // color type: 0 (grayscale)
            . "\x00"   // compression method: 0 (deflate)
            . "\x00"   // filter method: 0 (adaptive, only option)
            . "\x00";  // interlace method: 0 (no interlace)

        return $this->buildChunk('IHDR', $data);
    }

    /**
     * IDAT chunk: compressed pixel data.
     *
     * Each scanline is prefixed with a filter byte (0x00 = None),
     * followed by pixel bits packed 8 per byte, MSB first.
     * In 1-bit grayscale: bit 0 = black, bit 1 = white.
     * The last byte of each scanline is padded with 0 bits if width % 8 != 0.
     */
    private function buildIdatChunk(array $bitmap, int $width, int $height): string
    {
        $bytesPerScanline = (int) ceil($width / 8);
        $rawData = '';

        for ($y = 0; $y < $height; $y++) {
            $rawData .= "\x00"; // filter type: None

            $row = $bitmap[$y];
            for ($byteIndex = 0; $byteIndex < $bytesPerScanline; $byteIndex++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $byteIndex * 8 + $bit;
                    if ($x < $width) {
                        // true = dark = black = bit value 0
                        // false = light = white = bit value 1
                        if (!$row[$x]) {
                            $byte |= (0x80 >> $bit);
                        }
                    }
                    // padding bits stay 0 (black), but for consistency
                    // with white background, set padding to 1 (white)
                    elseif ($x >= $width) {
                        $byte |= (0x80 >> $bit);
                    }
                }
                $rawData .= chr($byte);
            }
        }

        $compressed = gzcompress($rawData);

        return $this->buildChunk('IDAT', $compressed);
    }

    /**
     * IEND chunk: marks end of PNG. Empty data.
     */
    private function buildIendChunk(): string
    {
        return $this->buildChunk('IEND', '');
    }

    /**
     * Build a PNG chunk: length(4B) + type(4B) + data + CRC(4B).
     *
     * CRC is calculated over type + data (not length).
     */
    private function buildChunk(string $type, string $data): string
    {
        $length = pack('N', strlen($data));
        $crc = pack('N', crc32($type . $data));

        return $length . $type . $data . $crc;
    }
}
```

**Step 2: Verify file is syntactically correct**

Run: `php -l src/Renderer/PngEncoder.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add src/Renderer/PngEncoder.php
git commit -m "feat(png): add PngEncoder — pure PHP 1-bit PNG binary encoder"
```

---

### Task 2: Create PngRenderer — RendererInterface implementation

**Files:**
- Create: `src/Renderer/PngRenderer.php`

**Step 1: Create PngRenderer**

```php
<?php

declare(strict_types=1);

namespace ScanMePHP\Renderer;

use ScanMePHP\Exception\RenderException;
use ScanMePHP\Matrix;
use ScanMePHP\RenderOptions;
use ScanMePHP\RendererInterface;

class PngRenderer implements RendererInterface
{
    private readonly PngEncoder $encoder;

    public function __construct(
        private readonly int $moduleSize = 10,
    ) {
        $this->encoder = new PngEncoder();
    }

    public function getContentType(): string
    {
        return 'image/png';
    }

    public function render(Matrix $matrix, RenderOptions $options): string
    {
        if ($options->label !== null && $options->label !== '') {
            throw RenderException::unsupportedOperation(
                'PNG renderer does not support labels — text rendering requires a font engine'
            );
        }

        $size = $matrix->getSize();
        $margin = $options->margin;
        $totalModules = $size + (2 * $margin);
        $totalPixels = $totalModules * $this->moduleSize;

        $bitmap = $this->buildBitmap($matrix, $size, $margin, $totalModules);

        return $this->encoder->encode($bitmap, $totalPixels, $totalPixels);
    }

    /**
     * Build a 2D boolean pixel grid from the QR matrix.
     *
     * Each QR module is expanded to moduleSize x moduleSize pixels.
     * true = dark (black module), false = light (white / margin).
     *
     * @return bool[][] Pixel grid [y][x]
     */
    private function buildBitmap(Matrix $matrix, int $size, int $margin, int $totalModules): array
    {
        $mod = $this->moduleSize;
        $totalPixels = $totalModules * $mod;
        $bitmap = [];

        for ($moduleY = 0; $moduleY < $totalModules; $moduleY++) {
            $dataY = $moduleY - $margin;

            // Build one row of module values
            $moduleRow = [];
            for ($moduleX = 0; $moduleX < $totalModules; $moduleX++) {
                $dataX = $moduleX - $margin;
                $isDark = ($dataX >= 0 && $dataX < $size && $dataY >= 0 && $dataY < $size)
                    ? $matrix->get($dataX, $dataY)
                    : false;
                $moduleRow[] = $isDark;
            }

            // Expand module row into moduleSize pixel rows
            $pixelRow = [];
            foreach ($moduleRow as $isDark) {
                for ($px = 0; $px < $mod; $px++) {
                    $pixelRow[] = $isDark;
                }
            }

            // Duplicate the pixel row moduleSize times
            for ($py = 0; $py < $mod; $py++) {
                $bitmap[] = $pixelRow;
            }
        }

        return $bitmap;
    }
}
```

**Step 2: Verify file is syntactically correct**

Run: `php -l src/Renderer/PngRenderer.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add src/Renderer/PngRenderer.php
git commit -m "feat(png): add PngRenderer implementing RendererInterface"
```

---

### Task 3: Add tests for PngRenderer

**Files:**
- Create: `tests/PngRendererTest.php`

**Step 1: Write tests**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\PngRenderer;
use ScanMePHP\Exception\RenderException;

class PngRendererTest extends TestCase
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public function testOutputStartsWithPngSignature(): void
    {
        $config = new QRCodeConfig(engine: new PngRenderer());
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertStringStartsWith(self::PNG_SIGNATURE, $output);
    }

    public function testGetContentTypeReturnsImagePng(): void
    {
        $renderer = new PngRenderer();
        $this->assertEquals('image/png', $renderer->getContentType());
    }

    public function testDataUriStartsWithPngMimeType(): void
    {
        $config = new QRCodeConfig(engine: new PngRenderer());
        $qr = new QRCode('https://example.com', $config);
        $dataUri = $qr->getDataUri();

        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
    }

    public function testOutputContainsRequiredPngChunks(): void
    {
        $config = new QRCodeConfig(engine: new PngRenderer());
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertStringContainsString('IHDR', $output);
        $this->assertStringContainsString('IDAT', $output);
        $this->assertStringContainsString('IEND', $output);
    }

    public function testOutputIsValidPngDecodableByGd(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available for validation');
        }

        $config = new QRCodeConfig(engine: new PngRenderer());
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $image = imagecreatefromstring($output);
        $this->assertNotFalse($image, 'PNG output should be decodable by GD');

        $width = imagesx($image);
        $height = imagesy($image);
        $this->assertGreaterThan(0, $width);
        $this->assertEquals($width, $height, 'QR code image should be square');

        imagedestroy($image);
    }

    public function testCustomModuleSize(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available for validation');
        }

        $config5 = new QRCodeConfig(engine: new PngRenderer(moduleSize: 5), margin: 0);
        $qr5 = new QRCode('https://example.com', $config5);
        $output5 = $qr5->render();

        $config20 = new QRCodeConfig(engine: new PngRenderer(moduleSize: 20), margin: 0);
        $qr20 = new QRCode('https://example.com', $config20);
        $output20 = $qr20->render();

        $image5 = imagecreatefromstring($output5);
        $image20 = imagecreatefromstring($output20);

        // moduleSize 20 should produce image 4x larger than moduleSize 5
        $this->assertEquals(imagesx($image5) * 4, imagesx($image20));

        imagedestroy($image5);
        imagedestroy($image20);
    }

    public function testLabelThrowsRenderException(): void
    {
        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('PNG renderer does not support labels');

        $config = new QRCodeConfig(
            engine: new PngRenderer(),
            label: 'Test Label',
        );
        $qr = new QRCode('https://example.com', $config);
        $qr->render();
    }

    public function testSaveToFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_qr_png_' . uniqid() . '.png';

        try {
            $config = new QRCodeConfig(engine: new PngRenderer());
            $qr = new QRCode('https://example.com', $config);
            $qr->saveToFile($tempFile);

            $this->assertFileExists($tempFile);
            $content = file_get_contents($tempFile);
            $this->assertStringStartsWith(self::PNG_SIGNATURE, $content);
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testMarginAffectsImageSize(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available for validation');
        }

        $config0 = new QRCodeConfig(engine: new PngRenderer(moduleSize: 1), margin: 0);
        $qr0 = new QRCode('https://example.com', $config0);

        $config4 = new QRCodeConfig(engine: new PngRenderer(moduleSize: 1), margin: 4);
        $qr4 = new QRCode('https://example.com', $config4);

        $image0 = imagecreatefromstring($qr0->render());
        $image4 = imagecreatefromstring($qr4->render());

        // margin=4 adds 8 modules (4 each side), so 8 pixels more at moduleSize=1
        $this->assertEquals(imagesx($image0) + 8, imagesx($image4));

        imagedestroy($image0);
        imagedestroy($image4);
    }
}
```

**Step 2: Run tests**

Run: `php vendor/bin/phpunit tests/PngRendererTest.php -v`
Expected: All tests PASS (GD-dependent tests may be skipped)

**Step 3: Run full test suite to ensure no regressions**

Run: `php vendor/bin/phpunit -v`
Expected: All existing tests still pass

**Step 4: Commit**

```bash
git add tests/PngRendererTest.php
git commit -m "test(png): add PngRenderer test suite"
```

---

### Task 4: Create example file

**Files:**
- Create: `examples/png_example.php`

**Step 1: Write example**

```php
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
```

**Step 2: Run example**

Run: `php examples/png_example.php`
Expected: Output shows file sizes and saved paths without errors

**Step 3: Commit**

```bash
git add examples/png_example.php
git commit -m "docs(png): add PNG renderer example"
```

---

### Task 5: Update README

**Files:**
- Modify: `README.md`

**Step 1: Add PNG section after SVG section and update renderer table**

Add PNG renderer documentation section after the SVG section (after line 76).
Update the renderer reference table to include PngRenderer.
Update the renderer count from 7 to 8.

**Step 2: Commit**

```bash
git add README.md
git commit -m "docs: add PngRenderer to README"
```

---

### Task 6: Final verification

**Step 1: Run full test suite**

Run: `php vendor/bin/phpunit -v`
Expected: All tests pass

**Step 2: Run example**

Run: `php examples/png_example.php`
Expected: All PNG files generated successfully

**Step 3: Verify generated PNG files are valid**

Run: `php -r "echo imagecreatefromstring(file_get_contents('examples/generated-assets/qrcode.png')) ? 'VALID' : 'INVALID';"` (if GD available)
Or: `file examples/generated-assets/qrcode.png` (should say "PNG image data")
