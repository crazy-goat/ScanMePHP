<?php

declare(strict_types=1);

namespace ScanMePHP\Encoding;

class ReedSolomon
{
    private int $primitive = 0x11d;
    private array $expTable;
    private array $logTable;

    /** @var array<int, array> Cached generator polynomials keyed by degree */
    private array $generatorCache = [];

    /**
     * Cached transposed factor tables keyed by eccCount.
     * factorTableCache[eccCount][factor] = int[] of XOR values for each ECC position.
     * Eliminates per-byte log lookups in the inner encode loop.
     * @var array<int, array<int, int[]>>
     */
    private array $factorTableCache = [];

    public function __construct()
    {
        $this->initGaloisField();
    }

    private function initGaloisField(): void
    {
        $this->expTable = [];
        $this->logTable = [];

        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $this->expTable[$i] = $x;
            $this->logTable[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= $this->primitive;
            }
        }
        $this->expTable[255] = $this->expTable[0];

        for ($i = 256; $i < 512; $i++) {
            $this->expTable[$i] = $this->expTable[$i - 255];
        }
    }

    public function encode(array $data, int $eccCount): array
    {
        $factorTable = $this->getFactorTable($eccCount);
        $ecc = array_fill(0, $eccCount, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($ecc);
            $ecc[] = 0;

            if ($factor !== 0) {
                $ft = $factorTable[$factor];
                for ($i = 0; $i < $eccCount; $i++) {
                    $ecc[$i] ^= $ft[$i];
                }
            }
        }

        return $ecc;
    }

    /**
     * Get transposed factor table, cached by eccCount.
     * factorTable[factor][i] = expTable[logTable[generator[i+1]] + logTable[factor]]
     * Pre-computes all 255 non-zero factor multiplications for each generator coefficient.
     *
     * @return array<int, int[]> [factor] => int[] XOR values per ECC position
     */
    private function getFactorTable(int $eccCount): array
    {
        if (isset($this->factorTableCache[$eccCount])) {
            return $this->factorTableCache[$eccCount];
        }

        $generator = $this->getGeneratorPolynomial($eccCount);
        $expTable = $this->expTable;
        $logTable = $this->logTable;

        // Pre-compute log of each generator coefficient (all are non-zero for RS)
        $genLog = [];
        for ($i = 0; $i < $eccCount; $i++) {
            $genLog[$i] = $logTable[$generator[$i + 1]];
        }

        // Build transposed table: for each possible factor (1-255),
        // pre-compute the XOR contribution to each ECC position
        $factorTable = [];
        for ($f = 1; $f < 256; $f++) {
            $lf = $logTable[$f];
            $row = [];
            for ($i = 0; $i < $eccCount; $i++) {
                $row[$i] = $expTable[$genLog[$i] + $lf];
            }
            $factorTable[$f] = $row;
        }

        $this->factorTableCache[$eccCount] = $factorTable;
        return $factorTable;
    }

    /**
     * Get generator polynomial, cached by degree.
     */
    private function getGeneratorPolynomial(int $degree): array
    {
        if (isset($this->generatorCache[$degree])) {
            return $this->generatorCache[$degree];
        }

        $poly = $this->buildGeneratorPolynomial($degree);
        $this->generatorCache[$degree] = $poly;
        return $poly;
    }

    private function buildGeneratorPolynomial(int $degree): array
    {
        $poly = [1];
        $expTable = $this->expTable;
        $logTable = $this->logTable;

        for ($i = 0; $i < $degree; $i++) {
            $polyLen = count($poly);
            $newPoly = array_fill(0, $polyLen + 1, 0);
            $alphaI = $expTable[$i];

            for ($j = 0; $j < $polyLen; $j++) {
                $newPoly[$j] ^= $poly[$j];
                $p = $poly[$j];
                if ($p !== 0 && $alphaI !== 0) {
                    $newPoly[$j + 1] ^= $expTable[$logTable[$p] + $logTable[$alphaI]];
                }
            }

            $poly = $newPoly;
        }

        return $poly;
    }

    public function getEccCount(int $version, int $errorCorrectionLevel): int
    {
        $eccTable = [
            [7, 10, 13, 17],
            [10, 16, 22, 28],
            [15, 26, 36, 44],
            [20, 36, 52, 64],
            [26, 48, 72, 88],
            [36, 64, 96, 112],
            [40, 72, 108, 130],
            [48, 88, 132, 156],
            [60, 110, 160, 192],
            [72, 130, 192, 224],
            [80, 150, 224, 264],
            [96, 176, 260, 308],
            [104, 198, 288, 352],
            [120, 216, 320, 384],
            [132, 240, 360, 432],
            [144, 280, 408, 480],
            [168, 308, 448, 532],
            [180, 338, 504, 588],
            [196, 364, 546, 650],
            [224, 416, 600, 700],
            [224, 442, 644, 750],
            [252, 476, 690, 816],
            [270, 504, 750, 900],
            [300, 560, 810, 960],
            [312, 588, 870, 1050],
            [336, 644, 952, 1110],
            [360, 700, 1020, 1200],
            [390, 728, 1050, 1260],
            [420, 784, 1140, 1350],
            [450, 812, 1200, 1440],
            [480, 868, 1290, 1530],
            [510, 924, 1350, 1620],
            [540, 980, 1440, 1710],
            [570, 1036, 1530, 1800],
            [570, 1064, 1590, 1890],
            [600, 1120, 1680, 1980],
            [630, 1204, 1770, 2100],
            [660, 1260, 1860, 2220],
            [720, 1316, 1950, 2310],
            [750, 1372, 2040, 2430],
        ];

        if ($version < 1 || $version > 40) {
            throw new \InvalidArgumentException("Invalid version: {$version}");
        }

        return $eccTable[$version - 1][$errorCorrectionLevel];
    }

    public function getTotalDataCodewords(int $version, int $errorCorrectionLevel): int
    {
        $totalModules = (17 + $version * 4) ** 2;
        $eccCount = $this->getEccCount($version, $errorCorrectionLevel);

        $formatInfoModules = 2 * 15;
        $versionInfoModules = $version >= 7 ? 2 * 18 : 0;

        $dataModules = $totalModules - $formatInfoModules - $versionInfoModules - 3 * 8 * 8;
        $dataModules -= 2 * ($version * 4 + 1);
        $dataModules -= 1;

        return (int) ($dataModules / 8) - $eccCount;
    }
}
