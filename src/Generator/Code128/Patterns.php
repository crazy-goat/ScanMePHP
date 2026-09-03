<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code128;

/**
 * The Code 128 table and the constants around it.
 *
 * Every one of the 107 patterns spans 11 modules as six elements — bar, space,
 * bar, space, bar, space — and the bars of each span an even number of them.
 * That parity is not decoration: it is the rule a scanner uses to reject a
 * character it misread, and it is why the widths are stored rather than the
 * modules. Source: ISO/IEC 15417 Table 1, checked module for module against
 * zxing-cpp in tests/fixtures/code128_reference.csv.
 */
final class Patterns
{
    /**
     * Element widths for symbol values 0 to 106, as bar/space/bar/space/bar/space.
     *
     * @var list<string>
     */
    public const WIDTHS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232',
    ];

    /** The stop pattern is the one 13-module character, with a trailing bar. */
    public const STOP = '2331112';

    /** Switch to character set C, and the same for B. */
    public const CODE_C = 99;

    public const CODE_B = 100;

    /**
     * The symbol character with no data meaning of its own.
     *
     * Directly after the start code it marks the symbol as GS1-128; between
     * element strings it terminates one of variable length. It is the same
     * value in both character sets, and in set C it stands alone rather than
     * standing for a digit pair.
     */
    public const FNC1 = 102;

    public const START_B = 104;

    public const START_C = 105;

    /** Checksum modulus, i.e. the number of symbol values excluding stop. */
    public const CHECKSUM_MODULUS = 103;

    /** Minimum quiet zone either side, in modules (ISO/IEC 15417 §5.3). */
    public const QUIET_ZONE = 10;

    /**
     * Default bar height in modules. The standard states height as a fraction
     * of length rather than a module count, so this is a legible default that
     * render options are expected to override for print.
     */
    public const BAR_HEIGHT = 50;

    /**
     * Bars and spaces for a list of symbol values, stop pattern included.
     *
     * @param list<int> $values Start code, payload and check character
     */
    public static function modules(array $values): string
    {
        $modules = '';
        foreach ($values as $value) {
            $modules .= self::elements(self::WIDTHS[$value]);
        }

        return $modules . self::elements(self::STOP);
    }

    /**
     * The weighted modulo 103 check character.
     *
     * The start code counts once, then each payload character by its one-based
     * position. It is mandatory and a scanner verifies it.
     *
     * @param list<int> $values Start code followed by the payload
     */
    public static function checkCharacter(array $values): int
    {
        $sum = $values[0];
        for ($position = 1, $count = \count($values); $position < $count; $position++) {
            $sum += $position * $values[$position];
        }

        return $sum % self::CHECKSUM_MODULUS;
    }

    /** Element widths to '1'/'0' modules, starting with a bar. */
    private static function elements(string $widths): string
    {
        $modules = '';
        for ($i = 0, $length = \strlen($widths); $i < $length; $i++) {
            $modules .= str_repeat($i % 2 === 0 ? '1' : '0', (int) $widths[$i]);
        }

        return $modules;
    }
}
