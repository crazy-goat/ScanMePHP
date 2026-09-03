<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBar;

/**
 * The arithmetic every GS1 DataBar symbol is built from.
 *
 * DataBar does not have a pattern table. A data character is a *value*, and its
 * eight element widths are the value's index into an enumeration of every legal
 * width combination — four bars and four spaces, with the bar widths summing to
 * one fixed number and the space widths to another. {@see widths()} is that
 * enumeration, walked one element at a time: at each element it counts how many
 * combinations start with a width of one, of two, and so on, and subtracts
 * until the remaining value falls inside the current bucket. That is why there
 * is no table here to be transcribed wrongly — there is a function, and a
 * fixture from an encoder we did not write says it agrees.
 *
 * Two things about it were measured rather than assumed, because the standard's
 * own phrasing does not survive being remembered:
 *
 *  * **The bar widths carry no "at least one narrow" rule and the space widths
 *    do.** Getting this backwards is not a small error — it changes how many
 *    combinations each bucket holds, so every value past the first shifts. It
 *    was settled by counting: with the rule on the spaces, the group sizes come
 *    out 161, 800, 1054, 700 and 126, and those are exactly the gaps between
 *    the group boundaries the oracle's own symbols show.
 *
 *  * **The inside characters interleave the other way round.** An outside
 *    character is drawn bar first, an inside character space first. Both were
 *    checked against all 2841 outside and all 1597 inside values, and each
 *    ordering is the only one of the four that matches.
 *
 * The checksum weights are not a table either: they are the powers of three
 * modulo 79, in order. Reference implementations ship them as thirty-two
 * literals, which is the same numbers spelled in the way most likely to acquire
 * a typo.
 *
 * @internal Shared by the DataBar generators.
 */
final class Patterns
{
    /** Bars and spaces in one data character, four of each. */
    public const ELEMENTS = 8;

    /** The prime the checksum lives in; also the count of its residues. */
    public const CHECKSUM_MODULUS = 79;

    /**
     * The nine finder patterns, the one thing here that is a table.
     *
     * They are not an enumeration of anything: the widths follow no rule that
     * generates all nine and nothing else, so they are measured from the
     * oracle's symbols and asserted against it. Each is five elements summing
     * to fifteen, and each ends in two single modules — which is what makes a
     * finder recognisable to a scanner sweeping a line at any angle.
     *
     * @var list<list<int>>
     */
    public const FINDERS = [
        [3, 8, 2, 1, 1],
        [3, 5, 5, 1, 1],
        [3, 3, 7, 1, 1],
        [3, 1, 9, 1, 1],
        [2, 7, 4, 1, 1],
        [2, 5, 6, 1, 1],
        [2, 3, 8, 1, 1],
        [1, 5, 7, 1, 1],
        [1, 3, 9, 1, 1],
    ];

    /**
     * The outside characters' groups: 2841 values in five buckets.
     *
     * Read a column at a time. Group 0 spends twelve modules on its bars and
     * four on its spaces, no bar wider than eight and no space wider than one —
     * which leaves exactly one legal set of spaces, so the group holds as many
     * values as it has bar combinations. Group 4 is the mirror of that.
     */
    public const OUTSIDE = [
        'offsets' => [0, 161, 961, 2015, 2715],
        'spaceCombinations' => [1, 10, 34, 70, 126],
        'barModules' => [12, 10, 8, 6, 4],
        'spaceModules' => [4, 6, 8, 10, 12],
        'widestBar' => [8, 6, 4, 3, 1],
        'widestSpace' => [1, 3, 5, 6, 8],
    ];

    /** The inside characters' groups: 1597 values in four buckets. */
    public const INSIDE = [
        'offsets' => [0, 336, 1036, 1516],
        'spaceCombinations' => [4, 20, 48, 81],
        'barModules' => [10, 8, 6, 4],
        'spaceModules' => [5, 7, 9, 11],
        'widestBar' => [7, 5, 3, 1],
        'widestSpace' => [2, 4, 6, 8],
    ];

    /** Values an outside character can carry. */
    public const OUTSIDE_VALUES = 2841;

    /** Values an inside character can carry. */
    public const INSIDE_VALUES = 1597;

    /**
     * One data character's eight element widths, bars and spaces interleaved.
     *
     * $spaceFirst says which of the two the character starts with: an outside
     * character opens with a bar, an inside one with a space.
     *
     * @param array{offsets: list<int>, spaceCombinations: list<int>, barModules: list<int>, spaceModules: list<int>, widestBar: list<int>, widestSpace: list<int>} $group
     * @return list<int>
     */
    public static function character(int $value, array $group, bool $spaceFirst): array
    {
        $index = 0;
        foreach ($group['offsets'] as $i => $offset) {
            if ($value >= $offset) {
                $index = $i;
            }
        }

        $remainder = $value - $group['offsets'][$index];
        $combinations = $group['spaceCombinations'][$index];

        $bars = self::widths(
            intdiv($remainder, $combinations),
            $group['barModules'][$index],
            $group['widestBar'][$index],
            false,
        );
        $spaces = self::widths(
            $remainder % $combinations,
            $group['spaceModules'][$index],
            $group['widestSpace'][$index],
            true,
        );

        $widths = [];
        for ($i = 0; $i < 4; $i++) {
            if ($spaceFirst) {
                $widths[] = $spaces[$i];
                $widths[] = $bars[$i];
            } else {
                $widths[] = $bars[$i];
                $widths[] = $spaces[$i];
            }
        }

        return $widths;
    }

    /**
     * The $value'th way to split $modules into four widths of at most $widest.
     *
     * $requireNarrow drops every combination whose widths are all wider than
     * one module, which is the rule that applies to a character's spaces and
     * not to its bars.
     *
     * @return list<int>
     */
    public static function widths(int $value, int $modules, int $widest, bool $requireNarrow): array
    {
        $widths = [];
        $remaining = $modules;
        $narrowSoFar = false;

        for ($element = 0; $element < 3; $element++) {
            $left = 3 - $element;
            $width = 1;
            $bucket = 0;

            while ($width <= $remaining) {
                $bucket = self::combinations($remaining - $width - 1, $left - 1);

                // The correction applies only while nothing chosen is a single
                // module, this element included: once one is, every
                // combination still ahead already satisfies the rule.
                if ($requireNarrow
                    && !$narrowSoFar
                    && $width > 1
                    && $remaining - $width - $left >= $left
                ) {
                    $bucket -= self::combinations($remaining - $width - $left - 1, $left - 1);
                }

                if ($left > 1) {
                    $overwide = 0;
                    for ($largest = $remaining - $width - $left + 1; $largest > $widest; $largest--) {
                        $overwide += self::combinations($remaining - $width - $largest - 1, $left - 2);
                    }
                    $bucket -= $overwide * $left;
                } elseif ($remaining - $width > $widest) {
                    $bucket--;
                }

                $value -= $bucket;
                if ($value < 0) {
                    break;
                }

                $width++;
            }

            $value += $bucket;
            $remaining -= $width;
            $widths[] = $width;
            $narrowSoFar = $narrowSoFar || $width === 1;
        }

        $widths[] = $remaining;

        return $widths;
    }

    /**
     * The checksum residue of a symbol's data character widths.
     *
     * The weights are the powers of three modulo 79 rather than a literal
     * table, which is the same thirty-two numbers with nothing to mistype.
     *
     * @param list<int> $widths every data character's widths, in value order
     */
    public static function checksum(array $widths): int
    {
        $checksum = 0;
        $weight = 1;
        foreach ($widths as $width) {
            $checksum = ($checksum + $width * $weight) % self::CHECKSUM_MODULUS;
            $weight = $weight * 3 % self::CHECKSUM_MODULUS;
        }

        return $checksum;
    }

    /**
     * @param list<int> $widths
     * @return list<int> the widths in reverse, for the mirrored half
     */
    public static function mirror(array $widths): array
    {
        return array_reverse($widths);
    }

    /**
     * Widths to a run-length string of light and dark modules.
     *
     * @param list<int> $widths
     */
    public static function modules(array $widths, bool $startsDark = false): string
    {
        $modules = '';
        $dark = $startsDark;
        foreach ($widths as $width) {
            $modules .= str_repeat($dark ? '1' : '0', $width);
            $dark = !$dark;
        }

        return $modules;
    }

    private static function combinations(int $n, int $r): int
    {
        if ($r < 0 || $n < 0 || $r > $n) {
            return 0;
        }

        $result = 1;
        for ($i = 0; $i < $r; $i++) {
            $result = intdiv($result * ($n - $i), $i + 1);
        }

        return $result;
    }
}
