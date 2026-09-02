<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Itf;

/**
 * Interleaved 2 of 5: the tables and the interleaving.
 *
 * Each digit is five elements of which exactly two are wide — that is the
 * "2 of 5" — and a pair of digits shares one ten-element block: the first
 * digit's five elements are the bars, the second digit's are the spaces
 * between them. That is the "interleaved", and it is what makes ITF the
 * densest of the classic linear symbologies for pure digits, at the cost of
 * two properties worth stating plainly.
 *
 * A payload must have an even number of digits, because a digit with no
 * partner has nothing to interleave with. And the symbology is not
 * self-checking: nothing in the bars marks where a character begins, so a scan
 * that clips the start or stop guard reads a valid shorter number rather than
 * failing. This is why ITF is printed with a bearer bar and why ITF-14 fixes
 * the digit count — see Itf14.
 *
 * The table was read out of zxing-cpp rather than transcribed, for the reason
 * every other table in this library was: a swapped row gives a symbol that
 * still scans, as a different number.
 */
final class Patterns
{
    /**
     * Element widths per digit, narrow or wide, indexed by the digit.
     *
     * Read as bars when the digit is first of a pair and as spaces when it is
     * second; the same five elements serve both.
     *
     * @var list<string>
     */
    public const PATTERNS = [
        'nnwwn', 'wnnnw', 'nwnnw', 'wwnnn', 'nnwnw',
        'wnwnn', 'nwwnn', 'nnnww', 'wnnwn', 'nwnwn',
    ];

    /** Four narrow elements, whatever the wide-to-narrow ratio is. */
    public const START = '1010';

    /** Elements per digit, two of them wide. */
    public const ELEMENTS = 5;

    /** Minimum quiet zone either side, in narrow modules (ISO/IEC 16390 §4.4). */
    public const QUIET_ZONE = 10;

    /** A legible default; the standard states height as a fraction of length. */
    public const BAR_HEIGHT = 50;

    /**
     * The stop guard: a wide bar, a narrow space, a narrow bar.
     *
     * Asymmetric with the start guard on purpose — it is how a scanner knows
     * which way round it read the symbol.
     */
    public static function stop(int $wideRatio): string
    {
        return str_repeat('1', $wideRatio) . '01';
    }

    /** Whether $data is an even number of digits, which is all ITF carries. */
    public static function isEncodable(string $data): bool
    {
        return preg_match('/^(?:\d\d)+$/', $data) === 1;
    }

    /**
     * Bars and spaces for an even-length digit string, guards included.
     *
     * @throws \InvalidArgumentException on anything but an even count of digits
     */
    public static function modules(string $digits, int $wideRatio): string
    {
        if (!self::isEncodable($digits)) {
            throw new \InvalidArgumentException(sprintf(
                'ITF interleaves digits in pairs, so it needs an even number of them and nothing else, got: %s',
                $digits
            ));
        }

        $modules = self::START;
        for ($position = 0, $length = \strlen($digits); $position < $length; $position += 2) {
            $modules .= self::pair(
                self::PATTERNS[(int) $digits[$position]],
                self::PATTERNS[(int) $digits[$position + 1]],
                $wideRatio
            );
        }

        return $modules . self::stop($wideRatio);
    }

    /** The module width of a symbol carrying $digits digits, guards included. */
    public static function width(int $digits, int $wideRatio): int
    {
        // Per pair: four wide elements and six narrow. Plus the four-module
        // start guard and the stop guard's wide bar and two narrow elements.
        return \strlen(self::START)
            + $digits / 2 * (4 * $wideRatio + 6)
            + $wideRatio + 2;
    }

    /**
     * One ten-element block: $bars drawn as bars, $spaces as the gaps between
     * them, taken one element at a time from each.
     */
    private static function pair(string $bars, string $spaces, int $wideRatio): string
    {
        $modules = '';
        for ($element = 0; $element < self::ELEMENTS; $element++) {
            $modules .= str_repeat('1', $bars[$element] === 'w' ? $wideRatio : 1);
            $modules .= str_repeat('0', $spaces[$element] === 'w' ? $wideRatio : 1);
        }

        return $modules;
    }
}
