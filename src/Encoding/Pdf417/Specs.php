<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Pdf417;

/**
 * A PDF417 symbol's figures, as functions rather than tables.
 *
 * Nothing here needed measuring. The row indicator values did need *checking*,
 * and the check is worth recording because it is stronger than reading them off
 * a specification: `tools/pdf417_codeword_table.py` derives thousands of
 * (cluster, value, pattern) triples from these six cases alone, across swept
 * payloads, error correction levels and column counts, and they came out with
 * zero contradictions. A single wrong case would have collided within a handful
 * of symbols.
 *
 * @internal Shared encoding primitive, not part of the public API.
 */
final class Specs
{
    /** ISO/IEC 15438 §5.1: one to thirty data columns, three to ninety rows. */
    public const MIN_COLUMNS = 1;

    public const MAX_COLUMNS = 30;

    public const MIN_ROWS = 3;

    public const MAX_ROWS = 90;

    /**
     * Nine levels, 0 to 8, each doubling the previous one's check codewords.
     */
    public const MIN_ERROR_CORRECTION_LEVEL = 0;

    public const MAX_ERROR_CORRECTION_LEVEL = 8;

    /**
     * Data and check codewords together. One short of 929 because the length
     * descriptor counts itself and a codeword value has to stay in the field.
     */
    public const MAX_CODEWORDS = 928;

    /** Seventeen modules per codeword; hence the symbology's name. */
    public const CODEWORD_MODULES = 17;

    /** The start pattern, 8-1-1-1-1-1-1-3, and the stop pattern, one wider. */
    public const START_PATTERN = 0x1FEA8;

    public const STOP_PATTERN = 0x3FA29;

    public const STOP_MODULES = 18;

    /**
     * A row's cluster: its index modulo three, times three.
     *
     * The three clusters exist so a scanner reading a wandering line across the
     * symbol can tell which row each codeword came from. Only 0, 3 and 6 occur,
     * out of the nine residues the cluster formula can produce.
     */
    public static function cluster(int $row): int
    {
        return $row % 3 * 3;
    }

    /** Check codewords at $level: two at level 0, doubling to 512 at level 8. */
    public static function checkCodewords(int $level): int
    {
        return 1 << ($level + 1);
    }

    /**
     * The error correction level ISO/IEC 15438 recommends for $dataCodewords.
     *
     * A recommendation and not a rule, which is why it is a caller option: the
     * standard's own table stops at 863 data codewords, and past that the
     * choice is between fewer check codewords and no symbol at all.
     */
    public static function recommendedLevel(int $dataCodewords): int
    {
        if ($dataCodewords <= 40) {
            return 2;
        }
        if ($dataCodewords <= 160) {
            return 3;
        }
        if ($dataCodewords <= 320) {
            return 4;
        }

        return 5;
    }

    /**
     * The symbol's width in modules for $columns data columns.
     *
     * Start pattern, left row indicator, the data, right row indicator, stop
     * pattern: seventeen modules each and one more for the stop pattern's final
     * bar, which exists so the last space has an edge to be measured against.
     */
    public static function width(int $columns): int
    {
        return self::CODEWORD_MODULES * ($columns + 4) + 1;
    }

    /**
     * The left row indicator's value for row $row.
     *
     * @return int A codeword value, 0 to 928
     */
    public static function leftIndicator(int $row, int $rows, int $columns, int $level): int
    {
        return 30 * intdiv($row, 3) + match ($row % 3) {
            0 => intdiv($rows - 1, 3),
            1 => $level * 3 + ($rows - 1) % 3,
            default => $columns - 1,
        };
    }

    /**
     * The right row indicator's value for row $row.
     *
     * The same three facts as the left indicator — how many rows, how many
     * columns, which error correction level — but rotated by one cluster, so
     * that a scanner that only ever sees one side still collects all three
     * within any three consecutive rows.
     *
     * @return int A codeword value, 0 to 928
     */
    public static function rightIndicator(int $row, int $rows, int $columns, int $level): int
    {
        return 30 * intdiv($row, 3) + match ($row % 3) {
            0 => $columns - 1,
            1 => intdiv($rows - 1, 3),
            default => $level * 3 + ($rows - 1) % 3,
        };
    }
}
