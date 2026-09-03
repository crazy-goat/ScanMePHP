<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\AustraliaPost;

use CrazyGoat\ScanMePHP\Generator\FourState\State;

/**
 * The two character tables, which are one enumeration read at two widths.
 *
 * Australia Post numbers the four bar states 0 to 3 and then spells a
 * character as a run of them: two bars for a digit in the **N table**, three
 * for anything in the **C table**. Both tables are published as literal
 * tables, and both are the same rule written out:
 *
 *   1. the combinations that use no tracker bar at all, in ascending order;
 *   2. then those with a tracker in the leading bar only;
 *   3. then, in the wider table, everything left over.
 *
 * That is the whole of it. In the N table the first group is the nine
 * combinations of two low bars — digits 0 to 8 — and the second group's first
 * entry is 9; nothing else is used. In the C table the first group is
 * twenty-seven combinations, which is the alphabet and a zero, the second is
 * the other nine digits, and the leftovers are the space, the hash and the
 * lower case.
 *
 * Deriving it rather than transcribing it matters here more than the saving in
 * lines. A table of sixty-four three-bar patterns is transcribed by hand
 * exactly once and then trusted forever, and a pair swapped in the middle of
 * it draws two legal characters for the rest of the symbology's life — legal,
 * scannable, and somebody else's. {@see \CrazyGoat\ScanMePHP\Tests\AustraliaPostTest}
 * asserts the three rules above against the tables this class produces.
 *
 * The state numbering is the standard's and is not the family's: 0 is a full
 * bar, 3 is a tracker. It exists in this namespace only, because it is what
 * the tables and the Reed–Solomon codewords are written in.
 */
final class Bars
{
    /** The four states, in the order Australia Post numbers them. */
    public const STATES = [State::Full, State::Ascender, State::Descender, State::Tracker];

    /** A bar carrying nothing, used to pad the customer field to its width. */
    public const FILLER = 3;

    /** Bars per entry in the N table, and in the C table. */
    public const NUMERIC_BARS = 2;
    public const CHARACTER_BARS = 3;

    /** What the N table carries: the ten digits, and only in that order. */
    public const DIGITS = '0123456789';

    /**
     * What the C table carries, in table order.
     *
     * Not an alphabet anybody would choose — capitals and a zero, then the
     * other nine digits, then the space, the hash and the lower case — but the
     * order is the enumeration's, not a preference, and writing it out is how
     * the three groups above are named.
     */
    public const CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0'
        . '123456789'
        . ' #abcdefghijklmnopqrstuvwxyz';

    /**
     * The two bars of a digit in the N table.
     *
     * @return list<int>
     * @throws \InvalidArgumentException when the character is not a digit
     */
    public static function numeric(string $digit): array
    {
        $index = strpos(self::DIGITS, $digit);
        if ($index === false || $digit === '') {
            throw new \InvalidArgumentException(sprintf(
                'the Australia Post N table carries digits only, got "%s"',
                $digit
            ));
        }

        return self::order(self::NUMERIC_BARS)[$index];
    }

    /**
     * The three bars of a character in the C table.
     *
     * @return list<int>
     * @throws \InvalidArgumentException when the character is not in the table
     */
    public static function character(string $character): array
    {
        $index = strpos(self::CHARACTERS, $character);
        if ($index === false || $character === '') {
            throw new \InvalidArgumentException(sprintf(
                'the Australia Post C table does not carry "%s"',
                $character
            ));
        }

        return self::order(self::CHARACTER_BARS)[$index];
    }

    /** Whether every character of $data is in the C table. */
    public static function covers(string $data): bool
    {
        return strspn($data, self::CHARACTERS) === \strlen($data);
    }

    /**
     * Every combination of $bars bars, in the order the tables index them.
     *
     * @return list<list<int>>
     */
    public static function order(int $bars): array
    {
        /** @var array<int, list<list<int>>> $memo */
        static $memo = [];
        if (isset($memo[$bars])) {
            return $memo[$bars];
        }

        $low = [];
        $leading = [];
        $rest = [];

        for ($value = 0; $value < 4 ** $bars; $value++) {
            $states = self::states($value, $bars);
            $tracker = static fn (int $state): bool => $state === self::FILLER;

            if (\count(array_filter($states, $tracker)) === 0) {
                $low[] = $states;
            } elseif ($states[0] === self::FILLER && \count(array_filter(\array_slice($states, 1), $tracker)) === 0) {
                $leading[] = $states;
            } else {
                $rest[] = $states;
            }
        }

        return $memo[$bars] = [...$low, ...$leading, ...$rest];
    }

    /**
     * A codeword as its bars, most significant first.
     *
     * @return list<int>
     */
    public static function states(int $value, int $bars): array
    {
        $states = [];
        for ($bar = $bars - 1; $bar >= 0; $bar--) {
            $states[] = intdiv($value, 4 ** $bar) % 4;
        }

        return $states;
    }

    /**
     * The bars of one codeword read back as its value.
     *
     * @param list<int> $states
     */
    public static function value(array $states): int
    {
        $value = 0;
        foreach ($states as $state) {
            $value = $value * 4 + $state;
        }

        return $value;
    }
}
