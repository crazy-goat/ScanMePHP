<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\TextPlacement;
use CrazyGoat\ScanMePHP\TextRegion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Text that knows where it goes.
 *
 * Until add-on placement every symbology wanted the same thing — one line,
 * centred, underneath — so Symbol carried a string and renderers centred it.
 * A composite wants two lines on opposite sides of the bars, each over its own
 * half, and this is the mechanism. The shorthand still has to cost nothing,
 * which is most of what is tested here.
 */
class TextRegionTest extends TestCase
{
    public function testAPlainTextIsShorthandForOneLineCentredUnderneath(): void
    {
        $symbol = Symbol::linear(
            modules: '10101',
            quietZone: new QuietZone(),
            barHeight: 5,
            text: 'AB'
        );

        $regions = $symbol->getTextRegions();

        self::assertCount(1, $regions);
        self::assertSame('AB', $regions[0]->text);
        self::assertSame(TextPlacement::Below, $regions[0]->placement);
        self::assertSame(0, $regions[0]->x);
        self::assertSame(5, $regions[0]->width, 'the whole symbol');
        self::assertSame('AB', $symbol->getText());
    }

    public function testNoTextIsNoRegions(): void
    {
        $symbol = Symbol::linear(modules: '10101', quietZone: new QuietZone(), barHeight: 5);

        self::assertSame([], $symbol->getTextRegions());
        self::assertNull($symbol->getText());
    }

    /**
     * getText() stays the answer to "what does this say", so a symbol built
     * from regions reads them out left to right rather than returning null.
     */
    public function testASymbolBuiltFromRegionsStillSaysWhatItSays(): void
    {
        $symbol = $this->twoRegions();

        self::assertSame('main add', $symbol->getText());
        self::assertCount(2, $symbol->getTextRegions());
    }

    public function testTwoSourcesForTheSameLineAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not both');

        new Symbol(
            width: 4,
            height: 1,
            modules: '1111',
            text: 'x',
            textRegions: [new TextRegion('y', TextPlacement::Below, 0, 4)]
        );
    }

    public function testARegionCannotRunPastTheSymbol(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("past the symbol's width of 4");

        new Symbol(
            width: 4,
            height: 1,
            modules: '1111',
            textRegions: [new TextRegion('y', TextPlacement::Below, 3, 3)]
        );
    }

    /**
     * A renderer draws one line per placement, so two regions on the same side
     * that overlap would be drawn over one another. Refusing is the only
     * answer that does not silently lose one of them.
     */
    public function testTwoRegionsOnTheSameSideMayNotOverlap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('overlap');

        new Symbol(
            width: 6,
            height: 1,
            modules: '111111',
            textRegions: [
                new TextRegion('y', TextPlacement::Below, 0, 4),
                new TextRegion('z', TextPlacement::Below, 3, 3),
            ]
        );
    }

    /** The same spans on opposite sides are fine — that is the whole point. */
    public function testTheSameSpanAboveAndBelowIsFine(): void
    {
        $symbol = new Symbol(
            width: 6,
            height: 1,
            modules: '111111',
            textRegions: [
                new TextRegion('under', TextPlacement::Below, 0, 6),
                new TextRegion('over', TextPlacement::Above, 0, 6),
            ]
        );

        self::assertCount(2, $symbol->getTextRegions());
    }

    /** @return iterable<string, array{string, int, int, string}> */
    public static function invalidProvider(): iterable
    {
        yield 'empty text' => ['', 0, 1, 'needs text'];
        yield 'negative start' => ['x', -1, 1, 'left of the symbol'];
        yield 'zero width' => ['x', 0, 0, 'at least one module'];
        yield 'negative width' => ['x', 0, -3, 'at least one module'];
    }

    #[DataProvider('invalidProvider')]
    public function testARegionThatCannotBeDrawnIsRefused(
        string $text,
        int $x,
        int $width,
        string $message
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new TextRegion($text, TextPlacement::Below, $x, $width);
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function centreProvider(): iterable
    {
        yield 'from zero' => [0, 10, 5];
        yield 'offset' => [102, 48, 126];
        yield 'one module' => [7, 1, 7];
        yield 'odd width' => [0, 95, 47];
    }

    #[DataProvider('centreProvider')]
    public function testTheCentreIsWhereARendererPutsTheText(
        int $x,
        int $width,
        int $expected
    ): void {
        $region = new TextRegion('x', TextPlacement::Below, $x, $width);

        self::assertSame($expected, $region->centre());
        self::assertSame($x + $width, $region->end());
    }

    /** @return iterable<string, array{TextRegion, TextRegion, bool}> */
    public static function overlapProvider(): iterable
    {
        $below = static fn (int $x, int $width): TextRegion
            => new TextRegion('x', TextPlacement::Below, $x, $width);

        yield 'disjoint' => [$below(0, 4), $below(4, 4), false];
        yield 'touching' => [$below(0, 4), $below(3, 4), true];
        yield 'contained' => [$below(0, 10), $below(4, 2), true];
        yield 'identical' => [$below(0, 4), $below(0, 4), true];
        yield 'other side' => [
            $below(0, 4),
            new TextRegion('x', TextPlacement::Above, 0, 4),
            false,
        ];
    }

    #[DataProvider('overlapProvider')]
    public function testOverlapIsSymmetricAndOnlyWithinAPlacement(
        TextRegion $first,
        TextRegion $second,
        bool $overlaps
    ): void {
        self::assertSame($overlaps, $first->overlaps($second));
        self::assertSame($overlaps, $second->overlaps($first));
    }

    private function twoRegions(): Symbol
    {
        return new Symbol(
            width: 10,
            height: 1,
            modules: str_repeat('1', 10),
            dimension: Dimension::Linear,
            rowHeights: [5],
            textRegions: [
                // Deliberately out of order: getText() reads them left to
                // right regardless of how they were passed.
                new TextRegion('add', TextPlacement::Above, 7, 3),
                new TextRegion('main', TextPlacement::Below, 0, 6),
            ]
        );
    }
}
