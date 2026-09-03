<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Rm4scc;

use CrazyGoat\ScanMePHP\Generator\FourState\Alphabet;
use CrazyGoat\ScanMePHP\Generator\FourState\State;

/**
 * What RM4SCC adds to the family's alphabet.
 *
 * The thirty-six characters themselves are {@see Alphabet}'s — KIX draws the
 * same bars for the same character, so the derivation lives once. What is here
 * is the part no other four-state code shares: a start bar, a stop bar, and a
 * check character.
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

    /** The check character for an already-normalised payload. */
    public static function checkCharacter(string $data): string
    {
        $ascenders = 0;
        $descenders = 0;

        foreach (str_split($data) as $character) {
            $index = Alphabet::indexOf($character);

            $ascenders += intdiv($index, 6) + 1;
            $descenders += $index % 6 + 1;
        }

        return Alphabet::CHARACTERS[6 * (($ascenders - 1) % 6) + ($descenders - 1) % 6];
    }
}
