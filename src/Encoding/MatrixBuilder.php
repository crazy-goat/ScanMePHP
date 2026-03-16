<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Matrix;

class MatrixBuilder
{
    private array $formatInfoBits = [
        // Error correction level L (0): 01
        // Error correction level M (1): 00
        // Error correction level Q (2): 11
        // Error correction level H (3): 10
        
        // Mask patterns 0-7 combined with error correction levels
        // Format: [ECC_L, ECC_M, ECC_Q, ECC_H] for each mask
        [
            [0x77c4, 0x5412, 0x5f74, 0x5d24],
            [0x72f3, 0x50d8, 0x57c0, 0x55a0],
            [0x7a89, 0x58f9, 0x5f04, 0x5d04],
            [0x759b, 0x53e5, 0x54b0, 0x5290],
            [0x7685, 0x5686, 0x5d10, 0x5b10],
            [0x71f1, 0x51d1, 0x56e0, 0x54c0],
            [0x79e9, 0x59c9, 0x5e80, 0x5c80],
            [0x74d8, 0x54b8, 0x5350, 0x5150],
        ],
    ];

    public function build(
        int $version,
        array $dataCodewords,
        array $eccCodewords,
        ErrorCorrectionLevel $errorCorrectionLevel,
        int $maskPattern
    ): Matrix {
        $matrix = new Matrix($version);
        
        // Add finder patterns
        $this->addFinderPatterns($matrix);
        
        // Add separators
        $this->addSeparators($matrix);
        
        // Add timing patterns
        $this->addTimingPatterns($matrix);
        
        // Add alignment patterns
        $this->addAlignmentPatterns($matrix);
        
        // Add dark module
        $this->addDarkModule($matrix);
        
        // Add format information
        $this->addFormatInfo($matrix, $errorCorrectionLevel, $maskPattern);
        
        // Add version information (for versions >= 7)
        if ($version >= 7) {
            $this->addVersionInfo($matrix);
        }
        
        // Place data and ECC
        $this->placeData($matrix, array_merge($dataCodewords, $eccCodewords), $maskPattern);
        
        return $matrix;
    }

    private function addFinderPatterns(Matrix $matrix): void
    {
        $size = $matrix->getSize();
        $finderSize = 7;
        
        // Finder pattern pattern (7x7)
        $pattern = [
            [1, 1, 1, 1, 1, 1, 1],
            [1, 0, 0, 0, 0, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 1, 1, 1, 0, 1],
            [1, 0, 0, 0, 0, 0, 1],
            [1, 1, 1, 1, 1, 1, 1],
        ];
        
        // Top-left
        for ($y = 0; $y < $finderSize; $y++) {
            for ($x = 0; $x < $finderSize; $x++) {
                $matrix->set($x, $y, (bool) $pattern[$y][$x]);
            }
        }
        
        // Top-right
        for ($y = 0; $y < $finderSize; $y++) {
            for ($x = 0; $x < $finderSize; $x++) {
                $matrix->set($size - $finderSize + $x, $y, (bool) $pattern[$y][$x]);
            }
        }
        
        // Bottom-left
        for ($y = 0; $y < $finderSize; $y++) {
            for ($x = 0; $x < $finderSize; $x++) {
                $matrix->set($x, $size - $finderSize + $y, (bool) $pattern[$y][$x]);
            }
        }
    }

    private function addSeparators(Matrix $matrix): void
    {
        $size = $matrix->getSize();
        
        // Top-left separator
        for ($i = 0; $i < 8; $i++) {
            $matrix->set($i, 7, false);
            $matrix->set(7, $i, false);
        }
        
        // Top-right separator
        for ($i = 0; $i < 8; $i++) {
            $matrix->set($size - 8 + $i, 7, false);
            $matrix->set($size - 8, $i, false);
        }
        
        // Bottom-left separator
        for ($i = 0; $i < 8; $i++) {
            $matrix->set($i, $size - 8, false);
            $matrix->set(7, $size - 8 + $i, false);
        }
    }

    private const ALIGNMENT_POSITIONS = [
        [],
        [],
        [6, 18],
        [6, 22],
        [6, 26],
        [6, 30],
        [6, 34],
        [6, 22, 38],
        [6, 24, 42],
        [6, 26, 46],
        [6, 28, 50],
        [6, 30, 54],
        [6, 32, 58],
        [6, 34, 62],
        [6, 26, 46, 66],
        [6, 26, 48, 70],
        [6, 26, 50, 74],
        [6, 30, 54, 78],
        [6, 30, 56, 82],
        [6, 30, 58, 86],
        [6, 34, 62, 90],
        [6, 28, 50, 72, 94],
        [6, 26, 50, 74, 98],
        [6, 30, 54, 78, 102],
        [6, 28, 54, 80, 106],
        [6, 32, 58, 84, 110],
        [6, 30, 58, 86, 114],
        [6, 34, 62, 90, 118],
        [6, 26, 50, 74, 98, 122],
        [6, 30, 54, 78, 102, 126],
        [6, 26, 52, 78, 104, 130],
        [6, 30, 56, 82, 108, 134],
        [6, 34, 60, 86, 112, 138],
        [6, 30, 58, 86, 114, 142],
        [6, 34, 62, 90, 118, 146],
        [6, 30, 54, 78, 102, 126, 150],
        [6, 24, 50, 76, 102, 128, 154],
        [6, 28, 54, 80, 106, 132, 158],
        [6, 32, 58, 84, 110, 136, 162],
        [6, 26, 54, 82, 110, 138, 166],
        [6, 30, 58, 86, 114, 142, 170],
    ];

    private function addAlignmentPatterns(Matrix $matrix): void
    {
        $version = $matrix->getVersion();
        if ($version < 2) {
            return;
        }

        $positions = self::ALIGNMENT_POSITIONS[$version];
        $pattern = [
            [1, 1, 1, 1, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 1, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 1, 1, 1, 1],
        ];

        foreach ($positions as $cy) {
            foreach ($positions as $cx) {
                if ($this->overlapsFinderPattern($cx, $cy, $matrix->getSize())) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $matrix->set($cx + $dx, $cy + $dy, (bool) $pattern[$dy + 2][$dx + 2]);
                    }
                }
            }
        }
    }

    private function overlapsFinderPattern(int $cx, int $cy, int $size): bool
    {
        if ($cx <= 8 && $cy <= 8) return true;
        if ($cx >= $size - 8 && $cy <= 8) return true;
        if ($cx <= 8 && $cy >= $size - 8) return true;
        return false;
    }

    private function addTimingPatterns(Matrix $matrix): void
    {
        $size = $matrix->getSize();
        
        // Horizontal timing pattern
        for ($x = 8; $x < $size - 8; $x++) {
            $matrix->set($x, 6, $x % 2 === 0);
        }
        
        // Vertical timing pattern
        for ($y = 8; $y < $size - 8; $y++) {
            $matrix->set(6, $y, $y % 2 === 0);
        }
    }

    private function addDarkModule(Matrix $matrix): void
    {
        $version = $matrix->getVersion();
        $matrix->set(8, 4 * $version + 9, true);
    }

    private function addFormatInfo(Matrix $matrix, ErrorCorrectionLevel $level, int $maskPattern): void
    {
        $size = $matrix->getSize();
        $formatBits = $this->getFormatBits($level, $maskPattern);
        
        // Top-left format info (along column 8, bottom to top, then along row 8, right to left)
        $matrix->set(8, 0, (bool) (($formatBits >> 0) & 1));
        $matrix->set(8, 1, (bool) (($formatBits >> 1) & 1));
        $matrix->set(8, 2, (bool) (($formatBits >> 2) & 1));
        $matrix->set(8, 3, (bool) (($formatBits >> 3) & 1));
        $matrix->set(8, 4, (bool) (($formatBits >> 4) & 1));
        $matrix->set(8, 5, (bool) (($formatBits >> 5) & 1));
        $matrix->set(8, 7, (bool) (($formatBits >> 6) & 1));
        $matrix->set(8, 8, (bool) (($formatBits >> 7) & 1));
        $matrix->set(7, 8, (bool) (($formatBits >> 8) & 1));
        $matrix->set(5, 8, (bool) (($formatBits >> 9) & 1));
        $matrix->set(4, 8, (bool) (($formatBits >> 10) & 1));
        $matrix->set(3, 8, (bool) (($formatBits >> 11) & 1));
        $matrix->set(2, 8, (bool) (($formatBits >> 12) & 1));
        $matrix->set(1, 8, (bool) (($formatBits >> 13) & 1));
        $matrix->set(0, 8, (bool) (($formatBits >> 14) & 1));
        
        // Top-right and bottom-left format info
        for ($i = 0; $i < 8; $i++) {
            $matrix->set($size - 1 - $i, 8, (bool) (($formatBits >> $i) & 1));
        }
        for ($i = 8; $i < 15; $i++) {
            $matrix->set(8, $size - 15 + $i, (bool) (($formatBits >> $i) & 1));
        }
    }

    private function getFormatBits(ErrorCorrectionLevel $level, int $maskPattern): int
    {
        $eccBits = match ($level) {
            ErrorCorrectionLevel::Low => 0b01,
            ErrorCorrectionLevel::Medium => 0b00,
            ErrorCorrectionLevel::Quartile => 0b11,
            ErrorCorrectionLevel::High => 0b10,
        };
        
        $data = ($eccBits << 3) | $maskPattern;
        
        // Calculate BCH error correction
        $generator = 0x537; // BCH(15,5) generator polynomial
        $format = $data << 10;
        
        for ($i = 14; $i >= 10; $i--) {
            if (($format >> $i) & 1) {
                $format ^= $generator << ($i - 10);
            }
        }
        
        $result = ($data << 10) | $format;
        return $result ^ 0x5412;
    }

    private function addVersionInfo(Matrix $matrix): void
    {
        $version = $matrix->getVersion();
        $size = $matrix->getSize();
        
        // Calculate version info bits
        $versionBits = $this->getVersionBits($version);
        
        // Place version info
        for ($i = 0; $i < 18; $i++) {
            $bit = (bool) (($versionBits >> $i) & 1);
            $matrix->set((int) ($i / 3), $size - 11 + ($i % 3), $bit);
            $matrix->set($size - 11 + ($i % 3), (int) ($i / 3), $bit);
        }
    }

    private function getVersionBits(int $version): int
    {
        $data = $version;
        $generator = 0x1f25; // BCH(18,6) generator polynomial
        $versionInfo = $data << 12;
        
        for ($i = 17; $i >= 12; $i--) {
            if (($versionInfo >> $i) & 1) {
                $versionInfo ^= $generator << ($i - 12);
            }
        }
        
        return ($data << 12) | $versionInfo;
    }

    private function placeData(Matrix $matrix, array $codewords, int $maskPattern): void
    {
        $size = $matrix->getSize();
        $bitIndex = 0;
        
        // Place data in upward columns, alternating direction
        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--; // Skip timing column
            }
            
            $up = (int)(($size - 1 - $col) / 2) % 2 === 0;
            
            for ($row = $up ? $size - 1 : 0; $up ? $row >= 0 : $row < $size; $up ? $row-- : $row++) {
                for ($c = 0; $c < 2; $c++) {
                    $x = $col - $c;
                    $y = $row;
                    
                    if (!$matrix->isReserved($x, $y)) {
                        $byteIndex = (int) ($bitIndex / 8);
                        $bitOffset = 7 - ($bitIndex % 8);
                        
                        if ($byteIndex < count($codewords)) {
                            $bit = (($codewords[$byteIndex] >> $bitOffset) & 1) === 1;
                            $bit = $this->applyMask($x, $y, $bit, $maskPattern);
                            $matrix->set($x, $y, $bit);
                        }
                        
                        $bitIndex++;
                    }
                }
            }
        }
    }

    private function applyMask(int $x, int $y, bool $bit, int $maskPattern): bool
    {
        $condition = match ($maskPattern) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => ((int) ($y / 2) + (int) ($x / 3)) % 2 === 0,
            5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
            6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
            7 => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
            default => false,
        };
        
        return $condition ? !$bit : $bit;
    }
}
