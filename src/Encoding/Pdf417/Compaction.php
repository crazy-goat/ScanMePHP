<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Pdf417;

/**
 * The arithmetic behind PDF417's numeric and byte compaction.
 *
 * Both are base conversions into base 900, and both need numbers wider than an
 * integer: a numeric group is up to forty-four digits with a guard digit in
 * front, so forty-five decimal digits, and a byte group is six bytes, so
 * forty-eight bits before the guard. The forty-five digit case is far past what
 * any PHP integer holds and this library has no dependencies, so the division
 * is done by hand — long division over a digit string, which is short, exact
 * and needs nothing installed.
 *
 * The guard digit is what makes numeric compaction lossless: without it, a
 * payload starting with a zero would convert back to a shorter string. It also
 * has a pleasant consequence, which {@see codewordsForDigits()} relies on.
 *
 * @internal Shared encoding primitive, not part of the public API.
 */
final class Compaction
{
    /** ISO/IEC 15438 §5.4.2.4: numeric compaction works in groups of 44. */
    public const NUMERIC_GROUP = 44;

    /** Six bytes become five codewords; §5.4.2.3. */
    public const BYTE_GROUP = 6;

    public const BYTE_GROUP_CODEWORDS = 5;

    /**
     * The codewords for a run of digits, grouped from the left.
     *
     * @return list<int>
     */
    public static function numeric(string $digits): array
    {
        $codewords = [];
        for ($offset = 0; $offset < \strlen($digits); $offset += self::NUMERIC_GROUP) {
            $group = '1' . substr($digits, $offset, self::NUMERIC_GROUP);
            $digitsOfGroup = [];

            while ($group !== '' && $group !== '0') {
                [$group, $remainder] = self::divide($group, 900);
                $digitsOfGroup[] = $remainder;
            }

            $codewords = [...$codewords, ...array_reverse($digitsOfGroup)];
        }

        return $codewords;
    }

    /**
     * How many codewords a group of $length digits takes.
     *
     * Exactly ceil((length + 1) / 3), and — the part worth knowing — the same
     * whatever the digits are. That is not obvious: the codeword count is the
     * number of base-900 digits of a number, which in general depends on the
     * number. The guard digit pins the value into [10^n, 2 * 10^n), and no
     * power of 900 falls inside any of those windows for a group of one to
     * forty-four digits, which is checked for every length in
     * Pdf417CompactionTest rather than assumed.
     *
     * Having it in closed form is what lets the encoder's optimiser stay exact:
     * the cost of a numeric run is known without converting it.
     */
    public static function codewordsForDigits(int $length): int
    {
        if ($length <= 0) {
            return 0;
        }

        return intdiv($length + 3, 3);
    }

    /**
     * The codewords for a run of bytes.
     *
     * Groups of six become five codewords by base conversion; a tail shorter
     * than six is not converted at all but written one byte per codeword,
     * which costs more per byte and is why the group size matters to the
     * optimiser.
     *
     * @return list<int>
     */
    public static function bytes(string $data): array
    {
        $codewords = [];
        $length = \strlen($data);
        $full = intdiv($length, self::BYTE_GROUP) * self::BYTE_GROUP;

        for ($offset = 0; $offset < $full; $offset += self::BYTE_GROUP) {
            $group = [];
            $number = '0';
            for ($i = 0; $i < self::BYTE_GROUP; $i++) {
                $number = self::multiplyAdd($number, 256, \ord($data[$offset + $i]));
            }
            for ($i = 0; $i < self::BYTE_GROUP_CODEWORDS; $i++) {
                [$number, $remainder] = self::divide($number, 900);
                $group[] = $remainder;
            }

            $codewords = [...$codewords, ...array_reverse($group)];
        }

        for ($offset = $full; $offset < $length; $offset++) {
            $codewords[] = \ord($data[$offset]);
        }

        return $codewords;
    }

    /** How many codewords a run of $length bytes takes. */
    public static function codewordsForBytes(int $length): int
    {
        return intdiv($length, self::BYTE_GROUP) * self::BYTE_GROUP_CODEWORDS
            + $length % self::BYTE_GROUP;
    }

    /**
     * $number divided by $divisor: the quotient as a digit string and the
     * remainder.
     *
     * @return array{string, int}
     */
    private static function divide(string $number, int $divisor): array
    {
        $quotient = '';
        $carry = 0;

        for ($i = 0; $i < \strlen($number); $i++) {
            $carry = $carry * 10 + (int) $number[$i];
            $quotient .= intdiv($carry, $divisor);
            $carry %= $divisor;
        }

        $quotient = ltrim($quotient, '0');

        return [$quotient === '' ? '0' : $quotient, $carry];
    }

    /** $number * $factor + $addend, as a digit string. */
    private static function multiplyAdd(string $number, int $factor, int $addend): string
    {
        $digits = array_reverse(str_split($number));
        $carry = $addend;
        $result = [];

        foreach ($digits as $digit) {
            $value = (int) $digit * $factor + $carry;
            $result[] = $value % 10;
            $carry = intdiv($value, 10);
        }
        while ($carry > 0) {
            $result[] = $carry % 10;
            $carry = intdiv($carry, 10);
        }

        $number = implode('', array_reverse($result));

        return ltrim($number, '0') ?: '0';
    }
}
