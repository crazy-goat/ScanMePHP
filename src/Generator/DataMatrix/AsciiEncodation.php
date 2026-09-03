<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataMatrix;

use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;

/**
 * ECC200 ASCII encodation (ISO/IEC 16022 §5.2.3).
 *
 * This is the base scheme and the only one implemented. It is complete — any
 * byte can be encoded, values above 127 through the Upper Shift escape — and
 * it compacts digit pairs into a single codeword, which is where most of the
 * real-world density is. The alternative schemes (C40, Text, X12, EDIFACT,
 * Base 256) would buy roughly a third off letter-heavy payloads and each needs
 * its own latch and unlatch bookkeeping, so they are deliberately absent
 * rather than half-implemented.
 *
 * @internal
 */
final class AsciiEncodation
{
    /** Codeword that escapes the next byte into the 128–255 range. */
    private const UPPER_SHIFT = 235;

    /**
     * FNC1. In the first position it marks the symbol as GS1 Data Matrix;
     * later it terminates an element string of variable length.
     *
     * It is a codeword rather than a byte, which is the whole difference
     * between GS1 here and GS1 in Code 128 — there it is a symbol character in
     * either set, here it is one of the 256 values ASCII encodation can emit.
     */
    private const FNC1 = 232;

    /** First pad codeword; the rest are randomised. */
    private const PAD = 129;

    /**
     * @return list<int> Data codewords, before padding
     */
    public static function encode(string $data): array
    {
        $codewords = [];
        $length = \strlen($data);

        for ($i = 0; $i < $length;) {
            if ($i + 1 < $length && self::isDigit($data[$i]) && self::isDigit($data[$i + 1])) {
                // A digit pair costs one codeword instead of two.
                $codewords[] = 130 + (int) substr($data, $i, 2);
                $i += 2;

                continue;
            }

            $byte = \ord($data[$i]);
            if ($byte < 128) {
                $codewords[] = $byte + 1;
            } else {
                $codewords[] = self::UPPER_SHIFT;
                $codewords[] = $byte - 128 + 1;
            }
            $i++;
        }

        return $codewords;
    }

    /**
     * The same, for a GS1 payload.
     *
     * One FNC1 codeword in front, which is what makes a reader announce ']d2'
     * and hand the data to a GS1 parser, and one for each separator inside.
     * The separators arrive as ASCII GS — byte for byte what a scanner reports
     * — because that is the form ElementString produces and the form
     * Code 128 carries, so one payload builder serves both.
     *
     * Digit pairs cannot straddle a separator, and do not need to be stopped
     * from doing so: GS is not a digit.
     *
     * @return list<int> Data codewords, before padding
     */
    public static function encodeGs1(string $payload): array
    {
        $codewords = [self::FNC1];

        foreach (explode(ElementString::SEPARATOR, $payload) as $index => $element) {
            if ($index > 0) {
                $codewords[] = self::FNC1;
            }

            foreach (self::encode($element) as $codeword) {
                $codewords[] = $codeword;
            }
        }

        return $codewords;
    }

    /**
     * Pad $codewords out to $capacity.
     *
     * Only the first pad is the plain pad codeword; the rest are randomised by
     * position (ISO/IEC 16022 §5.2.4.5). Runs of an identical codeword would
     * otherwise produce large uniform blocks of modules, which scanners lock
     * onto badly.
     *
     * @param list<int> $codewords
     * @return list<int>
     */
    public static function pad(array $codewords, int $capacity): array
    {
        $count = \count($codewords);
        if ($count > $capacity) {
            throw new \InvalidArgumentException(sprintf(
                'Data needs %d codewords but the symbol holds %d',
                $count,
                $capacity
            ));
        }

        if ($count === $capacity) {
            return $codewords;
        }

        $codewords[] = self::PAD;
        for ($position = $count + 2; $position <= $capacity; $position++) {
            $codewords[] = self::randomisedPad($position);
        }

        return $codewords;
    }

    /** The 253-state pad randomisation, for a one-based codeword position. */
    public static function randomisedPad(int $position): int
    {
        $pseudoRandom = ((149 * $position) % 253) + 1;
        $padded = self::PAD + $pseudoRandom;

        return $padded <= 254 ? $padded : $padded - 254;
    }

    private static function isDigit(string $character): bool
    {
        return $character >= '0' && $character <= '9';
    }
}
