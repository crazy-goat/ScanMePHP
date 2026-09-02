<?php

declare(strict_types=1);

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Exception\RenderException;
use CrazyGoat\ScanMePHP\Generator\Qr\QrGenerator;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Renderer\BitmapFont;
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

    public function testLabelIsDrawnFromTheBuiltInFont(): void
    {
        $symbol = $this->symbol();
        $bare = $this->render(new PngOptions(moduleSize: 2));
        // Short enough to fit under the symbol; a wider one widens the canvas,
        // which the next test covers.
        $labelled = $this->render(new PngOptions(moduleSize: 2, label: '42'));

        $bareHeader = unpack('Nwidth/Nheight', substr($bare, 16, 8));
        $labelledHeader = unpack('Nwidth/Nheight', substr($labelled, 16, 8));

        $this->assertGreaterThan(
            BitmapFont::measure('42'),
            $symbol->getWidth() + 8,
            'the label must be narrower than the symbol for this case'
        );
        $this->assertSame($bareHeader['width'], $labelledHeader['width'], 'so the width is unchanged');
        $this->assertSame(
            $bareHeader['height'] + (BitmapFont::HEIGHT + 1) * 2,
            $labelledHeader['height'],
            'one text line plus its gap'
        );
        $this->assertNotFalse(imagecreatefromstring($labelled));
        $this->assertSame($symbol->getWidth() + 8, $bareHeader['width'] / 2);
    }

    public function testALabelTheFontCannotDrawIsRefusedRatherThanLeftWithHoles(): void
    {
        // The symbol's own text is vetted by Compatibility before render() is
        // reached, but a caption comes straight from the options. The font
        // spans printable ASCII, so the realistic gap is now a UTF-8 caption:
        // report the offending bytes rather than drawing holes.
        $this->expectException(RenderException::class);
        $this->expectExceptionMessage('0xC5');

        $this->render(new PngOptions(label: 'Bilet ważny'));
    }

    public function testAnAsciiLabelIsDrawnWhateverPunctuationItCarries(): void
    {
        // Regression guard: this font first shipped with QR's 45-character
        // alphanumeric set, which refused every lowercase and most punctuation
        // — half of what Code 128 can legally encode.
        $this->assertNotFalse(imagecreatefromstring(
            $this->render(new PngOptions(label: 'order #42: {a-b_c} ~50%'))
        ));
    }

    public function testAWideLabelWidensTheCanvasRatherThanBeingClipped(): void
    {
        $narrow = (new PngRenderer())->render(
            Symbol::linear('10110010', QuietZone::none(), 20),
            new PngOptions(moduleSize: 1, label: 'A VERY LONG CAPTION INDEED')
        );
        $header = unpack('Nwidth/Nheight', substr($narrow, 16, 8));

        // Losing part of an article number would be worse than an image a few
        // modules wider than the symbol.
        $this->assertSame(BitmapFont::measure('A VERY LONG CAPTION INDEED'), $header['width']);
        $this->assertGreaterThan(8, $header['width']);
    }

    public function testTheFontCoversEveryPrintableAsciiCharacter(): void
    {
        $characters = BitmapFont::characters();

        // Code 128 encodes 0x20-0x7E, so anything short of that makes PNG
        // refuse payloads the symbology accepts.
        for ($byte = 0x20; $byte <= 0x7e; $byte++) {
            $this->assertContains(
                \chr($byte),
                $characters,
                sprintf('font must have 0x%02X, which Code 128 can encode', $byte)
            );
        }

        $this->assertTrue(BitmapFont::supports('order #42: {a-b_c} ~50%'));
        $this->assertTrue(BitmapFont::supports('5901234123457'));

        // Beyond ASCII the font is honest about what it cannot draw.
        $this->assertSame([], BitmapFont::missing('AaA'));
        $this->assertFalse(BitmapFont::supports('ważny'));
        $this->assertFalse(BitmapFont::supports("\x7f"));
    }

    public function testEveryGlyphIsTheDeclaredSizeAndOnlySpaceIsBlank(): void
    {
        foreach (BitmapFont::characters() as $character) {
            $rows = BitmapFont::rasterise($character);

            $this->assertCount(BitmapFont::HEIGHT, $rows, "glyph '$character' row count");
            foreach ($rows as $row) {
                $this->assertSame(BitmapFont::WIDTH, \strlen($row), "glyph '$character' row width");
                $this->assertMatchesRegularExpression('/^[01]+$/', $row);
            }

            $dark = substr_count(implode('', $rows), '1');
            if ($character === ' ') {
                $this->assertSame(0, $dark, 'space must be blank');
            } else {
                $this->assertGreaterThan(0, $dark, "glyph '$character' must not be blank");
            }
        }
    }

    public function testGlyphsAreDistinctSoTextStaysReadable(): void
    {
        $seen = [];
        foreach (BitmapFont::characters() as $character) {
            if ($character === ' ') {
                continue;
            }
            $bitmap = implode('/', BitmapFont::rasterise($character));
            $this->assertArrayNotHasKey(
                $bitmap,
                $seen,
                sprintf("'%s' and '%s' render identically", $character, $seen[$bitmap] ?? '')
            );
            $seen[$bitmap] = $character;
        }
    }

    public function testMeasuredWidthMatchesTheRasterisedWidth(): void
    {
        foreach (['5901234123457', 'A', 'AB', 'ABC-123 4/5', ''] as $text) {
            $expected = BitmapFont::measure($text);
            $rows = $text === '' ? [''] : BitmapFont::rasterise($text);

            $this->assertSame($expected, \strlen($rows[0]), "width of '$text'");
        }
    }

    public function testRasterisingAnUnsupportedCharacterFailsLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no glyph for');

        BitmapFont::rasterise('ż');
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
