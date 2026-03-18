<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use CrazyGoat\ScanMePHP\QRCode;
use CrazyGoat\ScanMePHP\QRCodeConfig;
use CrazyGoat\ScanMePHP\Renderer\PngRenderer;
use CrazyGoat\ScanMePHP\Exception\RenderException;

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

        $this->assertNotFalse($image5);
        $this->assertNotFalse($image20);

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

    public function testEmptyLabelDoesNotThrow(): void
    {
        $config = new QRCodeConfig(
            engine: new PngRenderer(),
            label: '',
        );
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertStringStartsWith(self::PNG_SIGNATURE, $output);
    }

    public function testNullLabelDoesNotThrow(): void
    {
        $config = new QRCodeConfig(
            engine: new PngRenderer(),
            label: null,
        );
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertStringStartsWith(self::PNG_SIGNATURE, $output);
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

        $this->assertNotFalse($image0);
        $this->assertNotFalse($image4);

        // margin=4 adds 8 modules (4 each side), so 8 pixels more at moduleSize=1
        $this->assertEquals(imagesx($image0) + 8, imagesx($image4));

        imagedestroy($image0);
        imagedestroy($image4);
    }

    public function testDifferentErrorCorrectionLevels(): void
    {
        foreach (\CrazyGoat\ScanMePHP\ErrorCorrectionLevel::cases() as $level) {
            $config = new QRCodeConfig(
                engine: new PngRenderer(),
                errorCorrectionLevel: $level,
            );
            $qr = new QRCode('https://example.com', $config);
            $output = $qr->render();

            $this->assertStringStartsWith(
                self::PNG_SIGNATURE,
                $output,
                "PNG should be valid for error correction level {$level->name}"
            );
        }
    }

    public function testPixelColorsAreCorrect(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available for validation');
        }

        // Use moduleSize=1, margin=0 so pixels map 1:1 to QR modules
        $config = new QRCodeConfig(engine: new PngRenderer(moduleSize: 1), margin: 0);
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $image = imagecreatefromstring($output);
        $this->assertNotFalse($image);

        $matrix = $qr->getMatrix();
        $size = $matrix->getSize();

        // GD decodes 1-bit grayscale PNG as a palette image (index 0/1),
        // so we must use imagecolorsforindex() to get actual RGB values.
        // Verify a sample of pixels match the QR matrix
        for ($y = 0; $y < $size; $y += 5) {
            for ($x = 0; $x < $size; $x += 5) {
                $isDark = $matrix->get($x, $y);
                $colorIndex = imagecolorat($image, $x, $y);
                $color = imagecolorsforindex($image, $colorIndex);
                $red = $color['red'];

                if ($isDark) {
                    $this->assertLessThanOrEqual(128, $red, "Pixel ($x,$y) should be dark");
                } else {
                    $this->assertGreaterThan(128, $red, "Pixel ($x,$y) should be light");
                }
            }
        }

        imagedestroy($image);
    }

    public function testInvertProducesDifferentOutputThanNormal(): void
    {
        $config = new QRCodeConfig(engine: new PngRenderer());
        $qr = new QRCode('https://example.com', $config);
        $normal = $qr->render();

        $configInverted = new QRCodeConfig(engine: new PngRenderer(), invert: true);
        $qrInverted = new QRCode('https://example.com', $configInverted);
        $inverted = $qrInverted->render();

        $this->assertNotEquals($normal, $inverted, 'Inverted PNG should differ from normal PNG');
    }

    public function testInvertSwapsPixelColors(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available for validation');
        }

        $config = new QRCodeConfig(engine: new PngRenderer(moduleSize: 1), margin: 0);
        $qr = new QRCode('https://example.com', $config);

        $configInverted = new QRCodeConfig(engine: new PngRenderer(moduleSize: 1), margin: 0, invert: true);
        $qrInverted = new QRCode('https://example.com', $configInverted);

        $image = imagecreatefromstring($qr->render());
        $imageInverted = imagecreatefromstring($qrInverted->render());

        $this->assertNotFalse($image);
        $this->assertNotFalse($imageInverted);

        $matrix = $qr->getMatrix();
        $size = $matrix->getSize();

        for ($y = 0; $y < $size; $y += 5) {
            for ($x = 0; $x < $size; $x += 5) {
                $isDark = $matrix->get($x, $y);

                $colorNormal = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $colorInverted = imagecolorsforindex($imageInverted, imagecolorat($imageInverted, $x, $y));

                $isNormalDark = $colorNormal['red'] <= 128;
                $isInvertedDark = $colorInverted['red'] <= 128;

                $this->assertNotEquals(
                    $isNormalDark,
                    $isInvertedDark,
                    "Pixel ($x,$y) should be inverted: normal=" . ($isNormalDark ? 'dark' : 'light') . ", inverted=" . ($isInvertedDark ? 'dark' : 'light')
                );
            }
        }

        imagedestroy($image);
        imagedestroy($imageInverted);
    }
}
