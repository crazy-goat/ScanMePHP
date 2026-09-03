<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\IntelligentMail;

/**
 * A payload on its way from digits to ten characters.
 *
 * Four steps, and every one of them is arithmetic:
 *
 *   1. **One number.** The routing code is offset past every shorter routing
 *      code so that a missing one and a zero one differ, then the twenty
 *      tracking digits are appended by place value. 102 bits.
 *   2. **A frame check sequence.** Eleven bits of CRC over those 102, with the
 *      generator polynomial USPS-B-3200 gives. This is the symbology's error
 *      *detection*; there is no correction anywhere in it.
 *   3. **Ten codewords.** The number in a mixed radix — 636 for the last,
 *      1365 for the eight after it, whatever is left for the first.
 *   4. **Ten characters.** Each codeword becomes a thirteen-bit pattern, and
 *      each of the eleven check bits gets somewhere to live: ten of them
 *      invert a character, and the eleventh is spent twice over, doubling the
 *      last codeword and adding 659 to the first.
 *
 * That last step is the one worth reading twice. The check bits are not
 * appended to the data — they are folded into it, which is why a symbol has no
 * check character to point at and why a reader recovers them by undoing the
 * inversions. Nine of the codewords are worth 1365; the last is worth 636 and
 * then doubled, so it carries an odd number's worth of information and one
 * check bit in the space where its low bit would have been.
 */
final class Codewords
{
    /** Codewords in every symbol. */
    public const COUNT = 10;

    /** Radix of the eight middle codewords, and the size of the character table. */
    public const RADIX = 1365;

    /** Radix of the last codeword: half of 1365, rounded down, because its low bit is spoken for. */
    public const LAST_RADIX = 636;

    /** What the first codeword can reach before the eleventh check bit is added to it. */
    public const FIRST_RADIX = 659;

    /** The CRC-11 generator polynomial, x^11 + x^10 + x^9 + x^8 + x^5 + x^4 + x^2 + 1. */
    private const GENERATOR = 0x0F35;

    /** Bits of frame check sequence. */
    private const CHECK_BITS = 11;

    /** Bits of the value the first byte carries: 102 is not a multiple of eight. */
    private const LEADING_BITS = 6;

    /**
     * How much each routing code length is offset by.
     *
     * A routing code is not a number, it is one of four different things that
     * happen to be written as digits. Offsetting each length past the count of
     * every shorter one keeps them apart: nothing is 0, a ZIP is 1 to 100000,
     * a ZIP+4 starts where those end, and so on.
     */
    private const ROUTING_OFFSET = [0 => 0, 5 => 1, 9 => 100001, 11 => 1000100001];

    /** The payload as one 102-bit number. */
    public static function value(Payload $payload): Number
    {
        $routing = $payload->routing;
        $number = Number::zero();

        foreach (str_split($routing === '' ? '0' : $routing) as $digit) {
            $number = $number->mulAdd(10, (int) $digit);
        }

        $number = $number->mulAdd(1, self::ROUTING_OFFSET[\strlen($routing)]);

        // The barcode identifier's second digit runs 0 to 4, so it is worth
        // five. Every other tracking digit is worth ten.
        $tracking = $payload->tracking;
        $number = $number->mulAdd(10, (int) $tracking[0]);
        $number = $number->mulAdd(5, (int) $tracking[1]);

        foreach (str_split(substr($tracking, 2)) as $digit) {
            $number = $number->mulAdd(10, (int) $digit);
        }

        return $number;
    }

    /** The eleven-bit frame check sequence over the value's 102 bits. */
    public static function frameCheck(Number $value): int
    {
        $fcs = (1 << self::CHECK_BITS) - 1;
        $top = 1 << (self::CHECK_BITS - 1);
        $mask = (1 << self::CHECK_BITS) - 1;

        foreach ($value->bytes() as $index => $byte) {
            $bits = $index === 0 ? self::LEADING_BITS : 8;
            $shifted = $byte << (self::CHECK_BITS - $bits);

            for ($bit = 0; $bit < $bits; $bit++) {
                $fcs = (($fcs ^ $shifted) & $top) !== 0
                    ? (($fcs << 1) ^ self::GENERATOR) & $mask
                    : ($fcs << 1) & $mask;

                $shifted <<= 1;
            }
        }

        return $fcs;
    }

    /**
     * The ten codewords, with the eleventh check bit already folded in.
     *
     * @return list<int>
     */
    public static function of(Number $value, int $frameCheck): array
    {
        $codewords = array_fill(0, self::COUNT, 0);

        [$value, $codewords[9]] = $value->divMod(self::LAST_RADIX);
        for ($index = self::COUNT - 2; $index > 0; $index--) {
            [$value, $codewords[$index]] = $value->divMod(self::RADIX);
        }

        $codewords[0] = $value->toInt();

        // The eleventh check bit, in the two places the radices left for it.
        $codewords[9] *= 2;
        if (($frameCheck & (1 << (self::CHECK_BITS - 1))) !== 0) {
            $codewords[0] += self::FIRST_RADIX;
        }

        return $codewords;
    }

    /**
     * The ten characters, each inverted when its own check bit is set.
     *
     * Inversion rather than a separate field is what makes the check bits
     * unremovable: a character and its complement are both legal patterns, and
     * only the other ten bits say which reading is the right one.
     *
     * @param list<int> $codewords
     * @return list<int>
     */
    public static function characters(array $codewords, int $frameCheck): array
    {
        $all = (1 << CharacterTable::CHARACTER_BITS) - 1;
        $characters = [];

        foreach ($codewords as $index => $codeword) {
            $pattern = CharacterTable::pattern($codeword);
            $characters[] = ($frameCheck & (1 << $index)) !== 0 ? $pattern ^ $all : $pattern;
        }

        return $characters;
    }
}
