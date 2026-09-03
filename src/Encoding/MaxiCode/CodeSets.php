<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\MaxiCode;

/**
 * MaxiCode's five code sets, and the codes that move between them.
 *
 * Between them the five sets carry all 256 byte values, which is unusual: this
 * is the only symbology here that reaches every byte without a binary mode.
 * Sets A and B hold the printable ASCII repertoire split by case, and C, D and
 * E divide up the rest — the upper half of Latin-1 and the control characters.
 * Every table below was measured against an independent encoder, byte by byte,
 * each byte placed inside a run of characters from the set being probed so that
 * the answer could not be a shift into some other set.
 *
 * The switching codes are the part worth stating carefully, because they are
 * not symmetric:
 *
 *  - **60, 61 and 62 shift** the next single character into C, D or E, from any
 *    set, and **doubled** they latch into it.
 *  - **63 latches between A and B** — from A it means B and from B it means A —
 *    and from C, D or E it always means B.
 *  - **58 latches to A**, but only from C, D and E. In A it is a colon and in B
 *    it does nothing, so a run of upper-case letters reached from C costs a
 *    latch each way rather than a shift.
 *  - **B alone can shift two or three characters at once**, with 56 and 57. It
 *    is the only multi-character shift in the symbology, and it is what makes a
 *    capital or two inside a lower-case run cheaper than latching out and back.
 *
 * Padding is set-dependent, and getting it wrong emits a real character rather
 * than nothing: 33 is the pad in A and B, 28 is the pad in E, and C and D have
 * none at all — in those two, 33 is a printable character, so the only way to
 * pad is to latch to A first.
 */
final class CodeSets
{
    public const A = 0;
    public const B = 1;
    public const C = 2;
    public const D = 3;
    public const E = 4;

    public const COUNT = 5;

    /** Reads the next nine digits as a thirty-bit number; see {@see Compaction}. */
    public const NUMERIC_LATCH = 31;

    public const LATCH_A = 58;

    public const LATCH_B = 63;

    public const SHIFT_B = 59;

    public const SHIFT_A = 59;

    /** Shift the next two, then the next three, characters into A. Set B only. */
    public const SHIFT_TWO_A = 56;

    public const SHIFT_THREE_A = 57;

    /** Shifts into C, D and E, from any set; doubled, they latch. */
    public const SHIFT = [self::C => 60, self::D => 61, self::E => 62];

    /**
     * Every value each set carries directly, as value => byte.
     *
     * @var array<int, array<int, int>>
     */
    private const CHARACTERS = [
        self::A => [
            0 => 0x0D, 1 => 0x41, 2 => 0x42, 3 => 0x43, 4 => 0x44, 5 => 0x45, 6 => 0x46, 7 => 0x47,
            8 => 0x48, 9 => 0x49, 10 => 0x4A, 11 => 0x4B, 12 => 0x4C, 13 => 0x4D, 14 => 0x4E, 15 => 0x4F,
            16 => 0x50, 17 => 0x51, 18 => 0x52, 19 => 0x53, 20 => 0x54, 21 => 0x55, 22 => 0x56, 23 => 0x57,
            24 => 0x58, 25 => 0x59, 26 => 0x5A, 28 => 0x1C, 29 => 0x1D, 30 => 0x1E, 32 => 0x20, 34 => 0x22,
            35 => 0x23, 36 => 0x24, 37 => 0x25, 38 => 0x26, 39 => 0x27, 40 => 0x28, 41 => 0x29, 42 => 0x2A,
            43 => 0x2B, 44 => 0x2C, 45 => 0x2D, 46 => 0x2E, 47 => 0x2F, 48 => 0x30, 49 => 0x31, 50 => 0x32,
            51 => 0x33, 52 => 0x34, 53 => 0x35, 54 => 0x36, 55 => 0x37, 56 => 0x38, 57 => 0x39, 58 => 0x3A,
        ],
        self::B => [
            0 => 0x60, 1 => 0x61, 2 => 0x62, 3 => 0x63, 4 => 0x64, 5 => 0x65, 6 => 0x66, 7 => 0x67,
            8 => 0x68, 9 => 0x69, 10 => 0x6A, 11 => 0x6B, 12 => 0x6C, 13 => 0x6D, 14 => 0x6E, 15 => 0x6F,
            16 => 0x70, 17 => 0x71, 18 => 0x72, 19 => 0x73, 20 => 0x74, 21 => 0x75, 22 => 0x76, 23 => 0x77,
            24 => 0x78, 25 => 0x79, 26 => 0x7A, 28 => 0x1C, 29 => 0x1D, 30 => 0x1E, 32 => 0x7B, 34 => 0x7D,
            35 => 0x7E, 36 => 0x7F, 37 => 0x3B, 38 => 0x3C, 39 => 0x3D, 40 => 0x3E, 41 => 0x3F, 42 => 0x5B,
            43 => 0x5C, 44 => 0x5D, 45 => 0x5E, 46 => 0x5F, 47 => 0x20, 48 => 0x2C, 49 => 0x2E, 50 => 0x2F,
            51 => 0x3A, 52 => 0x40, 53 => 0x21, 54 => 0x7C,
        ],
        self::C => [
            0 => 0xC0, 1 => 0xC1, 2 => 0xC2, 3 => 0xC3, 4 => 0xC4, 5 => 0xC5, 6 => 0xC6, 7 => 0xC7,
            8 => 0xC8, 9 => 0xC9, 10 => 0xCA, 11 => 0xCB, 12 => 0xCC, 13 => 0xCD, 14 => 0xCE, 15 => 0xCF,
            16 => 0xD0, 17 => 0xD1, 18 => 0xD2, 19 => 0xD3, 20 => 0xD4, 21 => 0xD5, 22 => 0xD6, 23 => 0xD7,
            24 => 0xD8, 25 => 0xD9, 26 => 0xDA, 28 => 0x1C, 29 => 0x1D, 30 => 0x1E, 32 => 0xDB, 33 => 0xDC,
            34 => 0xDD, 35 => 0xDE, 36 => 0xDF, 37 => 0xAA, 38 => 0xAC, 39 => 0xB1, 40 => 0xB2, 41 => 0xB3,
            42 => 0xB5, 43 => 0xB9, 44 => 0xBA, 45 => 0xBC, 46 => 0xBD, 47 => 0xBE, 48 => 0x80, 49 => 0x81,
            50 => 0x82, 51 => 0x83, 52 => 0x84, 53 => 0x85, 54 => 0x86, 55 => 0x87, 56 => 0x88, 57 => 0x89,
            59 => 0x20,
        ],
        self::D => [
            0 => 0xE0, 1 => 0xE1, 2 => 0xE2, 3 => 0xE3, 4 => 0xE4, 5 => 0xE5, 6 => 0xE6, 7 => 0xE7,
            8 => 0xE8, 9 => 0xE9, 10 => 0xEA, 11 => 0xEB, 12 => 0xEC, 13 => 0xED, 14 => 0xEE, 15 => 0xEF,
            16 => 0xF0, 17 => 0xF1, 18 => 0xF2, 19 => 0xF3, 20 => 0xF4, 21 => 0xF5, 22 => 0xF6, 23 => 0xF7,
            24 => 0xF8, 25 => 0xF9, 26 => 0xFA, 28 => 0x1C, 29 => 0x1D, 30 => 0x1E, 32 => 0xFB, 33 => 0xFC,
            34 => 0xFD, 35 => 0xFE, 36 => 0xFF, 37 => 0xA1, 38 => 0xA8, 39 => 0xAB, 40 => 0xAF, 41 => 0xB0,
            42 => 0xB4, 43 => 0xB7, 44 => 0xB8, 45 => 0xBB, 46 => 0xBF, 47 => 0x8A, 48 => 0x8B, 49 => 0x8C,
            50 => 0x8D, 51 => 0x8E, 52 => 0x8F, 53 => 0x90, 54 => 0x91, 55 => 0x92, 56 => 0x93, 57 => 0x94,
            59 => 0x20,
        ],
        self::E => [
            0 => 0x00, 1 => 0x01, 2 => 0x02, 3 => 0x03, 4 => 0x04, 5 => 0x05, 6 => 0x06, 7 => 0x07,
            8 => 0x08, 9 => 0x09, 10 => 0x0A, 11 => 0x0B, 12 => 0x0C, 13 => 0x0D, 14 => 0x0E, 15 => 0x0F,
            16 => 0x10, 17 => 0x11, 18 => 0x12, 19 => 0x13, 20 => 0x14, 21 => 0x15, 22 => 0x16, 23 => 0x17,
            24 => 0x18, 25 => 0x19, 26 => 0x1A, 30 => 0x1B, 32 => 0x1C, 33 => 0x1D, 34 => 0x1E, 35 => 0x1F,
            36 => 0x9F, 37 => 0xA0, 38 => 0xA2, 39 => 0xA3, 40 => 0xA4, 41 => 0xA5, 42 => 0xA6, 43 => 0xA7,
            44 => 0xA9, 45 => 0xAD, 46 => 0xAE, 47 => 0xB6, 48 => 0x95, 49 => 0x96, 50 => 0x97, 51 => 0x98,
            52 => 0x99, 53 => 0x9A, 54 => 0x9B, 55 => 0x9C, 56 => 0x9D, 57 => 0x9E, 59 => 0x20,
        ],
    ];

    /**
     * The pad codeword of each set, where the set has one.
     *
     * @var array<int, int>
     */
    private const PAD = [self::A => 33, self::B => 33, self::E => 28];

    /** @var array<int, array<int, int>>|null byte => set => value */
    private static ?array $byByte = null;

    /** The value $set writes $byte with, or null if it does not carry it. */
    public static function value(int $set, string $byte): ?int
    {
        return self::index()[\ord($byte)][$set] ?? null;
    }

    /**
     * Every set that carries $byte, as set => value.
     *
     * @return array<int, int>
     */
    public static function sets(string $byte): array
    {
        return self::index()[\ord($byte)] ?? [];
    }

    /** The byte $set writes with $value, or null where the value is a control code. */
    public static function character(int $set, int $value): ?int
    {
        return self::CHARACTERS[$set][$value] ?? null;
    }

    /** The pad codeword of $set, or null when the set has none. */
    public static function pad(int $set): ?int
    {
        return self::PAD[$set] ?? null;
    }

    /**
     * The codewords that latch from $from into $to.
     *
     * @return list<int>
     */
    public static function latch(int $from, int $to): array
    {
        if ($to === self::A) {
            return $from === self::B ? [self::LATCH_B] : [self::LATCH_A];
        }

        if ($to === self::B) {
            return [self::LATCH_B];
        }

        return [self::SHIFT[$to], self::SHIFT[$to]];
    }

    /**
     * The codeword that shifts one character from $from into $to, if one exists.
     *
     * C, D and E have no shift into A or B: from there, a single upper-case
     * letter costs a latch out and a latch back.
     */
    public static function shift(int $from, int $to): ?int
    {
        if ($to === self::A) {
            return $from === self::B ? self::SHIFT_A : null;
        }

        if ($to === self::B) {
            return $from === self::A ? self::SHIFT_B : null;
        }

        return $from === $to ? null : self::SHIFT[$to];
    }

    /** @return array<int, array<int, int>> */
    private static function index(): array
    {
        if (self::$byByte !== null) {
            return self::$byByte;
        }

        $index = [];
        foreach (self::CHARACTERS as $set => $characters) {
            foreach ($characters as $value => $byte) {
                $index[$byte][$set] = $value;
            }
        }

        return self::$byByte = $index;
    }
}
