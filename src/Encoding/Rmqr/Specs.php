<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Rmqr;

use CrazyGoat\ScanMePHP\Encoding\Mode;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;

/**
 * The thirty-two rMQR symbols and what each of them holds.
 *
 * rMQR (ISO/IEC 23941) is QR made rectangular, for the places a square will
 * not go: the side of a cable, a syringe barrel, the edge of a circuit board.
 * Where Micro QR shrinks a QR symbol in both directions and loses most of its
 * capacity doing it, rMQR keeps one direction long — R7x139 is seven modules
 * tall and holds a hundred and two digits, which no Micro QR comes close to.
 *
 * Three things about the size table are worth saying before it, because each
 * of them looks like an omission otherwise:
 *
 *  - **Six heights and six widths do not make thirty-six sizes.** Width 27
 *    exists only at heights 11 and 13; a symbol that short and that narrow at
 *    height 7 or 9 would have nowhere to put its data, and one at height 15 or
 *    17 is a shape the standard does not define. So there are thirty-two.
 *
 *  - **There are two error correction levels, not four.** rMQR offers M and H
 *    and nothing else. A symbology whose whole purpose is to survive being
 *    printed on something awkward has no use for L, and Q would sit between
 *    the two without buying a size.
 *
 *  - **The larger cells interleave several Reed–Solomon blocks**, as QR does
 *    and Micro QR never does. {@see BLOCKS_MEDIUM} and {@see BLOCKS_HIGH} say how many, and the count is
 *    not derivable from the codeword counts: R15x99-H splits forty-eight data
 *    codewords into four blocks where R13x139-M splits a hundred and six into
 *    three.
 *
 * Every number below was measured rather than transcribed — see
 * tools/rmqr_reference.py, which reads them back out of symbols drawn by an
 * encoder we did not write.
 *
 * @internal Part of the rMQR encoding pipeline.
 */
final class Specs
{
    /** Modules of blank margin ISO/IEC 23941 requires on every side. */
    public const QUIET_ZONE = 2;

    /** Side of the single finder pattern, in modules. */
    public const FINDER_SIZE = 7;

    /** Side of the sub-finder pattern in the opposite corner, in modules. */
    public const SUB_FINDER_SIZE = 5;

    /**
     * Height and width of each size, in the order the format information
     * numbers them. The index *is* the number the symbol carries, so this
     * order is not a presentation choice.
     *
     * @var list<array{int, int}>
     */
    private const SIZES = [
        [7, 43], [7, 59], [7, 77], [7, 99], [7, 139],
        [9, 43], [9, 59], [9, 77], [9, 99], [9, 139],
        [11, 27], [11, 43], [11, 59], [11, 77], [11, 99], [11, 139],
        [13, 27], [13, 43], [13, 59], [13, 77], [13, 99], [13, 139],
        [15, 43], [15, 59], [15, 77], [15, 99], [15, 139],
        [17, 43], [17, 59], [17, 77], [17, 99], [17, 139],
    ];

    /** Data plus error correction, by size index. */
    private const TOTAL_CODEWORDS = [
        13, 21, 32, 44, 68, 21, 33, 49, 66, 99, 15, 31, 47, 67, 89, 132,
        21, 41, 60, 85, 113, 166, 51, 74, 103, 136, 199, 61, 88, 122, 160, 232,
    ];

    /** Data codewords at level M, by size index. */
    private const DATA_MEDIUM = [
        6, 12, 20, 28, 44, 12, 21, 31, 42, 63, 7, 19, 31, 43, 57, 84,
        12, 27, 38, 53, 73, 106, 33, 48, 67, 88, 127, 39, 56, 78, 100, 152,
    ];

    /** Data codewords at level H, by size index. */
    private const DATA_HIGH = [
        3, 7, 10, 14, 24, 7, 11, 17, 22, 33, 5, 11, 15, 23, 29, 42,
        7, 13, 20, 29, 35, 54, 15, 26, 31, 48, 69, 21, 28, 38, 56, 76,
    ];

    /** Reed–Solomon blocks at level M, by size index. */
    private const BLOCKS_MEDIUM = [
        1, 1, 1, 1, 1, 1, 1, 1, 1, 2, 1, 1, 1, 1, 2, 2,
        1, 1, 1, 2, 2, 3, 1, 1, 2, 2, 3, 1, 2, 2, 3, 4,
    ];

    /** Reed–Solomon blocks at level H, by size index. */
    private const BLOCKS_HIGH = [
        1, 1, 1, 1, 2, 1, 1, 2, 2, 3, 1, 1, 2, 2, 2, 3,
        1, 1, 2, 2, 3, 4, 2, 2, 3, 4, 5, 2, 2, 3, 4, 6,
    ];

    /**
     * Character count width, by size index and mode.
     *
     * These are not simply "wide enough for the capacity". R7x43 holds seven
     * alphanumeric characters and spends four bits saying so; R11x27 holds
     * fourteen digits and spends five. Guessing the minimum gets both wrong,
     * which is why they are measured.
     *
     * @var array<int, list<int>>
     */
    private const COUNT_BITS = [
        Mode::Numeric->value => [
            4, 5, 6, 7, 7, 5, 6, 7, 7, 8, 5, 6, 7, 7, 8, 8,
            5, 7, 7, 8, 8, 8, 7, 7, 8, 8, 9, 7, 8, 8, 8, 9,
        ],
        Mode::Alphanumeric->value => [
            4, 5, 5, 6, 6, 5, 5, 6, 6, 7, 4, 5, 6, 6, 7, 7,
            5, 6, 6, 7, 7, 8, 6, 7, 7, 7, 8, 6, 7, 7, 8, 8,
        ],
        Mode::Byte->value => [
            3, 4, 5, 5, 6, 4, 5, 5, 6, 6, 3, 5, 5, 6, 6, 7,
            4, 5, 6, 6, 7, 7, 6, 6, 7, 7, 7, 6, 6, 7, 7, 8,
        ],
    ];

    /**
     * The mode indicator's value. Three bits at every size, unlike Micro QR,
     * and unlike QR's 0001/0010/0100/1000 they are consecutive.
     *
     * @var array<int, int>
     */
    private const MODE_VALUE = [
        Mode::Numeric->value => 0b001,
        Mode::Alphanumeric->value => 0b010,
        Mode::Byte->value => 0b011,
        Mode::Kanji->value => 0b100,
    ];

    /** Bits of mode indicator, the same at every size. */
    public const MODE_BITS = 3;

    /** Zeroes that close the bit stream when there is room. */
    public const TERMINATOR_BITS = 3;

    /**
     * The centre columns of the alignment patterns, by symbol width.
     *
     * A width carries one alignment column per twenty-something modules and
     * the spacing is not uniform — 59 puts them twenty apart and then
     * eighteen. Width 27 has none: the finder and the sub-finder between them
     * already reach across it.
     *
     * @var array<int, list<int>>
     */
    private const ALIGNMENT = [
        27 => [],
        43 => [21],
        59 => [19, 39],
        77 => [25, 51],
        99 => [23, 49, 75],
        139 => [27, 55, 83, 111],
    ];

    public static function count(): int
    {
        return \count(self::SIZES);
    }

    /** @return list<int> */
    public static function indexes(): array
    {
        return array_keys(self::SIZES);
    }

    public static function height(int $index): int
    {
        return self::SIZES[$index][0];
    }

    public static function width(int $index): int
    {
        return self::SIZES[$index][1];
    }

    /**
     * The two levels every size offers, weakest first.
     *
     * @return list<ErrorCorrectionLevel>
     */
    public static function levels(): array
    {
        return [ErrorCorrectionLevel::Medium, ErrorCorrectionLevel::High];
    }

    public static function supports(ErrorCorrectionLevel $level): bool
    {
        return $level === ErrorCorrectionLevel::Medium || $level === ErrorCorrectionLevel::High;
    }

    public static function totalCodewords(int $index): int
    {
        return self::TOTAL_CODEWORDS[$index];
    }

    public static function dataCodewords(int $index, ErrorCorrectionLevel $level): int
    {
        return $level === ErrorCorrectionLevel::High
            ? self::DATA_HIGH[$index]
            : self::DATA_MEDIUM[$index];
    }

    public static function errorCorrectionCodewords(int $index, ErrorCorrectionLevel $level): int
    {
        return self::totalCodewords($index) - self::dataCodewords($index, $level);
    }

    public static function blocks(int $index, ErrorCorrectionLevel $level): int
    {
        return $level === ErrorCorrectionLevel::High
            ? self::BLOCKS_HIGH[$index]
            : self::BLOCKS_MEDIUM[$index];
    }

    /** Bits of data the symbol holds, headers and terminator included. */
    public static function dataBits(int $index, ErrorCorrectionLevel $level): int
    {
        return self::dataCodewords($index, $level) * 8;
    }

    public static function modeValue(Mode $mode): int
    {
        return self::MODE_VALUE[$mode->value];
    }

    public static function supportsMode(Mode $mode): bool
    {
        return isset(self::COUNT_BITS[$mode->value]);
    }

    public static function countBits(int $index, Mode $mode): int
    {
        return self::COUNT_BITS[$mode->value][$index];
    }

    /**
     * The centre columns of this width's alignment patterns.
     *
     * @return list<int>
     */
    public static function alignment(int $width): array
    {
        return self::ALIGNMENT[$width];
    }

    /**
     * Every width the symbology defines, ascending.
     *
     * @return list<int>
     */
    public static function widths(): array
    {
        return array_keys(self::ALIGNMENT);
    }
}
