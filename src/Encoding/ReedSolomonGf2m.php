<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding;

/**
 * Reed–Solomon over GF(2^m) for any m, which the symbologies whose codewords
 * are not bytes need.
 *
 * QR and Data Matrix each live in one field, so {@see ReedSolomon256} can be —
 * and is — tuned for GF(256): a factor table of exactly 256 rows and an inner
 * loop with no log lookups. Aztec uses five fields in the same symbol family,
 * chosen by how many layers the data needs, plus a sixth for the mode message,
 * and MaxiCode's codewords are six bits wide. Widening that tuned class to fit
 * would have cost the QR hot path for no gain here, since nothing about either
 * is hot, so this is a separate and deliberately plain implementation.
 *
 * All the fields are base α¹. Their primitive polynomials are the standards',
 * and they are not interchangeable: encoding ten-bit codewords in GF(256)
 * produces the right *number* of check words and all of them wrong.
 *
 * Addition in GF(2^m) is exclusive-or, which is why this class and
 * {@see ReedSolomon256} both add with ^ and why
 * {@see Pdf417\ReedSolomonGf929} — a prime field, not a wider binary one —
 * cannot share either of them.
 *
 * @internal Shared encoding primitive, not part of the public API.
 */
final class ReedSolomonGf2m
{
    /**
     * ISO/IEC 24778:2008 Table 4, plus GF(16) for Aztec's mode message.
     *
     * GF(64) is shared: Aztec reaches for it at one and two layers, MaxiCode
     * uses it for every codeword it has, and both standards name the same
     * primitive polynomial.
     */
    public const PRIMITIVE = [
        4 => 0b1_0011,               // x^4 + x + 1, Aztec's mode message
        6 => 0b100_0011,             // x^6 + x + 1, Aztec layers 1-2, all of MaxiCode
        8 => 0b1_0010_1101,          // x^8 + x^5 + x^3 + x^2 + 1, Aztec layers 3-8
        10 => 0b100_0000_1001,       // x^10 + x^3 + 1, Aztec layers 9-22
        12 => 0b1_0000_0110_1001,    // x^12 + x^6 + x^5 + x^3 + 1, Aztec layers 23-32
    ];

    private readonly int $order;

    /** @var list<int> Powers of α, doubled so exponents need no modulo */
    private array $exp = [];

    /** @var array<int, int> */
    private array $log = [];

    /** @var array<int, list<int>> Generator polynomials by degree */
    private array $generators = [];

    public function __construct(int $wordBits)
    {
        if (!isset(self::PRIMITIVE[$wordBits])) {
            throw new \InvalidArgumentException(sprintf(
                'There is no field of %d-bit codewords here; the sizes are %s',
                $wordBits,
                implode(', ', array_keys(self::PRIMITIVE)),
            ));
        }

        $this->order = 1 << $wordBits;
        $primitive = self::PRIMITIVE[$wordBits];

        $x = 1;
        for ($i = 0; $i < $this->order - 1; $i++) {
            $this->exp[$i] = $x;
            $this->log[$x] = $i;
            $x <<= 1;
            if (($x & $this->order) !== 0) {
                $x ^= $primitive;
            }
        }
        for ($i = $this->order - 1; $i < 2 * ($this->order - 1); $i++) {
            $this->exp[$i] = $this->exp[$i - ($this->order - 1)];
        }
    }

    /**
     * The $checkWords check codewords for $data, in order.
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
            $factor = $word ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;

            if ($factor === 0) {
                continue;
            }

            $factorLog = $this->log[$factor];
            for ($i = 0; $i < $checkWords; $i++) {
                $coefficient = $generator[$checkWords - 1 - $i];
                if ($coefficient !== 0) {
                    $remainder[$i] ^= $this->exp[$this->log[$coefficient] + $factorLog];
                }
            }
        }

        return $remainder;
    }

    /** @return list<int> Coefficients, lowest degree first */
    private function generatorPolynomial(int $degree): array
    {
        if (isset($this->generators[$degree])) {
            return $this->generators[$degree];
        }

        $polynomial = array_fill(0, $degree + 1, 0);
        $polynomial[0] = 1;

        for ($i = 0; $i < $degree; $i++) {
            $root = $this->exp[$i + 1];
            for ($j = $i + 1; $j > 0; $j--) {
                $polynomial[$j] = $polynomial[$j - 1] ^ $this->multiply($polynomial[$j], $root);
            }
            $polynomial[0] = $this->multiply($polynomial[0], $root);
        }

        return $this->generators[$degree] = $polynomial;
    }

    private function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        return $this->exp[$this->log[$a] + $this->log[$b]];
    }
}
