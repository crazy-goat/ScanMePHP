<?php

declare(strict_types=1);

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Exception\RenderException;
use CrazyGoat\ScanMePHP\Generator\Qr\QrGenerator;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Renderer\PngRenderer;
use CrazyGoat\ScanMePHP\Symbol;
use PHPUnit\Framework\TestCase;

/**
 * The PNG container itself. Pixel correctness is covered by RendererTest,
 * which samples every module against the symbol; here the concern is that we
 * emit a structurally valid file, since this encoder is hand-rolled rather
 * than delegated to GD.
 */
class PngRendererTest extends TestCase
{
    private const URL = 'https://example.com';

    private function symbol(?QrOptions $options = null): Symbol
    {
        return (new QrGenerator())->generate(self::URL, $options ?? new QrOptions());
    }

    private function render(?PngOptions $options = null): string
    {
        return (new PngRenderer())->render($this->symbol(), $options ?? new PngOptions());
    }

    public function testStartsWithThePngSignature(): void
    {
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $this->render());
    }

    public function testCarriesTheRequiredChunksInOrder(): void
    {
        $png = $this->render();

        $ihdr = strpos($png, 'IHDR');
        $idat = strpos($png, 'IDAT');
        $iend = strpos($png, 'IEND');

        $this->assertNotFalse($ihdr);
        $this->assertNotFalse($idat);
        $this->assertNotFalse($iend);
        $this->assertLessThan($idat, $ihdr, 'IHDR must precede IDAT');
        $this->assertLessThan($iend, $idat, 'IDAT must precede IEND');
        $this->assertSame(\strlen($png) - 12, $iend - 4, 'IEND must be the final chunk');
    }

    public function testIhdrDeclaresOneBitGrayscale(): void
    {
        $png = $this->render();
        $header = unpack('Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace', substr($png, 16, 13));

        $this->assertSame(1, $header['bitDepth']);
        $this->assertSame(0, $header['colorType'], 'colour type 0 is grayscale');
        $this->assertSame(0, $header['compression']);
        $this->assertSame(0, $header['filter']);
        $this->assertSame(0, $header['interlace']);
    }

    public function testEveryChunkCrcIsCorrect(): void
    {
        $png = $this->render();
        $offset = 8;
        $seen = [];

        while ($offset < \strlen($png)) {
            $length = (int) unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $body = substr($png, $offset + 8, $length);
            $crc = (int) unpack('N', substr($png, $offset + 8 + $length, 4))[1];

            $this->assertSame(crc32($type . $body), $crc, "CRC of chunk $type");
            $seen[] = $type;
            $offset += 12 + $length;
        }

        $this->assertSame(['IHDR', 'IDAT', 'IEND'], $seen);
        $this->assertSame(\strlen($png), $offset, 'no trailing bytes');
    }

    public function testGdDecodesItAtTheDeclaredSize(): void
    {
        $symbol = $this->symbol();
        $png = $this->render(new PngOptions(moduleSize: 4, quietZone: 3));

        $image = imagecreatefromstring($png);
        $this->assertNotFalse($image);
        $this->assertSame(($symbol->getWidth() + 6) * 4, imagesx($image));
        $this->assertSame(imagesx($image), imagesy($image));
    }

    public function testModuleSizeAndQuietZoneScaleTheImage(): void
    {
        $side = $this->symbol()->getWidth();

        foreach ([[1, 0], [3, 2], [10, 4], [7, 11]] as [$moduleSize, $quietZone]) {
            $png = $this->render(new PngOptions(moduleSize: $moduleSize, quietZone: $quietZone));
            $header = unpack('Nwidth/Nheight', substr($png, 16, 8));

            $this->assertSame(($side + 2 * $quietZone) * $moduleSize, $header['width']);
            $this->assertSame($header['width'], $header['height']);
        }
    }

    public function testLabelIsRefusedRatherThanDroppedSilently(): void
    {
        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('requires a font engine');

        $this->render(new PngOptions(label: 'Ticket 42'));
    }

    public function testAbsentLabelIsFine(): void
    {
        foreach ([null, ''] as $label) {
            $this->assertStringStartsWith("\x89PNG", $this->render(new PngOptions(label: $label)));
        }
    }

    public function testEveryErrorCorrectionLevelProducesADecodableImage(): void
    {
        $previous = 0;

        foreach (ErrorCorrectionLevel::cases() as $level) {
            $symbol = $this->symbol(new QrOptions($level));
            $png = (new PngRenderer())->render($symbol, new PngOptions(moduleSize: 2));

            $this->assertNotFalse(imagecreatefromstring($png));
            $this->assertGreaterThanOrEqual($previous, $symbol->getWidth(), 'more recovery data never shrinks the symbol');
            $previous = $symbol->getWidth();
        }
    }

    public function testUpFilterKeepsRepeatedRowsCheap(): void
    {
        // Scanlines repeat moduleSize times, and the "Up" filter stores every
        // repeat as zeros, so scaling up must cost far less than linearly.
        $small = \strlen($this->render(new PngOptions(moduleSize: 2)));
        $large = \strlen($this->render(new PngOptions(moduleSize: 16)));

        $this->assertLessThan($small * 4, $large, '8× the pixels must not cost 4× the bytes');
    }
}
