<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\MicroQr;

use CrazyGoat\ScanMePHP\Encoding\Mode;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;

/**
 * The four Micro QR symbols and what each of them holds.
 *
 * Micro QR is not QR made smaller. Almost every width QR fixes once, Micro QR
 * varies by version: the mode indicator is nought, one, two or three bits, the
 * character count is a different width in each version *and* each mode, the
 * terminator is three, five, seven or nine zeroes. There is no version
 * information, no alignment pattern, one finder rather than three, four masks
 * rather than eight, and the quiet zone is two modules rather than four.
 *
 * Two consequences are worth stating before the tables, because both look like
 * bugs otherwise:
 *
 *  - **M1 has no error correction level to choose.** Its two check codewords
 *    are error *detection*: a reader can tell an M1 symbol has been misread
 *    and cannot repair it. The standard calls the level of an M1 symbol
 *    "detection only", so this class refuses every level for it rather than
 *    pretending one of the four applies.
 *
 *  - **M1 and M3 end on half a codeword.** Their final data codeword is four
 *    bits, not eight. That is not padding: the capacity really is twenty bits
 *    at M1 and eighty-four at M3-L, and the nibble is a codeword as far as
 *    Reed–Solomon is concerned and half a codeword as far as the matrix is.
 *    {@see FINAL_NIBBLE} names the versions this happens to.
 *
 * The character capacities that fall out of these tables were checked against
 * ISO/IEC 18004:2015 Table 7 by measurement rather than transcription — see
 * tools/micro_qr_reference.py, which finds the longest payload each version
 * accepts by asking an encoder we did not write.
 *
 * @internal Part of the Micro QR encoding pipeline.
 */
final class Specs
{
    public const MIN_VERSION = 1;

    public const MAX_VERSION = 4;

    /** Modules of blank margin ISO/IEC 18004 requires on every side. */
    public const QUIET_ZONE = 2;

    /** Micro QR uses four of QR's eight mask patterns. */
    public const MASKS = 4;

    /** Side of the single finder pattern, in modules. */
    public const FINDER_SIZE = 7;

    /** Width in modules, by version. */
    private const SIZE = [1 => 11, 2 => 13, 3 => 15, 4 => 17];

    /** Data plus error correction, by version. */
    private const TOTAL_CODEWORDS = [1 => 5, 2 => 10, 3 => 17, 4 => 24];

    /** Versions whose last data codeword is four bits rather than eight. */
    private const FINAL_NIBBLE = [1, 3];

    /**
     * Data codewords by version and level; a missing entry is a combination
     * the standard does not define. M1 is keyed by null, having no level.
     *
     * @var array<int, array<int|string, int>>
     */
    private const DATA_CODEWORDS = [
        1 => ['detection' => 3],
        2 => [ErrorCorrectionLevel::Low->value => 5, ErrorCorrectionLevel::Medium->value => 4],
        3 => [ErrorCorrectionLevel::Low->value => 11, ErrorCorrectionLevel::Medium->value => 9],
        4 => [
            ErrorCorrectionLevel::Low->value => 16,
            ErrorCorrectionLevel::Medium->value => 14,
            ErrorCorrectionLevel::Quartile->value => 10,
        ],
    ];

    /**
     * The three bits the format information carries ahead of the mask, which
     * are the version and the level rolled into one number. This is the only
     * place the two are not independent, and the reason a reader can tell M3-M
     * from M4-L without being told the size separately.
     *
     * @var array<int, array<int|string, int>>
     */
    private const SYMBOL_NUMBER = [
        1 => ['detection' => 0],
        2 => [ErrorCorrectionLevel::Low->value => 1, ErrorCorrectionLevel::Medium->value => 2],
        3 => [ErrorCorrectionLevel::Low->value => 3, ErrorCorrectionLevel::Medium->value => 4],
        4 => [
            ErrorCorrectionLevel::Low->value => 5,
            ErrorCorrectionLevel::Medium->value => 6,
            ErrorCorrectionLevel::Quartile->value => 7,
        ],
    ];

    /**
     * Bits of mode indicator, by version. M1 spends none: it has only one mode
     * to be in, so saying which would be four bits of nothing in a symbol with
     * twenty to spend.
     */
    private const MODE_BITS = [1 => 0, 2 => 1, 3 => 2, 4 => 3];

    /**
     * The mode indicator's value, which is its position in the list of modes
     * this version supports rather than QR's fixed 0001/0010/0100/1000.
     *
     * @var array<int, int>
     */
    private const MODE_VALUE = [
        Mode::Numeric->value => 0,
        Mode::Alphanumeric->value => 1,
        Mode::Byte->value => 2,
        Mode::Kanji->value => 3,
    ];

    /**
     * Character count width, by version and mode. A gap is a mode the version
     * cannot be in at all: M1 is numeric and nothing else, M2 adds
     * alphanumeric, and only M3 and M4 can carry bytes.
     *
     * @var array<int, array<int, int>>
     */
    private const COUNT_BITS = [
        1 => [Mode::Numeric->value => 3],
        2 => [Mode::Numeric->value => 4, Mode::Alphanumeric->value => 3],
        3 => [
            Mode::Numeric->value => 5,
            Mode::Alphanumeric->value => 4,
            Mode::Byte->value => 4,
            Mode::Kanji->value => 3,
        ],
        4 => [
            Mode::Numeric->value => 6,
            Mode::Alphanumeric->value => 5,
            Mode::Byte->value => 5,
            Mode::Kanji->value => 4,
        ],
    ];

    /** Zeroes that close the bit stream when there is room, by version. */
    private const TERMINATOR_BITS = [1 => 3, 2 => 5, 3 => 7, 4 => 9];

    public static function size(int $version): int
    {
        return self::SIZE[$version];
    }

    /** @return list<int> */
    public static function versions(): array
    {
        return array_keys(self::SIZE);
    }

    /** Whether this version can be built at this level at all. */
    public static function supports(int $version, ?ErrorCorrectionLevel $level): bool
    {
        return isset(self::DATA_CODEWORDS[$version][self::key($level)]);
    }

    /**
     * The levels a version offers, in ascending order of recovery.
     *
     * Empty for M1, which is the honest answer: there is nothing to choose.
     *
     * @return list<ErrorCorrectionLevel>
     */
    public static function levels(int $version): array
    {
        if ($version === 1) {
            return [];
        }

        return array_values(array_map(
            static fn (int|string $value): ErrorCorrectionLevel => ErrorCorrectionLevel::from((int) $value),
            array_keys(self::DATA_CODEWORDS[$version]),
        ));
    }

    public static function dataCodewords(int $version, ?ErrorCorrectionLevel $level): int
    {
        return self::DATA_CODEWORDS[$version][self::key($level)];
    }

    public static function errorCorrectionCodewords(int $version, ?ErrorCorrectionLevel $level): int
    {
        return self::TOTAL_CODEWORDS[$version] - self::dataCodewords($version, $level);
    }

    public static function totalCodewords(int $version): int
    {
        return self::TOTAL_CODEWORDS[$version];
    }

    /** Whether this version's last data codeword is four bits rather than eight. */
    public static function endsOnANibble(int $version): bool
    {
        return \in_array($version, self::FINAL_NIBBLE, true);
    }

    /** Bits of data the symbol holds, headers and terminator included. */
    public static function dataBits(int $version, ?ErrorCorrectionLevel $level): int
    {
        return self::dataCodewords($version, $level) * 8 - (self::endsOnANibble($version) ? 4 : 0);
    }

    public static function symbolNumber(int $version, ?ErrorCorrectionLevel $level): int
    {
        return self::SYMBOL_NUMBER[$version][self::key($level)];
    }

    public static function modeBits(int $version): int
    {
        return self::MODE_BITS[$version];
    }

    public static function modeValue(Mode $mode): int
    {
        return self::MODE_VALUE[$mode->value];
    }

    public static function supportsMode(int $version, Mode $mode): bool
    {
        return isset(self::COUNT_BITS[$version][$mode->value]);
    }

    public static function countBits(int $version, Mode $mode): int
    {
        return self::COUNT_BITS[$version][$mode->value];
    }

    /**
     * The modes this version can be in, widest first.
     *
     * @return list<Mode>
     */
    public static function modes(int $version): array
    {
        return array_values(array_map(
            Mode::from(...),
            array_keys(self::COUNT_BITS[$version]),
        ));
    }

    public static function terminatorBits(int $version): int
    {
        return self::TERMINATOR_BITS[$version];
    }

    private static function key(?ErrorCorrectionLevel $level): int|string
    {
        return !$level instanceof \CrazyGoat\ScanMePHP\ErrorCorrectionLevel ? 'detection' : $level->value;
    }
}
