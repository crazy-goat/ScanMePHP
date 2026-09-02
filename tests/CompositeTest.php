<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Compatibility;
use CrazyGoat\ScanMePHP\Generator\Ean\Composite;
use CrazyGoat\ScanMePHP\Generator\Ean\Patterns;
use CrazyGoat\ScanMePHP\Renderer\Options\SvgOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use CrazyGoat\ScanMePHP\TextPlacement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An add-on printed beside the symbol it belongs to, as one symbol.
 *
 * Generating the halves has worked since the add-ons landed; placing them is
 * what this closes. The standard does not concatenate them: there is a gap,
 * the add-on's bars are shorter, and its digits go above its own bars because
 * the line below already carries the main symbol's.
 *
 * That the pair scans is DecoderRoundTripTest's business. This is the
 * geometry, and the refusals — a composite is a GS1 label construct, so what
 * may be composed with what is a rule and not a convenience.
 */
class CompositeTest extends TestCase
{
    /** The example on the back of a paperback: an ISBN and a price. */
    private function bookWithPrice(): \CrazyGoat\ScanMePHP\Symbol
    {
        $scanme = Scanme::create();

        return Composite::of(
            $scanme->generate('9788375780642', Symbology::Ean13),
            $scanme->generate('51299', Symbology::Ean5)
        );
    }

    /** @return iterable<string, array{string, Symbology, string, Symbology, int}> */
    public static function compositeProvider(): iterable
    {
        // Main, its symbology, add-on, its symbology, main bar width.
        yield 'ean-13 and ean-5' => ['9788375780642', Symbology::Ean13, '51299', Symbology::Ean5, 95];
        yield 'ean-13 and ean-2' => ['5901234123457', Symbology::Ean13, '12', Symbology::Ean2, 95];
        yield 'upc-a and ean-5' => ['036000291452', Symbology::UpcA, '51299', Symbology::Ean5, 95];
        yield 'upc-e and ean-2' => ['04252614', Symbology::UpcE, '12', Symbology::Ean2, 51];
    }

    #[DataProvider('compositeProvider')]
    public function testTheHalvesAreSeparatedNotConcatenated(
        string $main,
        Symbology $mainSymbology,
        string $addOn,
        Symbology $addOnSymbology,
        int $mainWidth
    ): void {
        $scanme = Scanme::create();
        $mainSymbol = $scanme->generate($main, $mainSymbology);
        $addOnSymbol = $scanme->generate($addOn, $addOnSymbology);

        $composite = Composite::of($mainSymbol, $addOnSymbol);

        self::assertSame(
            $mainWidth + Composite::SEPARATION + $addOnSymbol->getWidth(),
            $composite->getWidth()
        );

        // The gap is light, all the way down, and wider than it looks: the
        // add-on's guard opens with a space of its own.
        foreach ($composite->rows() as $row) {
            self::assertSame(
                str_repeat('0', Composite::SEPARATION),
                substr($row, $mainWidth, Composite::SEPARATION),
                'the separation is not blank'
            );
        }

        $addOnStart = $mainWidth + Composite::SEPARATION;
        self::assertSame(
            '0',
            $composite->rows()[1][$addOnStart],
            "the add-on's guard opens on a space, so the real gap is one wider"
        );
    }

    /**
     * The add-on's bars are shorter than the main symbol's, and by exactly the
     * band its digits occupy — so the two sets of bars share a baseline, which
     * is what a printed label looks like.
     */
    #[DataProvider('compositeProvider')]
    public function testTheAddOnBarsAreShorterByTheHeightOfItsDigits(
        string $main,
        Symbology $mainSymbology,
        string $addOn,
        Symbology $addOnSymbology,
        int $mainWidth
    ): void {
        $scanme = Scanme::create();
        $composite = Composite::of(
            $scanme->generate($main, $mainSymbology),
            $scanme->generate($addOn, $addOnSymbology)
        );

        $rows = $composite->rows();
        $heights = $composite->getRowHeights();
        $addOnStart = $mainWidth + Composite::SEPARATION;

        self::assertSame(Composite::ADDON_TEXT_ROWS, $heights[0], 'the band above the add-on');
        self::assertSame(
            Patterns::BAR_HEIGHT - Composite::ADDON_TEXT_ROWS,
            $heights[1],
            'where both sets of bars run'
        );

        // Row 0: the main symbol's bars, and nothing where the add-on is.
        self::assertStringContainsString('1', substr($rows[0], 0, $mainWidth));
        self::assertSame(
            str_repeat('0', $composite->getWidth() - $addOnStart),
            substr($rows[0], $addOnStart),
            'the add-on has no bars in the band its digits occupy'
        );

        // Row 1: both.
        self::assertStringContainsString('1', substr($rows[1], $addOnStart));

        // The main symbol's total bar height is unchanged by any of this.
        self::assertSame(Patterns::BAR_HEIGHT, $heights[0] + $heights[1]);
    }

    /** The guard descenders belong to the main symbol; an add-on has none. */
    public function testOnlyTheMainSymbolHasGuardDescenders(): void
    {
        $scanme = Scanme::create();
        $composite = Composite::of(
            $scanme->generate('9788375780642', Symbology::Ean13),
            $scanme->generate('51299', Symbology::Ean5)
        );

        self::assertSame(3, $composite->getHeight());
        self::assertSame(Patterns::GUARD_DESCENT, $composite->getRowHeights()[2]);
        self::assertSame(
            str_repeat('0', $composite->getWidth() - 95),
            substr($composite->rows()[2], 95),
            'the descender row stops at the main symbol'
        );
    }

    /**
     * Where the digits go, which is the whole reason a composite needed more
     * than string concatenation.
     */
    public function testTheMainDigitsGoBelowAndTheAddOnsAbove(): void
    {
        $scanme = Scanme::create();
        $composite = Composite::of(
            $scanme->generate('9788375780642', Symbology::Ean13),
            $scanme->generate('51299', Symbology::Ean5)
        );

        $regions = $composite->getTextRegions();
        self::assertCount(2, $regions);

        [$below, $above] = $regions;

        self::assertSame('9788375780642', $below->text);
        self::assertSame(TextPlacement::Below, $below->placement);
        self::assertSame(0, $below->x);
        self::assertSame(95, $below->width, 'centred on the main bars, not on the whole label');

        self::assertSame('51299', $above->text);
        self::assertSame(TextPlacement::Above, $above->placement);
        self::assertSame(95 + Composite::SEPARATION, $above->x);
        self::assertSame(48, $above->width);

        // And asking the symbol what it says gives both, left to right.
        self::assertSame('9788375780642 51299', $composite->getText());
    }

    public function testTheQuietZonesAreTheOnesOnTheOutside(): void
    {
        $scanme = Scanme::create();
        $main = $scanme->generate('9788375780642', Symbology::Ean13);
        $addOn = $scanme->generate('51299', Symbology::Ean5);
        $composite = Composite::of($main, $addOn);

        // The main symbol's left margin and the add-on's right, not the main
        // symbol's right: that one is now the gap between the two.
        self::assertSame($main->getQuietZone()->left, $composite->getQuietZone()->left);
        self::assertSame($addOn->getQuietZone()->right, $composite->getQuietZone()->right);
        self::assertNotSame(
            $main->getQuietZone()->right,
            $composite->getQuietZone()->right,
            'an add-on ends with a narrower margin than a main symbol'
        );
    }

    public function testTheMetadataSaysWhatWasComposed(): void
    {
        $scanme = Scanme::create();
        $composite = Composite::of(
            $scanme->generate('5901234123457', Symbology::Ean13),
            $scanme->generate('12', Symbology::Ean2)
        );

        self::assertSame(Symbology::Ean13->value, $composite->getMetadataValue('symbology'));
        self::assertSame(Symbology::Ean2->value, $composite->getMetadataValue('addOn'));
        self::assertSame('5901234123457', $composite->getMetadataValue('main'));
        self::assertSame('12', $composite->getMetadataValue('addOnText'));
        self::assertSame(Composite::SEPARATION, $composite->getMetadataValue('separation'));
    }

    /** @return iterable<string, array{string, Symbology, string, Symbology, string}> */
    public static function refusalProvider(): iterable
    {
        // GS1 defines no add-on for EAN-8. Its bars take one and a scanner
        // reads the pair, which is exactly why refusing it matters: nothing
        // downstream would notice until a retail system did.
        yield 'ean-8 has no add-on' => [
            '96385074', Symbology::Ean8, '12', Symbology::Ean2, 'main symbol of a composite',
        ];
        yield 'an add-on is not a main symbol' => [
            '12', Symbology::Ean2, '12', Symbology::Ean2, 'main symbol of a composite',
        ];
        yield 'code 128 is not an add-on' => [
            '9788375780642', Symbology::Ean13, 'SCANME', Symbology::Code128, 'add-on of a composite',
        ];
        yield 'a qr code is not either' => [
            '9788375780642', Symbology::Ean13, 'https://example.com', Symbology::QrCode, 'add-on of a composite',
        ];
        yield 'two main symbols' => [
            '9788375780642', Symbology::Ean13, '5901234123457', Symbology::Ean13, 'add-on of a composite',
        ];
    }

    #[DataProvider('refusalProvider')]
    public function testItRefusesToComposeWhatTheStandardDoesNot(
        string $main,
        Symbology $mainSymbology,
        string $addOn,
        Symbology $addOnSymbology,
        string $expectedMessage
    ): void {
        $scanme = Scanme::create();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        Composite::of(
            $scanme->generate($main, $mainSymbology),
            $scanme->generate($addOn, $addOnSymbology)
        );
    }

    /**
     * A symbol built by hand carries no symbology metadata, and a composite
     * cannot check what it is being handed without it. Refusing is the only
     * safe answer — the alternative is composing two arbitrary symbols and
     * calling the result an EAN.
     */
    public function testASymbolThatDoesNotSayWhatItIsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not say what it is');

        Composite::of(
            \CrazyGoat\ScanMePHP\Symbol::linear(
                modules: '101',
                quietZone: new \CrazyGoat\ScanMePHP\QuietZone(),
                barHeight: 10,
            ),
            Scanme::create()->generate('12', Symbology::Ean2)
        );
    }

    public function testEveryOutputFormatDrawsAComposite(): void
    {
        $scanme = Scanme::create();
        $composite = $this->bookWithPrice();

        foreach ($scanme->getRegistry()->rendererFormats() as $format) {
            $output = $scanme->renderSymbol($composite, $format);
            self::assertNotSame('', $output, $format);

            // A raster carries no searchable text; the formats that do are
            // checked below, where both halves of the label have to appear.
            if ($scanme->getContentType($format) === 'image/png') {
                continue;
            }

            self::assertStringContainsString('51299', $output, "the add-on's digits in {$format}");
            self::assertStringContainsString('9788375780642', $output, "the main digits in {$format}");
        }
    }

    /**
     * The add-on's digits are drawn over the add-on, not over the middle of
     * the label — which is the one thing a renderer could get wrong while
     * still producing text.
     */
    public function testTheAddOnsDigitsAreDrawnOverTheAddOn(): void
    {
        $scanme = Scanme::create();
        $composite = $this->bookWithPrice();

        $svg = $scanme->renderSymbol($composite, 'svg', new SvgOptions(moduleSize: 1));

        self::assertSame(1, preg_match('/<text x="(\d+)"[^>]*>51299</', $svg, $addOn));
        self::assertSame(1, preg_match('/<text x="(\d+)"[^>]*>9788375780642</', $svg, $main));

        // Eleven modules of left quiet zone, then the add-on starts at 102.
        $quietLeft = $composite->getQuietZone()->left;
        self::assertSame($quietLeft + 95 + Composite::SEPARATION + 24, (int) $addOn[1]);
        self::assertSame($quietLeft + 47, (int) $main[1]);
        self::assertGreaterThan((int) $main[1], (int) $addOn[1]);
    }

    /**
     * And it is drawn above them, in the band the renderer reserves, so the
     * canvas is taller than the same symbol without text.
     */
    public function testTheBandAboveIsReservedOnlyWhenSomethingGoesInIt(): void
    {
        $scanme = Scanme::create();
        $composite = $this->bookWithPrice();

        $withText = $scanme->renderSymbol($composite, 'svg', new SvgOptions(moduleSize: 1));
        $without = $scanme->renderSymbol(
            $composite,
            'svg',
            new SvgOptions(moduleSize: 1, showText: false)
        );

        self::assertSame(1, preg_match('/viewBox="0 0 \d+ (\d+)"/', $withText, $tall));
        self::assertSame(1, preg_match('/viewBox="0 0 \d+ (\d+)"/', $without, $short));

        // One band above for the add-on's digits, one below for the main ones.
        self::assertGreaterThan((int) $short[1], (int) $tall[1]);
    }

    /**
     * A renderer that cannot place text is told so, rather than centring the
     * price under the middle of the label and calling it done.
     *
     * Every renderer shipped here can place text. This is the contract for one
     * written elsewhere — and the reason positionedText defaults to false: a
     * renderer that predates the capability is reported as unable, not assumed
     * able.
     */
    public function testARendererThatCannotPlaceTextIsRefused(): void
    {
        $composite = $this->bookWithPrice();
        $plain = new class () extends \CrazyGoat\ScanMePHP\Tests\Support\NullRenderer {};

        $reasons = Compatibility::check($composite, $plain);

        self::assertCount(1, $reasons);
        self::assertStringContainsString('over particular columns', $reasons[0]);

        // And an ordinary symbol, whose one line is centred underneath, is
        // still fine for it — the capability gates the composite, not text.
        self::assertSame(
            [],
            Compatibility::check(Scanme::create()->generate('5901234123457', Symbology::Ean13), $plain)
        );
    }

    /** Suppressing the text must suppress both lines, not just the one below. */
    public function testSuppressingTheTextDropsBothLines(): void
    {
        $scanme = Scanme::create();
        $composite = Composite::of(
            $scanme->generate('9788375780642', Symbology::Ean13),
            $scanme->generate('51299', Symbology::Ean5)
        );

        $svg = $scanme->renderSymbol($composite, 'svg', new SvgOptions(showText: false));

        self::assertStringNotContainsString('51299', $svg);
        self::assertStringNotContainsString('9788375780642', $svg);
        self::assertStringNotContainsString('<text', $svg);
    }
}
