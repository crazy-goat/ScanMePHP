<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Pdf417;

/**
 * Reed–Solomon over GF(929), the third such implementation here and the only
 * one that is not a binary field.
 *
 * The other two share a shape: {@see \CrazyGoat\ScanMePHP\Encoding\ReedSolomon256}
 * is tuned for GF(256) and {@see \CrazyGoat\ScanMePHP\Encoding\Aztec\ReedSolomonGf2m}
 * generalises to any GF(2^m). Neither generalises to this one, and not for want
 * of trying: over GF(2^m) addition *is* exclusive-or, which is why both of them
 * add with `^`. 929 is prime, so addition here is addition, and every `^` in
 * those classes would have to become an addition modulo 929. That is not a
 * widening of either class, it is a different arithmetic, so it is a different
 * class.
 *
 * What carries over is the structure: build the generator polynomial as the
 * product of (x - 3^i), then divide the message by it and keep the remainder.
 * Three is a primitive root modulo 929, so its powers run through every
 * non-zero element, which is the only property the construction needs.
 *
 * The sign convention is the part worth stating, because getting it wrong
 * produces plausible-looking check codewords that no reader accepts: PDF417's
 * check codewords are the *negated* remainder coefficients. The division below
 * therefore subtracts as it goes and negates at the end.
 *
 * @internal Shared encoding primitive, not part of the public API.
 */
final class ReedSolomonGf929
{
    /** ISO/IEC 15438 §5.5. The field is the integers modulo this prime. */
    public const MODULUS = 929;

    /** A primitive root modulo 929, so its powers enumerate the whole group. */
    public const ROOT = 3;

    /** @var array<int, list<int>> Generator polynomials by degree */
    private array $generators = [];

    /**
     * The $checkWords check codewords for $data, in the order they are placed.
     *
     * @param list<int> $data
     * @return list<int>
     */
    public function encode(array $data, int $checkWords): array
    {
        if ($checkWords === 0) {
            return [];
        }

        $generator = $this->generatorPolynomial($checkWords);
        $remainder = array_fill(0, $checkWords, 0);

        foreach ($data as $word) {
            $factor = ($word + $remainder[0]) % self::MODULUS;
            array_shift($remainder);
            $remainder[] = 0;

            if ($factor === 0) {
                continue;
            }

            for ($i = 0; $i < $checkWords; $i++) {
                $coefficient = $generator[$i + 1];
                if ($coefficient !== 0) {
                    $remainder[$i] = ($remainder[$i] - $factor * $coefficient) % self::MODULUS;
                }
            }
        }

        return array_map(
            static fn (int $coefficient): int => (self::MODULUS - $coefficient % self::MODULUS) % self::MODULUS,
            $remainder,
        );
    }

    /**
     * The product of (x - 3^i) for i = 1..$degree, lowest degree first.
     *
     * @return list<int>
     */
    private function generatorPolynomial(int $degree): array
    {
        if (isset($this->generators[$degree])) {
            return $this->generators[$degree];
        }

        $polynomial = [1];
        for ($i = 1; $i <= $degree; $i++) {
            $root = $this->power($i);
            $next = array_fill(0, \count($polynomial) + 1, 0);

            foreach ($polynomial as $j => $coefficient) {
                $next[$j] = ($next[$j] + $coefficient) % self::MODULUS;
                $next[$j + 1] = ($next[$j + 1] - $coefficient * $root) % self::MODULUS;
            }

            $polynomial = array_map(
                static fn (int $coefficient): int => ($coefficient % self::MODULUS + self::MODULUS) % self::MODULUS,
                $next,
            );
        }

        return $this->generators[$degree] = $polynomial;
    }

    private function power(int $exponent): int
    {
        $result = 1;
        for ($i = 0; $i < $exponent; $i++) {
            $result = $result * self::ROOT % self::MODULUS;
        }

        return $result;
    }
}
