<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\IntelligentMail;

/**
 * The 1365 thirteen-bit characters a codeword is drawn as.
 *
 * USPS-B-3200 prints this as two tables of numbers, and it is not one: it is
 * an enumeration, the same way {@see \CrazyGoat\ScanMePHP\Generator\FourState\Alphabet}
 * is. Every thirteen-bit pattern with exactly five bits set comes first — all
 * 1287 of them — and then every pattern with exactly two, all 78. A codeword
 * runs from 0 to 1364, which is exactly how many that is.
 *
 * The order inside each group is the only part that has to be said out loud. A
 * pattern and its mirror image sit next to each other, counting up from the
 * bottom of the table; the patterns that are their own mirror image are filled
 * in from the top, downwards. That is what pairs a symbol with its reverse, so
 * that a symbol read back to front decodes to a different valid codeword
 * rather than to a plausible wrong one — which is what lets a reader work out
 * which way round the mail piece went through the machine.
 *
 * Written out as arithmetic rather than as 1365 constants: two thousand digits
 * transcribed by hand is two thousand chances at a typo, and a typo here
 * relabels one codeword in a way no short payload would ever reach.
 */
final class CharacterTable
{
    /** Bits in one character, one per bar it will be spread across. */
    public const CHARACTER_BITS = 13;

    /** Characters in the table, and so the number of codeword values. */
    public const LENGTH = 1365;

    /** All thirteen-bit patterns with five bits set. */
    private const FIVE_OF_THIRTEEN = 1287;

    /** All thirteen-bit patterns with two bits set. */
    private const TWO_OF_THIRTEEN = 78;

    /** @var list<int>|null */
    private static ?array $characters = null;

    /** The character a codeword is drawn as. */
    public static function pattern(int $codeword): int
    {
        return self::all()[$codeword];
    }

    /** @return list<int> */
    public static function all(): array
    {
        return self::$characters ??= [
            ...self::ofWeight(5, self::FIVE_OF_THIRTEEN),
            ...self::ofWeight(2, self::TWO_OF_THIRTEEN),
        ];
    }

    /** A pattern read back to front, which is the same bar sequence reversed. */
    public static function mirror(int $pattern): int
    {
        $mirrored = 0;

        for ($bit = 0; $bit < self::CHARACTER_BITS; $bit++) {
            $mirrored = ($mirrored << 1) | (($pattern >> $bit) & 1);
        }

        return $mirrored;
    }

    /**
     * Every pattern with exactly $bitsSet bits set, in the standard's order.
     *
     * @return list<int>
     */
    private static function ofWeight(int $bitsSet, int $length): array
    {
        $table = array_fill(0, $length, 0);
        $lower = 0;
        $upper = $length - 1;

        for ($pattern = 0; $pattern < (1 << self::CHARACTER_BITS); $pattern++) {
            if (substr_count(decbin($pattern), '1') !== $bitsSet) {
                continue;
            }

            $mirrored = self::mirror($pattern);
            if ($mirrored < $pattern) {
                // Its mirror image already placed the pair.
                continue;
            }

            if ($mirrored === $pattern) {
                $table[$upper--] = $pattern;
                continue;
            }

            $table[$lower++] = $pattern;
            $table[$lower++] = $mirrored;
        }

        return $table;
    }
}
