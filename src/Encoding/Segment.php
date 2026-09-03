<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding;

use CrazyGoat\ScanMePHP\Exception\InvalidDataException;

/**
 * How QR packs a run of characters into bits, without saying which symbol the
 * bits are going into.
 *
 * QR and Micro QR disagree about every part of a segment's *header* — the mode
 * indicator is four bits in QR and zero to three in Micro QR, and the character
 * count is a different width in each of the four Micro QR versions — but they
 * agree exactly about the payload underneath it. Three digits are ten bits in
 * both, two alphanumeric characters are eleven bits in both, a byte is a byte.
 *
 * So the payload rules live here and the headers live with the symbol that
 * knows its own widths. That split is the point: the alphanumeric alphabet in
 * particular is a table with a well-known way of going wrong (space, dollar,
 * percent, asterisk, plus, minus, dot, slash, colon — in that order, after the
 * digits and letters), and a second copy of it would be a second chance to get
 * it wrong that no test comparing QR against its own fixture would ever catch.
 *
 * @internal Shared encoding primitive, not part of the public API.
 */
final class Segment
{
    /** ISO/IEC 18004:2015 Table 5, in index order — the index *is* the value. */
    public const ALPHANUMERIC = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';

    /** Bits a run of $count digits costs, header excluded. */
    public static function numericBits(int $count): int
    {
        return intdiv($count, 3) * 10 + match ($count % 3) {
            1 => 4,
            2 => 7,
            default => 0,
        };
    }

    /** Bits a run of $count alphanumeric characters costs, header excluded. */
    public static function alphanumericBits(int $count): int
    {
        return intdiv($count, 2) * 11 + ($count % 2) * 6;
    }

    /** Bits a run of $count bytes costs, header excluded. */
    public static function byteBits(int $count): int
    {
        return $count * 8;
    }

    public static function isNumeric(string $data): bool
    {
        return $data !== '' && strspn($data, '0123456789') === \strlen($data);
    }

    public static function isAlphanumeric(string $data): bool
    {
        return $data !== '' && strspn($data, self::ALPHANUMERIC) === \strlen($data);
    }

    /**
     * Digits in groups of three: ten bits a group, seven for a trailing pair,
     * four for a trailing single.
     *
     * @return list<int>
     */
    public static function numeric(string $data): array
    {
        $bits = [];
        $length = \strlen($data);

        for ($i = 0; $i < $length; $i += 3) {
            $group = substr($data, $i, 3);
            if (strspn($group, '0123456789') !== \strlen($group)) {
                throw InvalidDataException::incompatibleMode('Numeric', $group);
            }

            $width = match (\strlen($group)) {
                3 => 10,
                2 => 7,
                default => 4,
            };
            $bits = [...$bits, ...self::pack((int) $group, $width)];
        }

        return $bits;
    }

    /**
     * Characters in pairs: eleven bits a pair as `first * 45 + second`, six
     * bits for a trailing single.
     *
     * @return list<int>
     */
    public static function alphanumeric(string $data): array
    {
        $bits = [];
        $length = \strlen($data);

        for ($i = 0; $i < $length; $i += 2) {
            $first = strpos(self::ALPHANUMERIC, $data[$i]);
            if ($first === false) {
                throw InvalidDataException::incompatibleMode('Alphanumeric', $data);
            }

            if ($i + 1 >= $length) {
                $bits = [...$bits, ...self::pack($first, 6)];
                break;
            }

            $second = strpos(self::ALPHANUMERIC, $data[$i + 1]);
            if ($second === false) {
                throw InvalidDataException::incompatibleMode('Alphanumeric', $data);
            }

            $bits = [...$bits, ...self::pack($first * 45 + $second, 11)];
        }

        return $bits;
    }

    /** @return list<int> */
    public static function byte(string $data): array
    {
        $bits = [];
        $length = \strlen($data);

        for ($i = 0; $i < $length; $i++) {
            $bits = [...$bits, ...self::pack(\ord($data[$i]), 8)];
        }

        return $bits;
    }

    /**
     * A value as $width bits, most significant first.
     *
     * @return list<int>
     */
    public static function pack(int $value, int $width): array
    {
        $bits = [];
        for ($i = $width - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }

        return $bits;
    }
}
