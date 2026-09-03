<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\FourState;

/**
 * The thirty-six characters every four-state code in this library carries.
 *
 * RM4SCC and KIX draw the same bars for the same character — measured, not
 * assumed: `tools/four_state.py` reads both tables off zint and
 * {@see \CrazyGoat\ScanMePHP\Tests\KixTest} asserts they still agree. That is
 * why the table lives here rather than twice, once per symbology: two copies
 * of an arithmetic derivation are two chances for one of them to drift, and a
 * drifted copy still draws thirty-six legal characters.
 *
 * There is no table. A character is a pair of two-of-four nibbles — one saying
 * which of its four bars reach up, one saying which reach down — and its
 * position in the alphabet is the pair read as a base-six number:
 * `index = ascenders * 6 + descenders`, digits first, then letters. Every
 * published four-state character table is that arithmetic written out, and
 * writing it out is how a table acquires a typo.
 *
 * What the two symbologies do *around* the characters is where they differ,
 * and that stays with each of them: RM4SCC adds a start bar, a check character
 * and a stop bar; KIX adds nothing at all.
 */
final class Alphabet
{
    /** Six times six characters, in the order the nibble pairs enumerate them. */
    public const CHARACTERS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * A character's place in the alphabet.
     *
     * @throws \InvalidArgumentException when the character is not in it
     */
    public static function indexOf(string $character): int
    {
        $index = strpos(self::CHARACTERS, $character);
        if ($index === false) {
            throw new \InvalidArgumentException(sprintf(
                'a four-state code carries digits and capital letters only, got "%s"',
                $character
            ));
        }

        return $index;
    }

    /**
     * The four bars of one character.
     *
     * @return list<State>
     * @throws \InvalidArgumentException when the character is not in the alphabet
     */
    public static function bars(string $character): array
    {
        $index = self::indexOf($character);

        return Patterns::bars(
            Patterns::TWO_OF_FOUR[intdiv($index, 6)],
            Patterns::TWO_OF_FOUR[$index % 6],
        );
    }

    /**
     * Whether every character of $data is in the alphabet.
     *
     * An empty string is not: no four-state symbology draws an empty symbol.
     */
    public static function covers(string $data): bool
    {
        return $data !== '' && strspn($data, self::CHARACTERS) === \strlen($data);
    }
}
