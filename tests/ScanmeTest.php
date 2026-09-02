<?php

declare(strict_types=1);

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Exception\IncompatibleRendererException;
use CrazyGoat\ScanMePHP\Exception\UnknownGeneratorException;
use CrazyGoat\ScanMePHP\Exception\UnknownRendererException;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Exception\UnsupportedOptionsException;
use CrazyGoat\ScanMePHP\Format;
use CrazyGoat\ScanMePHP\Generator\Qr\QrGenerator;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\ModuleStyle;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Renderer\Options\AsciiOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\HtmlOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\SvgOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\TestCase;

/**
 * The facade: name resolution, option routing, and the refusals that keep a
 * caller from getting a symbol that looks right and does not work.
 */
class ScanmeTest extends TestCase
{
    private const URL = 'https://example.com';

    private Scanme $scanme;

    protected function setUp(): void
    {
        $this->scanme = Scanme::create();
    }

    /** @return iterable<string, array{Format, string}> */
    public static function formatProvider(): iterable
    {
        yield 'svg' => [Format::Svg, 'image/svg+xml'];
        yield 'png' => [Format::Png, 'image/png'];
        yield 'html div' => [Format::HtmlDiv, 'text/html'];
        yield 'html table' => [Format::HtmlTable, 'text/html'];
        yield 'ascii blocks' => [Format::AsciiBlocks, 'text/plain'];
        yield 'ascii half blocks' => [Format::AsciiHalfBlocks, 'text/plain'];
        yield 'ascii dots' => [Format::AsciiDots, 'text/plain'];
    }

    /** @dataProvider formatProvider */
    public function testEveryBuiltInFormatRendersQr(Format $format, string $contentType): void
    {
        $output = $this->scanme->render(self::URL, Symbology::QrCode, $format);

        $this->assertNotSame('', $output);
        $this->assertSame($contentType, $this->scanme->getContentType($format));
        $this->assertTrue($this->scanme->supports(Symbology::QrCode, $format));
    }

    /** @dataProvider formatProvider */
    public function testEnumAndStringNameTheSameThing(Format $format, string $contentType): void
    {
        $this->assertSame($contentType, $this->scanme->getContentType($format->value));
        $this->assertSame(
            $this->scanme->render(self::URL, Symbology::QrCode, $format),
            $this->scanme->render(self::URL, 'qrcode', $format->value)
        );
    }

    public function testGeneratorAliasAndCaseAreResolved(): void
    {
        $expected = $this->scanme->render(self::URL, Symbology::QrCode, Format::Svg);

        foreach (['qr', 'QRCode', 'QR'] as $name) {
            $this->assertSame($expected, $this->scanme->render(self::URL, $name, 'SVG'), $name);
        }
    }

    public function testErrorCorrectionChangesTheSymbolButQuietZoneDoesNot(): void
    {
        $low = $this->scanme->generate(self::URL, Symbology::QrCode, new QrOptions(ErrorCorrectionLevel::Low));
        $high = $this->scanme->generate(self::URL, Symbology::QrCode, new QrOptions(ErrorCorrectionLevel::High));

        // More recovery data needs more capacity, so the symbol grows.
        $this->assertGreaterThan(
            (int) $low->getMetadataValue('version'),
            (int) $high->getMetadataValue('version')
        );
        $this->assertGreaterThan($low->getWidth(), $high->getWidth());

        // The quiet zone is presentation, so it cannot move the modules.
        $wide = $this->scanme->render(self::URL, Symbology::QrCode, Format::Svg, new SvgOptions(quietZone: 12));
        $narrow = $this->scanme->render(self::URL, Symbology::QrCode, Format::Svg, new SvgOptions(quietZone: 0));
        $this->assertNotSame($wide, $narrow);
        $this->assertSame(
            substr_count($wide, 'M'),
            substr_count($narrow, 'M'),
            'quiet zone must not add or remove modules'
        );
    }

    public function testGeneratorAndRendererOptionsAreRoutedIndependently(): void
    {
        $both = $this->scanme->render(
            self::URL,
            Symbology::QrCode,
            Format::Png,
            new QrOptions(ErrorCorrectionLevel::High),
            new PngOptions(moduleSize: 3, quietZone: 6),
        );

        $ihdr = unpack('Nwidth/Nheight', substr($both, 16, 8));
        $symbol = $this->scanme->generate(self::URL, Symbology::QrCode, new QrOptions(ErrorCorrectionLevel::High));

        $this->assertSame(($symbol->getWidth() + 12) * 3, $ihdr['width']);
        $this->assertSame($ihdr['width'], $ihdr['height']);
    }

    public function testOptionOrderDoesNotMatter(): void
    {
        $generatorFirst = $this->scanme->render(
            self::URL,
            Symbology::QrCode,
            Format::Svg,
            new QrOptions(ErrorCorrectionLevel::Quartile),
            new SvgOptions(moduleSize: 7),
        );
        $rendererFirst = $this->scanme->render(
            self::URL,
            Symbology::QrCode,
            Format::Svg,
            new SvgOptions(moduleSize: 7),
            new QrOptions(ErrorCorrectionLevel::Quartile),
        );

        $this->assertSame($generatorFirst, $rendererFirst);
    }

    public function testTwoBagsForTheSameSlotAreRejected(): void
    {
        $this->expectException(UnsupportedOptionsException::class);
        $this->expectExceptionMessage('pass at most one');

        $this->scanme->render(
            self::URL,
            Symbology::QrCode,
            Format::Svg,
            new SvgOptions(moduleSize: 4),
            new SvgOptions(moduleSize: 8),
        );
    }

    public function testOptionsMeantForAnotherRendererAreRejectedNotIgnored(): void
    {
        $this->expectException(UnsupportedOptionsException::class);
        $this->expectExceptionMessage('svg renderer expects options of type');

        $this->scanme->render(self::URL, Symbology::QrCode, Format::Svg, new PngOptions(moduleSize: 3));
    }

    public function testUnclaimedOptionsAreRejected(): void
    {
        $orphan = new class () implements CrazyGoat\ScanMePHP\Options\OptionsInterface {};

        $this->expectException(UnsupportedOptionsException::class);
        $this->expectExceptionMessage('nothing would consume them');

        $this->scanme->render(self::URL, Symbology::QrCode, Format::Svg, $orphan);
    }

    public function testUnknownGeneratorListsWhatIsAvailable(): void
    {
        $this->expectException(UnknownGeneratorException::class);
        $this->expectExceptionMessage('Available: qrcode');

        $this->scanme->render(self::URL, 'aztec', Format::Svg);
    }

    public function testUnknownFormatListsWhatIsAvailable(): void
    {
        $this->expectException(UnknownRendererException::class);
        $this->expectExceptionMessage('Available: ascii-blocks');

        $this->scanme->render(self::URL, Symbology::QrCode, 'pdf');
    }

    public function testEmptyDataIsRejected(): void
    {
        $this->expectException(UnsupportedDataException::class);

        $this->scanme->render('', Symbology::QrCode, Format::Svg);
    }

    public function testDataBeyondCapacityIsRejected(): void
    {
        $this->expectException(UnsupportedDataException::class);
        $this->expectExceptionMessage('up to 2953 bytes');

        $this->scanme->render(str_repeat('x', 3000), Symbology::QrCode, Format::Svg);
    }

    public function testPinnedVersionIsHonouredByAPurePhpBackend(): void
    {
        $symbol = $this->scanme->generate(self::URL, Symbology::QrCode, new QrOptions(version: 15));

        $this->assertSame(15, $symbol->getMetadataValue('version'));
        $this->assertSame(17 + 15 * 4, $symbol->getWidth());
    }

    public function testHumanReadableTextIsRefusedByTheFontlessPngRenderer(): void
    {
        // The shape EAN-13 and Code128 will produce: a linear symbol carrying
        // the digits a renderer is required to print beneath the bars.
        $linear = Symbol::linear('101001110010101', new QuietZone(left: 11, right: 7), 60, '5901234123457');

        $this->assertTrue($this->scanme->getRegistry()->getRenderer(Format::Svg)->getCapabilities()->text);
        $this->assertFalse($this->scanme->getRegistry()->getRenderer(Format::Png)->getCapabilities()->text);

        $this->assertStringContainsString(
            '5901234123457',
            $this->scanme->renderSymbol($linear, Format::Svg)
        );

        $this->expectException(IncompatibleRendererException::class);
        $this->expectExceptionMessage('human-readable interpretation');
        $this->scanme->renderSymbol($linear, Format::Png);
    }

    public function testHexagonalModulesAreRefusedByEverySquareRenderer(): void
    {
        // The shape MaxiCode will produce.
        $hexagonal = new Symbol(
            width: 4,
            height: 4,
            modules: '1010010110100101',
            dimension: Dimension::Matrix,
            moduleShape: ModuleShape::Hexagon,
            quietZone: QuietZone::uniform(1),
        );

        foreach ($this->scanme->getRegistry()->renderers() as $renderer) {
            $this->assertFalse(
                $renderer->getCapabilities()->supportsShape(ModuleShape::Hexagon),
                $renderer->getFormat()
            );
        }

        $this->expectException(IncompatibleRendererException::class);
        $this->expectExceptionMessage('cannot draw hexagon modules');
        $this->scanme->renderSymbol($hexagonal, Format::Svg);
    }

    public function testLinearBarHeightIsScaledNotFlattened(): void
    {
        // Four-state postal codes encode in bar height, so an override has to
        // stretch the ascender/tracker/descender ratio rather than level it.
        $fourState = new Symbol(
            width: 3,
            height: 3,
            modules: '101111101',
            dimension: Dimension::Linear,
            quietZone: QuietZone::none(),
            rowHeights: [5, 10, 5],
        );

        $rendered = $this->scanme->renderSymbol($fourState, Format::AsciiBlocks, new AsciiOptions(barHeight: 40));
        $lines = explode("\n", $rendered);

        $this->assertCount(40, $lines);
        $this->assertSame(array_fill(0, 10, '█ █'), array_slice($lines, 0, 10));
        $this->assertSame(array_fill(0, 20, '███'), array_slice($lines, 10, 20));
    }

    public function testQuietZoneDefaultsToWhatTheSymbologyRequires(): void
    {
        $symbol = $this->scanme->generate(self::URL, Symbology::QrCode);
        $this->assertSame(4, $symbol->getQuietZone()->left, 'ISO/IEC 18004 requires 4 modules');

        $ascii = $this->scanme->render(self::URL, Symbology::QrCode, Format::AsciiBlocks);
        $lines = explode("\n", $ascii);

        $this->assertSame(4, $this->leadingBlankLines($lines));
        $this->assertSame(4, $this->leadingBlankLines(array_reverse($lines)));
        $this->assertSame($symbol->getWidth() + 8, mb_strlen($lines[0]));
    }

    public function testDataUriCarriesTheFormatsMimeType(): void
    {
        $this->assertStringStartsWith(
            'data:image/svg+xml;base64,',
            $this->scanme->dataUri(self::URL, Symbology::QrCode, Format::Svg)
        );
        $this->assertStringStartsWith(
            'data:text/plain;base64,',
            $this->scanme->dataUri(self::URL, Symbology::QrCode, Format::AsciiBlocks)
        );
    }

    public function testToFileWritesExactlyWhatRenderReturns(): void
    {
        $path = sys_get_temp_dir() . '/scanme_' . uniqid() . '.png';

        try {
            $this->scanme->toFile($path, self::URL, Symbology::QrCode, Format::Png);

            $this->assertFileExists($path);
            $this->assertSame(
                $this->scanme->render(self::URL, Symbology::QrCode, Format::Png),
                (string) file_get_contents($path)
            );
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testSvgModuleStylesAllRender(): void
    {
        foreach (ModuleStyle::cases() as $style) {
            $svg = $this->scanme->render(
                self::URL,
                Symbology::QrCode,
                Format::Svg,
                new SvgOptions(moduleStyle: $style)
            );
            $this->assertStringContainsString('<svg', $svg, $style->value);
        }
    }

    public function testInvertSwapsForegroundAndBackground(): void
    {
        $normal = $this->scanme->render(self::URL, Symbology::QrCode, Format::Svg);
        $inverted = $this->scanme->render(self::URL, Symbology::QrCode, Format::Svg, new SvgOptions(invert: true));

        $this->assertNotSame($normal, $inverted);
        $this->assertStringContainsString('<rect width="330" height="330" fill="#FFFFFF"/>', $normal);
        $this->assertStringContainsString('<rect width="330" height="330" fill="#000000"/>', $inverted);
    }

    public function testFullHtmlDocumentIsOptIn(): void
    {
        $embedded = $this->scanme->render(self::URL, Symbology::QrCode, Format::HtmlDiv);
        $document = $this->scanme->render(
            self::URL,
            Symbology::QrCode,
            Format::HtmlDiv,
            new HtmlOptions(fullDocument: true, title: 'Ticket <1>')
        );

        $this->assertStringNotContainsString('<!DOCTYPE', $embedded);
        $this->assertStringContainsString('<!DOCTYPE html>', $document);
        $this->assertStringContainsString('<title>Ticket &lt;1&gt;</title>', $document);
    }

    public function testRegistryDescribesWhatIsInstalled(): void
    {
        $registry = $this->scanme->getRegistry();
        $described = $registry->describeGenerators();

        $this->assertArrayHasKey('qrcode', $described);
        $this->assertSame('QR Code', $described['qrcode']->title);
        $this->assertSame(Dimension::Matrix, $described['qrcode']->dimension);
        $this->assertSame(['L', 'M', 'Q', 'H'], $described['qrcode']->errorCorrectionLevels);
        $this->assertTrue($described['qrcode']->hasErrorCorrection());
        $this->assertSame(['qrcode', 'qr'], $described['qrcode']->allNames());

        $this->assertSame(['qrcode'], $registry->generatorsFor(self::URL));
        $this->assertSame([], $registry->generatorsFor(str_repeat('x', 3000)));
    }

    public function testACustomGeneratorCanReplaceABuiltInOne(): void
    {
        $registry = $this->scanme->getRegistry();
        $this->assertSame(
            'qrcode',
            $registry->getGenerator(Symbology::QrCode)->getCapabilities()->name
        );

        // Registering under an existing name is the documented way to swap an
        // implementation, so it must replace rather than be ignored.
        $replacement = new QrGenerator();
        $registry->addGenerator($replacement);

        $this->assertSame($replacement, $registry->getGenerator('qr'));
    }

    /** @param list<string> $lines */
    private function leadingBlankLines(array $lines): int
    {
        $count = 0;
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                break;
            }
            $count++;
        }

        return $count;
    }
}
