<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding;

class ReedSolomon
{
    private int $gfSize = 256;
    private int $primitive = 0x11d; // Primitive polynomial for GF(256)
    private array $expTable;
    private array $logTable;

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
        
        // Fill remaining entries
        for ($i = 256; $i < 512; $i++) {
            $this->expTable[$i] = $this->expTable[$i - 255];
        }
    }

    public function encode(array $data, int $eccCount): array
    {
        $generator = $this->buildGeneratorPolynomial($eccCount);
        $ecc = array_fill(0, $eccCount, 0);
        
        foreach ($data as $byte) {
            $factor = $byte ^ $ecc[0];
            
            for ($i = 0; $i < $eccCount - 1; $i++) {
                $ecc[$i] = $ecc[$i + 1];
            }
            $ecc[$eccCount - 1] = 0;
            
            for ($i = 0; $i < $eccCount; $i++) {
                $ecc[$i] ^= $this->multiply($generator[$i + 1], $factor);
            }
        }
        
        return $ecc;
    }

    private function buildGeneratorPolynomial(int $degree): array
    {
        $poly = [1];
        
        for ($i = 0; $i < $degree; $i++) {
            $newPoly = array_fill(0, count($poly) + 1, 0);
            
            for ($j = 0; $j < count($poly); $j++) {
                $newPoly[$j] ^= $poly[$j];
                $newPoly[$j + 1] ^= $this->multiply($poly[$j], $this->expTable[$i]);
            }
            
            $poly = $newPoly;
        }
        
        return $poly;
    }

    private function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return $this->expTable[$this->logTable[$a] + $this->logTable[$b]];
    }

    public function getEccCount(int $version, int $errorCorrectionLevel): int
    {
        // ECC codewords per block for each version and error correction level
        $eccTable = [
            // Version 1-40, Error correction levels: L, M, Q, H
            [7, 10, 13, 17],    // Version 1
            [10, 16, 22, 28],   // Version 2
            [15, 26, 36, 44],   // Version 3
            [20, 36, 52, 64],   // Version 4
            [26, 48, 72, 88],   // Version 5
            [36, 64, 96, 112],  // Version 6
            [40, 72, 108, 130], // Version 7
            [48, 88, 132, 156], // Version 8
            [60, 110, 160, 192], // Version 9
            [72, 130, 192, 224], // Version 10
            [80, 150, 224, 264], // Version 11
            [96, 176, 260, 308], // Version 12
            [104, 198, 288, 352], // Version 13
            [120, 216, 320, 384], // Version 14
            [132, 240, 360, 432], // Version 15
            [144, 280, 408, 480], // Version 16
            [168, 308, 448, 532], // Version 17
            [180, 338, 504, 588], // Version 18
            [196, 364, 546, 650], // Version 19
            [224, 416, 600, 700], // Version 20
            [224, 442, 644, 750], // Version 21
            [252, 476, 690, 816], // Version 22
            [270, 504, 750, 900], // Version 23
            [300, 560, 810, 960], // Version 24
            [312, 588, 870, 1050], // Version 25
            [336, 644, 952, 1110], // Version 26
            [360, 700, 1020, 1200], // Version 27
            [390, 728, 1050, 1260], // Version 28
            [420, 784, 1140, 1350], // Version 29
            [450, 812, 1200, 1440], // Version 30
            [480, 868, 1290, 1530], // Version 31
            [510, 924, 1350, 1620], // Version 32
            [540, 980, 1440, 1710], // Version 33
            [570, 1036, 1530, 1800], // Version 34
            [570, 1064, 1590, 1890], // Version 35
            [600, 1120, 1680, 1980], // Version 36
            [630, 1204, 1770, 2100], // Version 37
            [660, 1260, 1860, 2220], // Version 38
            [720, 1316, 1950, 2310], // Version 39
            [750, 1372, 2040, 2430], // Version 40
        ];
        
        if ($version < 1 || $version > 40) {
            throw new \InvalidArgumentException("Invalid version: {$version}");
        }
        
        return $eccTable[$version - 1][$errorCorrectionLevel];
    }

    public function getTotalDataCodewords(int $version, int $errorCorrectionLevel): int
    {
        // Total data codewords = total modules / 8 - ECC codewords
        $totalModules = (17 + $version * 4) ** 2;
        $eccCount = $this->getEccCount($version, $errorCorrectionLevel);
        
        // Subtract format and version information
        $formatInfoModules = 2 * 15; // Two format info areas
        $versionInfoModules = $version >= 7 ? 2 * 18 : 0; // Two version info areas
        
        $dataModules = $totalModules - $formatInfoModules - $versionInfoModules - 3 * 8 * 8; // Finder patterns
        $dataModules -= 2 * ($version * 4 + 1); // Timing patterns
        $dataModules -= 1; // Dark module
        
        return (int) ($dataModules / 8) - $eccCount;
    }
}
