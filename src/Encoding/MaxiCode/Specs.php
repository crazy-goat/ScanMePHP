<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\MaxiCode;

/**
 * MaxiCode's fixed geometry and codeword budget.
 *
 * MaxiCode has exactly one size. Where every other matrix symbology in this
 * library picks a version, a layer count or a column count to fit the data,
 * MaxiCode is 33 rows of hexagons around a central bullseye and nothing else,
 * so "does it fit" is a yes or no rather than a search. That single size is why
 * the numbers here are constants and not tables.
 *
 * The rows alternate in width because the hexagons are staggered: an even row
 * holds 30 of them and an odd row 29, offset half a module to the right. That
 * comes to 974 positions for 144 codewords of six bits — 864 module bits — with
 * the remaining 110 taken by the orientation patterns and by the bullseye,
 * which is not made of modules at all.
 */
final class Specs
{
    public const ROWS = 33;

    /** Columns on an even row; an odd row has one fewer. */
    public const COLUMNS = 30;

    public const CODEWORD_BITS = 6;

    public const CODEWORD_VALUES = 1 << self::CODEWORD_BITS;

    public const CODEWORDS = 144;

    /**
     * The primary message: the mode codeword and the first nine data codewords.
     *
     * The split is not a storage detail. The primary message has its own error
     * correction and sits closest to the bullseye, so a scanner can read the
     * mode and — in modes 2 and 3 — the whole routing block from the middle of
     * a damaged symbol without recovering the rest.
     */
    public const PRIMARY_CODEWORDS = 10;

    public const PRIMARY_CHECK_CODEWORDS = 10;

    public const SECONDARY_DATA_CODEWORDS = 84;

    /**
     * Check codewords per interleaved block of the secondary message.
     *
     * The secondary message is split into two blocks — the even-numbered
     * codewords and the odd-numbered ones — and each gets its own twenty. Two
     * blocks rather than one is what makes a burst of damage across
     * neighbouring modules land half in each.
     */
    public const SECONDARY_CHECK_CODEWORDS = 20;

    public const DATA_CODEWORDS = self::PRIMARY_CODEWORDS - 1 + self::SECONDARY_DATA_CODEWORDS;

    /** The field every MaxiCode codeword lives in: six bits wide. */
    public const GALOIS_FIELD_BITS = self::CODEWORD_BITS;

    /**
     * The module the bullseye is centred on.
     *
     * Row 16 is even, so this is a whole-module offset and the bullseye sits
     * exactly on a module centre rather than between two.
     */
    public const BULLSEYE_ROW = 16;

    public const BULLSEYE_COLUMN = 14;

    /** Columns in the given module row. */
    public static function columns(int $row): int
    {
        return $row % 2 === 0 ? self::COLUMNS : self::COLUMNS - 1;
    }

    /** Every module position, in row-major order. */
    public static function positions(): \Generator
    {
        for ($row = 0; $row < self::ROWS; $row++) {
            for ($column = 0, $columns = self::columns($row); $column < $columns; $column++) {
                yield [$row, $column];
            }
        }
    }
}
