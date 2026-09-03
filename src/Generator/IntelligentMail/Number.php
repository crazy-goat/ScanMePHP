<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\IntelligentMail;

/**
 * The 102-bit number an Intelligent Mail payload becomes.
 *
 * Thirty-one digits do not fit in a PHP integer and this library has no
 * dependencies to reach for, so the value is carried as thirteen bytes and the
 * two operations the symbology needs are done by hand. Both are the schoolbook
 * ones, and both are exact: multiply-and-add builds the number up out of the
 * payload's digits, divide-with-remainder takes it apart into codewords.
 *
 * Nothing here is general-purpose arithmetic. The multiplier and the divisor
 * are always small — 10, 5, 636, 1365 — which is what keeps every intermediate
 * inside a single integer and lets the carry be a plain `int` rather than a
 * second Number. A multiplier past 2^47 or so would silently lose precision,
 * so anything that overflows the thirteen bytes throws instead of wrapping:
 * the whole point of the class is that the value is never approximate.
 */
final class Number
{
    /** Thirteen bytes: 104 bits, the smallest whole number of them that holds 102. */
    public const BYTES = 13;

    /** @param list<int> $bytes most significant first */
    private function __construct(private readonly array $bytes)
    {
    }

    public static function zero(): self
    {
        return new self(array_fill(0, self::BYTES, 0));
    }

    /**
     * This number times $multiplier, plus $addend.
     *
     * @throws \LogicException when the result does not fit in 104 bits
     */
    public function mulAdd(int $multiplier, int $addend): self
    {
        $bytes = $this->bytes;
        $carry = $addend;

        for ($index = self::BYTES - 1; $index >= 0; $index--) {
            $carry += $bytes[$index] * $multiplier;
            $bytes[$index] = $carry & 0xFF;
            $carry >>= 8;
        }

        if ($carry !== 0) {
            throw new \LogicException('an Intelligent Mail value overflowed 104 bits');
        }

        return new self($bytes);
    }

    /**
     * The quotient and the remainder of dividing by $divisor.
     *
     * @return array{self, int}
     */
    public function divMod(int $divisor): array
    {
        $bytes = [];
        $remainder = 0;

        foreach ($this->bytes as $byte) {
            $current = ($remainder << 8) | $byte;
            $bytes[] = intdiv($current, $divisor);
            $remainder = $current % $divisor;
        }

        return [new self($bytes), $remainder];
    }

    /**
     * The number as an integer, for the part of it that is small enough.
     *
     * Used where the arithmetic has already divided the value down — the
     * leading codeword — and throws rather than truncating anywhere else.
     *
     * @throws \LogicException when the value is wider than a PHP integer
     */
    public function toInt(): int
    {
        $value = 0;

        foreach ($this->bytes as $byte) {
            if ($value > (\PHP_INT_MAX >> 8)) {
                throw new \LogicException('an Intelligent Mail value is too wide for an integer');
            }

            $value = ($value << 8) | $byte;
        }

        return $value;
    }

    /**
     * The number as thirteen bytes, most significant first.
     *
     * @return list<int>
     */
    public function bytes(): array
    {
        return $this->bytes;
    }
}
