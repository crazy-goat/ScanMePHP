<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Rm4scc;

use CrazyGoat\ScanMePHP\Generator\FourState\Patterns;
use CrazyGoat\ScanMePHP\Generator\FourState\State;

/**
 * RM4SCC's thirty-six characters, and the one that checks them.
 *
 * There is no table here, which is the whole point. A character is a pair of
 * two-of-four nibbles — one saying which of its four bars reach up, one saying
 * which reach down — and its position in the alphabet is the pair read as a
 * base-six number: `index = ascenders * 6 + descenders`, digits first, then
 * letters. Every published RM4SCC table is that arithmetic written out, and
 * writing it out is how a table acquires a typo.
 *
 * The check character is the same arithmetic done twice more. Each nibble is
 * worth its position plus one, so 1 to 6 rather than 0 to 5; the check
 * character's ascender nibble is the sum of everyone else's brought back into
 * that range, and its descender nibble likewise. The "plus one" is not
 * decoration — modulo six over 0 to 5 and over 1 to 6 disagree exactly when
 * the sum is a multiple of six, which is the case a symbol nobody tested with
 * a six-character postcode would fail on.
 */
final class Characters
{
    /** Six times six characters, in the order the nibble pairs enumerate them. */
    public const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * The longest payload the reference encoder will draw.
     *
     * Royal Mail's own symbols are a postcode and a delivery point suffix,
     * well under this, and the symbology itself sets no length. Fifty is where
     * zint stops, and since zint is the only encoder that can check ours,
     * anything past it would be output nothing has ever agreed with.
     */
    public const MAX_LENGTH = 50;

    /** Ascender: what a scanner uses to find the start of the symbol. */
    public const START = State::Ascender;

    /** Full bar: the only state that cannot be mistaken for a truncated one. */
    public const STOP = State::Full;

    /**
     * The four bars of one character.
     *
     * @return list<State>
     * @throws \InvalidArgumentException when the character is not in the alphabet
     */
    public static function bars(string $character): array
    {
        $index = strpos(self::ALPHABET, $character);
        if ($index === false) {
            throw new \InvalidArgumentException(sprintf(
                'RM4SCC carries digits and capital letters only, got "%s"',
                $character
            ));
        }

        return Patterns::bars(
            Patterns::TWO_OF_FOUR[intdiv($index, 6)],
            Patterns::TWO_OF_FOUR[$index % 6],
        );
    }

    /** The check character for an already-normalised payload. */
    public static function checkCharacter(string $data): string
    {
        $ascenders = 0;
        $descenders = 0;

        foreach (str_split($data) as $character) {
            $index = strpos(self::ALPHABET, $character);
            \assert($index !== false, 'the payload was normalised before it got here');

            $ascenders += intdiv($index, 6) + 1;
            $descenders += $index % 6 + 1;
        }

        return self::ALPHABET[6 * (($ascenders - 1) % 6) + ($descenders - 1) % 6];
    }
}
