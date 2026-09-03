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
use CrazyGoat\ScanMePHP\Region;
use CrazyGoat\ScanMePHP\RegionRole;
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
        // Derived from the registry rather than spelled out: this assertion
        // broke on every symbology added to the library, which taught nobody
        // anything. The list is pinned once, in
        // testTheBuiltInSymbologiesAreExactlyThese.
        $registered = array_keys($this->scanme->getRegistry()->describeGenerators());
        sort($registered);

        try {
            $this->scanme->render(self::URL, 'no-such-symbology', Format::Svg);
            $this->fail('expected an unknown generator name to be refused');
        } catch (UnknownGeneratorException $e) {
            $this->assertStringContainsString(
                'Available: ' . implode(', ', $registered),
                $e->getMessage()
            );
        }
    }

    /**
     * The one place that spells out the built-in set. A symbology is not
     * shipped until it is listed here and in DecoderRoundTripTest, so adding
     * one is a two-line change rather than a hunt through the suite.
     */
    public function testTheBuiltInSymbologiesAreExactlyThese(): void
    {
        $registered = array_keys($this->scanme->getRegistry()->describeGenerators());
        sort($registered);

        $this->assertSame(
            ['australia-post', 'aztec', 'codabar', 'code128', 'code39', 'code39ext', 'code93', 'data-matrix', 'databar-expanded', 'databar-expanded-stacked', 'databar-limited', 'databar-omni', 'ean13', 'ean2', 'ean5', 'ean8', 'gs1-128', 'gs1-data-matrix', 'gs1-qr', 'intelligent-mail', 'itf', 'itf14', 'kix', 'maxicode', 'micro-qr', 'pdf417', 'qrcode', 'rm4scc', 'rmqr', 'upc-a', 'upc-e'],
            $registered
        );
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

    public function testEveryRendererCanPrintHumanReadableText(): void
    {
        // The shape EAN-13 and Code128 produce: a linear symbol carrying the
        // digits a renderer is required to print beneath the bars.
        $linear = Symbol::linear('101001110010101', new QuietZone(left: 11, right: 7), 60, '5901234123457');

        foreach ($this->scanme->getRegistry()->renderers() as $renderer) {
            $this->assertTrue($renderer->getCapabilities()->text, $renderer->getFormat());
            $this->assertNotSame(
                '',
                $this->scanme->renderSymbol($linear, $renderer->getFormat()),
                $renderer->getFormat()
            );
        }

        $this->assertStringContainsString('5901234123457', $this->scanme->renderSymbol($linear, Format::Svg));
    }

    public function testPngReportsTheCharactersItsBuiltInFontLacks(): void
    {
        // The PNG writer has no font engine, so it ships a bitmap font with a
        // fixed repertoire: all of printable ASCII, which is exactly what
        // Code 128 encodes. Past that the limit is reported per character
        // rather than as a blanket refusal.
        $capabilities = $this->scanme->getRegistry()->getRenderer(Format::Png)->getCapabilities();
        $this->assertNotNull($capabilities->textCharacters);
        $this->assertSame([], $capabilities->unprintableCharacters('ABC-123 4/5'));
        $this->assertSame([], $capabilities->unprintableCharacters('sku-abc {~42%}'));
        $this->assertSame(
            ["\xc5", "\xbc"],
            $capabilities->unprintableCharacters('ważny ważny'),
            'reported per byte, once each'
        );

        // Renderers that delegate typography to a browser or terminal take any text.
        foreach ([Format::Svg, Format::HtmlDiv, Format::AsciiBlocks] as $format) {
            $this->assertNull(
                $this->scanme->getRegistry()->getRenderer($format)->getCapabilities()->textCharacters,
                $format->value
            );
        }

        // Lowercase used to be refused here; that was the bug the decoder
        // round-trip suite exposed, so it is now a positive assertion.
        $lowercase = Symbol::linear('101001110010101', new QuietZone(left: 11, right: 7), 60, 'sku-abc');
        $this->assertStringContainsString('sku-abc', $this->scanme->renderSymbol($lowercase, Format::Svg));
        $this->assertNotSame('', $this->scanme->renderSymbol($lowercase, Format::Png));

        $accented = Symbol::linear('101001110010101', new QuietZone(left: 11, right: 7), 60, 'ważny');

        $this->expectException(IncompatibleRendererException::class);
        $this->expectExceptionMessage('0xC5');
        $this->scanme->renderSymbol($accented, Format::Png);
    }

    public function testSuppressingTheTextSidestepsTheFontLimit(): void
    {
        $lowercase = Symbol::linear('101001110010101', new QuietZone(left: 11, right: 7), 60, 'sku-abc');

        $png = $this->scanme->renderSymbol($lowercase, Format::Png, new PngOptions(
            moduleSize: 2,
            showText: false,
        ));
        $header = unpack('Nwidth/Nheight', substr($png, 16, 8));

        $this->assertSame((15 + 18) * 2, $header['width']);
        $this->assertSame(60 * 2, $header['height'], 'no text, so no room reserved for it');
    }

    /**
     * The shape negotiation, on the one symbology that exercises it.
     *
     * MaxiCode is the only symbol here whose modules are not squares, so it is
     * the only case where a renderer can be asked for something it cannot draw.
     * Both halves matter: the two renderers that grew hexagons have to accept
     * it, and the five that draw character or table cells have to say so by
     * name rather than approximate it into something unscannable.
     */
    public function testHexagonalModulesAreDrawnOnlyByTheRenderersThatCan(): void
    {
        $drawn = [];
        $refused = [];

        foreach ($this->scanme->getRegistry()->renderers() as $renderer) {
            $format = $renderer->getFormat();
            if ($renderer->getCapabilities()->supportsShape(ModuleShape::Hexagon)) {
                $drawn[] = $format;

                continue;
            }
            $refused[] = $format;
        }

        sort($drawn);
        sort($refused);
        $this->assertSame(['png', 'svg'], $drawn);
        $this->assertSame(
            ['ascii-blocks', 'ascii-dots', 'ascii-half-blocks', 'html-div', 'html-table'],
            $refused
        );

        $this->expectException(IncompatibleRendererException::class);
        $this->expectExceptionMessage('cannot draw hexagon modules');
        $this->scanme->render('HELLO', Symbology::MaxiCode, Format::AsciiBlocks);
    }

    /**
     * The other half of the negotiation, and the one that is not about shape.
     *
     * A finder region used to be a hint — QR reports its corner patterns so a
     * renderer can round them, and ignoring that draws the same scannable
     * symbol. MaxiCode's bullseye is not a hint: three concentric rings are not
     * modules, the grid is blank where the finder goes, and a renderer that
     * paints only what the grid holds leaves a hole in the middle.
     *
     * The symbol here is deliberately square-moduled, so the refusal is proved
     * to come from the region rather than from the hexagons it happens to
     * accompany in the only symbology that has both.
     */
    public function testAFinderTheGridDoesNotHoldIsRefusedOnItsOwn(): void
    {
        $square = new Symbol(
            width: 4,
            height: 4,
            modules: '1010010110100101',
            dimension: Dimension::Matrix,
            quietZone: QuietZone::uniform(1),
            finderRegions: [new Region(1, 1, 2, 2, RegionRole::RendererDrawn)],
        );

        foreach ($this->scanme->getRegistry()->renderers() as $renderer) {
            $capabilities = $renderer->getCapabilities();
            $this->assertSame(
                \in_array($renderer->getFormat(), ['svg', 'png'], true),
                $capabilities->supportsRegions($square->getFinderRegions()),
                $renderer->getFormat()
            );
            // The module shape is square, so nothing else can be the reason.
            $this->assertTrue($capabilities->supportsShape(ModuleShape::Square));
        }

        $this->scanme->renderSymbol($square, Format::Svg);

        $this->expectException(IncompatibleRendererException::class);
        $this->expectExceptionMessage('has to be drawn by the renderer');
        $this->scanme->renderSymbol($square, Format::HtmlDiv);
    }

    /**
     * Everything except MaxiCode reports regions that are only a hint, and a
     * renderer that ignores them is still correct. If a symbology ever changes
     * its mind about that, it has to say so in the role.
     */
    public function testEveryOtherSymbologysFinderRegionsAreOnlyAHint(): void
    {
        $checked = 0;

        foreach (['qrcode', 'aztec', 'data-matrix', 'pdf417', 'code128'] as $symbology) {
            $regions = $this->scanme->generate('HELLO', $symbology)->getFinderRegions();

            foreach ($regions as $region) {
                $this->assertSame(RegionRole::InGrid, $region->role, $symbology);
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'at least QR reports three of them');
        $this->assertTrue(
            $this->scanme->getRegistry()->getRenderer(Format::AsciiBlocks)
                ->getCapabilities()->supportsRegions($regions),
            'a hint is drawable by every renderer, including the ones that ignore it'
        );
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

        // Stated as membership rather than as a full list: what matters is
        // which symbologies can carry a payload, not how many others exist.
        $all = array_keys($registry->describeGenerators());

        // A URL is printable ASCII, so Code 128 can carry it too; the caller
        // picks, rather than having one guessed for them.
        $forUrl = $registry->generatorsFor(self::URL);
        $this->assertContains('qrcode', $forUrl);
        $this->assertContains('code128', $forUrl);
        $this->assertNotContains('ean13', $forUrl, 'EAN-13 takes 12 or 13 digits');
        $this->assertSame(
            array_values(array_intersect($all, $forUrl)),
            $forUrl,
            'candidates must come back in registration order'
        );

        // Data Matrix escapes bytes above 127, so it carries binary too.
        $forBinary = $registry->generatorsFor("binary\0payload");
        $this->assertContains('qrcode', $forBinary);
        $this->assertContains('data-matrix', $forBinary);
        $this->assertNotContains('code128', $forBinary, 'Code 128 stops at printable ASCII');

        // Past QR's capacity only Code 128 remains, because ISO/IEC 15417 sets
        // no length limit — a symbol that long is useless in print, but
        // inventing a cap the standard does not have would be worse than
        // letting the caller see how wide it comes out.
        $forHuge = $registry->generatorsFor(str_repeat('x', 3000));
        $this->assertContains('code128', $forHuge);
        $this->assertNotContains('qrcode', $forHuge, 'past QR version 40');
        $this->assertNotContains('data-matrix', $forHuge, 'past the largest ECC200 symbol');
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
