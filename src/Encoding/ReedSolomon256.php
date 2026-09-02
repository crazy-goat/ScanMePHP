<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding;

/**
 * Reed–Solomon error correction over GF(2^8), shared by every symbology whose
 * codewords are bytes.
 *
 * Two things differ between the standards and both are constructor arguments:
 *
 *  - the field's **primitive polynomial**, which fixes the multiplication
 *    table — QR uses 0x11D, Data Matrix ECC200 and Aztec use 0x12D;
 *  - the **generator base**, the first power of α in
 *    `prod (x - a^i)` — QR starts at α⁰, ECC200 at α¹.
 *
 * Getting either wrong still produces plausible-looking codewords, so the
 * tests anchor each configuration on a published vector rather than on this
 * implementation agreeing with itself.
 *
 * @internal Shared encoding primitive, not part of the public API.
 */
final class ReedSolomon256
{
    public const QR_PRIMITIVE = 0x11d;

    public const QR_GENERATOR_BASE = 0;

    public const DATA_MATRIX_PRIMITIVE = 0x12d;

    public const DATA_MATRIX_GENERATOR_BASE = 1;

    /** @var list<int> Powers of α, doubled in length so exponents need no modulo */
    private array $expTable = [];

    /** @var array<int, int> */
    private array $logTable = [];

    /** @var array<int, list<int>> Generator polynomials by degree */
    private array $generatorCache = [];

    /**
     * Transposed factor tables by ECC count:
     * factorTable[eccCount][factor][i] = the XOR contribution of $factor to
     * ECC position $i. Precomputing these keeps the inner encode loop free of
     * per-byte log lookups.
     *
     * @var array<int, array<int, list<int>>>
     */
    private array $factorTableCache = [];

    public function __construct(
        private readonly int $primitive = self::QR_PRIMITIVE,
        private readonly int $generatorBase = self::QR_GENERATOR_BASE,
    ) {
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $this->expTable[$i] = $x;
            $this->logTable[$x] = $i;
            $x <<= 1;
            if (($x & 0x100) !== 0) {
                $x ^= $this->primitive;
            }
        }
        $this->expTable[255] = $this->expTable[0];
        for ($i = 256; $i < 512; $i++) {
            $this->expTable[$i] = $this->expTable[$i - 255];
        }
    }

    public static function forQr(): self
    {
        return new self(self::QR_PRIMITIVE, self::QR_GENERATOR_BASE);
    }

    public static function forDataMatrix(): self
    {
        return new self(self::DATA_MATRIX_PRIMITIVE, self::DATA_MATRIX_GENERATOR_BASE);
    }

    /**
     * The $eccCount error correction codewords for $data.
     *
     * @param list<int> $data Data codewords, 0–255
     * @return list<int>
     */
    public function encode(array $data, int $eccCount): array
    {
        $factorTable = $this->factorTable($eccCount);
        $ecc = array_fill(0, $eccCount, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($ecc);
            $ecc[] = 0;

            if ($factor !== 0) {
                $row = $factorTable[$factor];
                for ($i = 0; $i < $eccCount; $i++) {
                    $ecc[$i] ^= $row[$i];
                }
            }
        }

        return $ecc;
    }

    /** @return array<int, list<int>> */
    private function factorTable(int $eccCount): array
    {
        if (isset($this->factorTableCache[$eccCount])) {
            return $this->factorTableCache[$eccCount];
        }

        $generator = $this->generatorPolynomial($eccCount);

        // Intermediate generator coefficients can be zero in GF(256) for large
        // ECC counts (264 at QR v11-High, for one), and log(0) is undefined, so
        // those positions are marked and contribute nothing.
        $generatorLog = [];
        for ($i = 0; $i < $eccCount; $i++) {
            $coefficient = $generator[$eccCount - 1 - $i];
            $generatorLog[$i] = $coefficient !== 0 ? $this->logTable[$coefficient] : -1;
        }

        $factorTable = [];
        for ($factor = 1; $factor < 256; $factor++) {
            $factorLog = $this->logTable[$factor];
            $row = [];
            for ($i = 0; $i < $eccCount; $i++) {
                $row[$i] = $generatorLog[$i] !== -1 ? $this->expTable[$generatorLog[$i] + $factorLog] : 0;
            }
            $factorTable[$factor] = $row;
        }

        return $this->factorTableCache[$eccCount] = $factorTable;
    }

    /** @return list<int> Coefficients, highest degree first */
    private function generatorPolynomial(int $degree): array
    {
        if (isset($this->generatorCache[$degree])) {
            return $this->generatorCache[$degree];
        }

        $polynomial = array_fill(0, $degree + 1, 0);
        $polynomial[0] = 1;

        for ($i = 0; $i < $degree; $i++) {
            $root = $i + $this->generatorBase;
            for ($j = $degree; $j >= 1; $j--) {
                $polynomial[$j] = $polynomial[$j - 1]
                    ^ ($polynomial[$j] === 0 ? 0 : $this->expTable[$this->logTable[$polynomial[$j]] + $root]);
            }
            $polynomial[0] = $this->expTable[$this->logTable[$polynomial[0]] + $root];
        }

        return $this->generatorCache[$degree] = $polynomial;
    }
}
