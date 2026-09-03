<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\FourState;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * What every four-state postal code is drawn from.
 *
 * Two things live here because they are the family's, not any one member's.
 *
 * The **two-of-four patterns** are the alphabet behind the character tables.
 * A character is four bars, and a four-state code spends its ascenders and its
 * descenders on parity: each of the two nibbles carries exactly two of them,
 * which is what lets a reader reject a bar it misread rather than report the
 * wrong letter. Six such nibbles exist, and the tables are indexed by their
 * ascending order — so RM4SCC's thirty-six characters are six times six, and
 * nothing about them is transcribed. {@see Rm4sccTest} asserts that this
 * constant is the enumeration and not a list somebody typed.
 *
 * The **geometry** is the other half. A four-state symbol is three module rows
 * — ascender band, tracker band, descender band — and its meaning is the ratio
 * between them rather than any absolute height, which is why
 * {@see \CrazyGoat\ScanMePHP\Renderer\Options\AbstractRenderOptions::resolveRowHeights()}
 * scales the three together instead of flattening them to one bar height.
 * Royal Mail specifies 1.9mm of ascender, 1.25mm of tracker and 1.9mm of
 * descender against a 0.5mm bar, which is 3 : 2 : 3 in modules, and it is what
 * zint draws.
 */
final class Patterns
{
    /**
     * The six four-bit patterns with exactly two bits set, ascending.
     *
     * The most significant bit is the first bar.
     */
    public const TWO_OF_FOUR = [0b0011, 0b0101, 0b0110, 0b1001, 0b1010, 0b1100];

    /** Bars per character, in every member of the family. */
    public const BARS_PER_CHARACTER = 4;

    /** Ascender band, tracker band, descender band. */
    public const ROW_HEIGHTS = [3, 2, 3];

    /**
     * The four bars a pair of nibbles draws.
     *
     * @param int $ascenders which of the four bars reach up
     * @param int $descenders which of the four bars reach down
     * @return list<State>
     */
    public static function bars(int $ascenders, int $descenders): array
    {
        $bars = [];
        for ($bar = 0; $bar < self::BARS_PER_CHARACTER; $bar++) {
            $bit = 1 << (self::BARS_PER_CHARACTER - 1 - $bar);
            $bars[] = State::of(($ascenders & $bit) !== 0, ($descenders & $bit) !== 0);
        }

        return $bars;
    }

    /**
     * A symbol from its bars, left to right.
     *
     * Bars are one module wide with one module of space between them, which is
     * the pitch every member of the family draws at: a bar and its gap are the
     * two halves of one character position.
     *
     * @param list<State> $bars
     * @param array<string, int|string|bool> $metadata
     */
    public static function symbol(array $bars, QuietZone $quietZone, array $metadata = []): Symbol
    {
        $width = 2 * \count($bars) - 1;
        $rows = ['', '', ''];

        foreach ($bars as $index => $bar) {
            if ($index > 0) {
                foreach ($rows as $row => $modules) {
                    $rows[$row] = $modules . '0';
                }
            }

            $rows[0] .= $bar->hasAscender() ? '1' : '0';
            // Every bar crosses the tracker. That is what makes the row a
            // tracker rather than a fourth state: a column with nothing in it
            // is not a bar, it is the gap after one.
            $rows[1] .= '1';
            $rows[2] .= $bar->hasDescender() ? '1' : '0';
        }

        return new Symbol(
            width: $width,
            height: 3,
            modules: implode('', $rows),
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            quietZone: $quietZone,
            rowHeights: self::ROW_HEIGHTS,
            metadata: $metadata,
        );
    }

    /**
     * The bars of a symbol, read back as state letters.
     *
     * The inverse of symbol(), and the form a four-state symbol is legible in:
     * a fixture of module strings would say nothing to a reader, where
     * 'AFTTFT…' is what the standards, zint's DAFT symbology and
     * `tools/four_state.py` all write.
     */
    public static function states(Symbol $symbol): string
    {
        $letters = '';
        for ($x = 0; $x < $symbol->getWidth(); $x += 2) {
            $letters .= State::of($symbol->get($x, 0), $symbol->get($x, 2))->value;
        }

        return $letters;
    }
}
