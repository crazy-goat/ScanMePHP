<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding;

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

    // [version-1][ecl] = [g1_blocks, g1_data, ecc_per_block, g2_blocks, g2_data]
    private const EC_BLOCKS = [
        [[1,19,7,0,0],[1,16,10,0,0],[1,13,13,0,0],[1,9,17,0,0]],         // v1
        [[1,34,10,0,0],[1,28,16,0,0],[1,22,22,0,0],[1,16,28,0,0]],       // v2
        [[1,55,15,0,0],[1,44,26,0,0],[2,17,18,0,0],[2,13,22,0,0]],       // v3
        [[1,80,20,0,0],[2,32,18,0,0],[2,24,26,0,0],[4,9,16,0,0]],        // v4
        [[1,108,26,0,0],[2,43,24,0,0],[2,15,18,2,16],[2,11,22,2,12]],    // v5
        [[2,68,18,0,0],[4,27,16,0,0],[4,19,24,0,0],[4,15,28,0,0]],       // v6
        [[2,78,20,0,0],[4,31,18,0,0],[2,14,18,4,15],[4,13,26,1,14]],     // v7
        [[2,97,24,0,0],[2,38,22,2,39],[4,18,22,2,19],[4,14,26,2,15]],    // v8
        [[2,116,30,0,0],[3,36,22,2,37],[4,16,20,4,17],[4,12,24,4,13]],   // v9
        [[2,68,18,2,69],[4,43,26,1,44],[6,19,24,2,20],[6,15,28,2,16]],   // v10
        [[4,81,20,0,0],[1,50,30,4,51],[4,22,28,4,23],[3,12,24,8,13]],    // v11
        [[2,92,24,2,93],[6,36,22,2,37],[4,20,26,6,21],[7,14,28,4,15]],   // v12
        [[4,107,26,0,0],[8,37,22,1,38],[8,20,24,4,21],[12,11,22,4,12]],  // v13
        [[3,115,30,1,116],[4,40,24,5,41],[11,16,20,5,17],[11,12,24,5,13]], // v14
        [[5,87,22,1,88],[5,41,24,5,42],[5,24,30,7,25],[11,12,24,7,13]],  // v15
        [[5,98,24,1,99],[7,45,28,3,46],[15,19,24,2,20],[3,15,30,13,16]], // v16
        [[1,107,28,5,108],[10,46,28,1,47],[1,22,28,15,23],[2,14,28,17,15]], // v17
        [[5,120,30,1,121],[9,43,26,4,44],[17,22,28,1,23],[2,14,28,19,15]], // v18
        [[3,113,28,4,114],[3,44,26,11,45],[17,21,26,4,22],[9,13,26,16,14]], // v19
        [[3,107,28,5,108],[3,41,26,13,42],[15,24,30,5,25],[15,15,28,10,16]], // v20
        [[4,116,28,4,117],[17,42,26,0,0],[17,22,28,6,23],[19,16,30,6,17]], // v21
        [[2,111,28,7,112],[17,46,28,0,0],[7,24,30,16,25],[34,13,24,0,0]], // v22
        [[4,121,30,5,122],[4,47,28,14,48],[11,24,30,14,25],[16,15,30,14,16]], // v23
        [[6,117,30,4,118],[6,45,28,14,46],[11,24,30,16,25],[30,16,30,2,17]], // v24
        [[8,106,26,4,107],[8,47,28,13,48],[7,24,30,22,25],[22,15,30,13,16]], // v25
        [[10,114,28,2,115],[19,46,28,4,47],[28,22,28,6,23],[33,16,30,4,17]], // v26
        [[8,122,30,4,123],[22,45,28,3,46],[8,23,30,26,24],[12,15,30,28,16]], // v27
        [[3,117,30,10,118],[3,45,28,23,46],[4,24,30,31,25],[11,15,30,31,16]], // v28
        [[7,116,30,7,117],[21,45,28,7,46],[1,23,30,37,24],[19,15,30,26,16]], // v29
        [[5,115,30,10,116],[19,47,28,10,48],[15,24,30,25,25],[23,15,30,25,16]], // v30
        [[13,115,30,3,116],[2,46,28,29,47],[42,24,30,1,25],[23,15,30,28,16]], // v31
        [[17,115,30,0,0],[10,46,28,23,47],[10,24,30,35,25],[19,15,30,35,16]], // v32
        [[17,115,30,1,116],[14,46,28,21,47],[29,24,30,19,25],[11,15,30,46,16]], // v33
        [[13,115,30,6,116],[14,46,28,23,47],[44,24,30,7,25],[59,16,30,1,17]], // v34
        [[12,121,30,7,122],[12,47,28,26,48],[39,24,30,14,25],[22,15,30,41,16]], // v35
        [[6,121,30,14,122],[6,47,28,34,48],[46,24,30,10,25],[2,15,30,64,16]], // v36
        [[17,122,30,4,123],[29,46,28,14,47],[49,24,30,10,25],[24,15,30,46,16]], // v37
        [[4,122,30,18,123],[13,46,28,32,47],[48,24,30,14,25],[42,15,30,32,16]], // v38
        [[20,117,30,4,118],[40,47,28,7,48],[43,24,30,22,25],[10,15,30,67,16]], // v39
        [[19,118,30,6,119],[18,47,28,31,48],[34,24,30,34,25],[20,15,30,61,16]], // v40
    ];

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

    public function encodeWithInterleaving(array $data, int $version, int $eclLevel): array
    {
        $ecBlock = self::EC_BLOCKS[$version - 1][$eclLevel];
        $g1Blocks = $ecBlock[0];
        $g1Data = $ecBlock[1];
        $eccPerBlock = $ecBlock[2];
        $g2Blocks = $ecBlock[3];
        $g2Data = $ecBlock[4];
        $numBlocks = $g1Blocks + $g2Blocks;

        // Split data into blocks and generate ECC per block
        $blockData = [];
        $blockEcc = [];
        $offset = 0;

        for ($b = 0; $b < $numBlocks; $b++) {
            $dlen = ($b < $g1Blocks) ? $g1Data : $g2Data;
            $bd = array_slice($data, $offset, $dlen);
            $offset += $dlen;

            $blockData[$b] = $bd;
            $blockEcc[$b] = $this->encode($bd, $eccPerBlock);
        }

        // Interleave data codewords column-wise across blocks
        $maxDataLen = ($g2Blocks > 0) ? $g2Data : $g1Data;
        $interleaved = [];

        for ($col = 0; $col < $maxDataLen; $col++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $dlen = ($b < $g1Blocks) ? $g1Data : $g2Data;
                if ($col < $dlen) {
                    $interleaved[] = $blockData[$b][$col];
                }
            }
        }

        // Interleave ECC codewords column-wise across blocks
        for ($col = 0; $col < $eccPerBlock; $col++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $interleaved[] = $blockEcc[$b][$col];
            }
        }

        return $interleaved;
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

        // Pre-compute log of each generator coefficient.
        // Most coefficients are non-zero, but intermediate coefficients CAN be zero
        // in GF(256) for large ECC counts (e.g., 264 for v11-High).
        // Use -1 as sentinel for zero coefficients (log(0) is undefined).
        $genLog = [];
        for ($i = 0; $i < $eccCount; $i++) {
            $coeff = $generator[$eccCount - 1 - $i];
            $genLog[$i] = $coeff !== 0 ? $logTable[$coeff] : -1;
        }

        // Build transposed table: for each possible factor (1-255),
        // pre-compute the XOR contribution to each ECC position.
        // Zero coefficients contribute 0 (multiplication by zero in GF(256)).
        $factorTable = [];
        for ($f = 1; $f < 256; $f++) {
            $lf = $logTable[$f];
            $row = [];
            for ($i = 0; $i < $eccCount; $i++) {
                $row[$i] = $genLog[$i] !== -1 ? $expTable[$genLog[$i] + $lf] : 0;
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
        $expTable = $this->expTable;
        $logTable = $this->logTable;

        $gen = array_fill(0, $degree + 1, 0);
        $gen[0] = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = $degree; $j >= 1; $j--) {
                $gen[$j] = $gen[$j - 1] ^ ($gen[$j] === 0 ? 0 : $expTable[$logTable[$gen[$j]] + $i]);
            }
            $gen[0] = $expTable[$logTable[$gen[0]] + $i];
        }

        return $gen;
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
