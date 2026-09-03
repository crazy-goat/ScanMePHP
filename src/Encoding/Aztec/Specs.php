<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Aztec;

/**
 * The numbers that describe an Aztec symbol, as functions rather than tables.
 *
 * Aztec is unusually regular: everything about a symbol follows from two
 * things, the layer count and whether it is compact. There is no
 * version-by-version table like QR's, so a table here would only be a chance
 * to mistype one of thirty-six rows.
 *
 * The one formula that is not closed is the full symbol's size, because the
 * reference grid adds rows and how many rows it adds depends on the size. It
 * is a fixed point and it settles in two steps; tests/AztecReferenceTest.php
 * checks every one of the thirty-six sizes against an encoder we did not write.
 *
 * @internal Part of the Aztec encoding pipeline.
 */
final class Specs
{
    public const MAX_COMPACT_LAYERS = 4;

    public const MAX_FULL_LAYERS = 32;

    /**
     * The reference grid repeats every this many modules, measured from the
     * centre. Only full symbols have one; the largest compact symbol is 27
     * modules across and never reaches the first line.
     */
    private const GRID_PERIOD = 16;

    /**
     * The layer count and kind behind a module size.
     *
     * Sizes do not collide between the two kinds — compact stops at 27 and full
     * starts at 31 — so a size names one symbol and nothing else. That is why
     * callers pin a size rather than a layer count: a layer count of four is
     * two different symbols.
     *
     * @return array{int, bool} layers, then whether it is compact
     */
    public static function fromSize(int $size): array
    {
        for ($layers = 1; $layers <= self::MAX_COMPACT_LAYERS; $layers++) {
            if (self::size($layers, true) === $size) {
                return [$layers, true];
            }
        }
        for ($layers = 1; $layers <= self::MAX_FULL_LAYERS; $layers++) {
            if (self::size($layers, false) === $size) {
                return [$layers, false];
            }
        }

        throw new \InvalidArgumentException(sprintf('%d is not an Aztec symbol size', $size));
    }

    /**
     * Every size an Aztec symbol can be, ascending.
     *
     * @return list<int>
     */
    public static function sizes(): array
    {
        $sizes = [];
        for ($layers = 1; $layers <= self::MAX_COMPACT_LAYERS; $layers++) {
            $sizes[] = self::size($layers, true);
        }
        for ($layers = 4; $layers <= self::MAX_FULL_LAYERS; $layers++) {
            $sizes[] = self::size($layers, false);
        }

        return $sizes;
    }

    public static function size(int $layers, bool $compact): int
    {
        if ($compact) {
            return 4 * $layers + 11;
        }

        // Each grid line costs two modules, and adding them can push the
        // symbol past the next multiple, which is why this iterates instead of
        // multiplying once.
        $size = 4 * $layers + 15;
        while (true) {
            $withGrid = 4 * $layers + 15 + 2 * intdiv($size - 1, 2 * self::GRID_PERIOD);
            if ($withGrid === $size) {
                return $size;
            }
            $size = $withGrid;
        }
    }

    /**
     * How many bits wide a codeword is, which is also which Galois field the
     * error correction lives in.
     */
    public static function wordBits(int $layers, bool $compact): int
    {
        if ($layers <= 2) {
            return 6;
        }
        if ($compact || $layers <= 8) {
            return 8;
        }

        return $layers <= 22 ? 10 : 12;
    }

    /** Every bit position in the data spiral, error correction included. */
    public static function totalBits(int $layers, bool $compact): int
    {
        return ($compact ? 88 + 16 * $layers : 112 + 16 * $layers) * $layers;
    }

    /**
     * Codewords the spiral holds. The remainder of the division is genuinely
     * unused — a compact one-layer symbol has 104 bit positions and 17 six-bit
     * codewords, so two positions carry nothing.
     */
    public static function totalWords(int $layers, bool $compact): int
    {
        return intdiv(self::totalBits($layers, $compact), self::wordBits($layers, $compact));
    }

    /** Chebyshev radius of the ring the mode message sits in. */
    public static function modeRingRadius(bool $compact): int
    {
        return $compact ? 5 : 7;
    }

    /** Bits in the mode message: layers and word count, then check words. */
    public static function modeMessageBits(bool $compact): int
    {
        return $compact ? 28 : 40;
    }
}
