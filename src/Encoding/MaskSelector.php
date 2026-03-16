<?php

declare(strict_types=1);

namespace ScanMePHP\Encoding;

use ScanMePHP\ErrorCorrectionLevel;
use ScanMePHP\Matrix;

class MaskSelector
{
    /**
     * Cached mask XOR patterns as int rows, keyed by version.
     * @var array<int, array<int, int[]>> [version][mask] => int[] rows
     */
    private static array $maskRowCache = [];

    /**
     * Cached mask XOR patterns as int columns, keyed by version.
     * @var array<int, array<int, int[]>> [version][mask] => int[] cols
     */
    private static array $maskColCache = [];

    /**
     * Cached format info deltas, keyed by "version:ecl:type".
     * @var array<string, array<int, int[]>>
     */
    private static array $formatInfoCache = [];

    /**
     * Select the best mask pattern using int-packed penalty evaluation.
     *
     * Accepts a matrix with UNMASKED data (no mask XOR applied to data modules).
     * Correctly evaluates each mask independently with proper format info.
     */
    public function selectBestMask(Matrix $matrix, ErrorCorrectionLevel $ecl): int
    {
        $bestMask = 0;
        $bestScore = PHP_INT_MAX;

        $size = $matrix->getSize();
        $version = $matrix->getVersion();
        $rawData = $matrix->getRawData();
        $reserved = $matrix->getReservedBitmap();

        // Get or compute cached mask patterns as int rows/cols
        $this->ensureMaskCache($version, $reserved, $size);
        $maskRows = self::$maskRowCache[$version];
        $maskCols = self::$maskColCache[$version];

        // Pack unmasked data into int rows and int cols
        $dataRows = self::packRows($rawData, $size);
        $dataCols = self::packCols($rawData, $size);

        // Get format info XOR deltas for each mask
        $fmtDeltaRows = $this->getFormatInfoDeltaRows($version, $ecl, $size);
        $fmtDeltaCols = $this->getFormatInfoDeltaCols($version, $ecl, $size);

        // Pre-compute constants
        $pattern1 = 0b10111010000;
        $pattern2 = 0b00001011101;
        $mask11 = (1 << 11) - 1;
        $sizeM1 = $size - 1;
        $sizeM10 = $size - 10;
        $sizeMask = (1 << $size) - 1;
        $sizeM1Mask = (1 << $sizeM1) - 1;
        $totalModules = $size * $size;

        for ($mask = 0; $mask < 8; $mask++) {
            $xorRows = $maskRows[$mask];
            $xorCols = $maskCols[$mask];
            $fmtRows = $fmtDeltaRows[$mask];
            $fmtCols = $fmtDeltaCols[$mask];

            // Apply data mask XOR + format info delta XOR
            $maskedRows = $dataRows;
            $maskedCols = $dataCols;
            for ($i = 0; $i < $size; $i++) {
                $maskedRows[$i] ^= $xorRows[$i] ^ $fmtRows[$i];
                $maskedCols[$i] ^= $xorCols[$i] ^ $fmtCols[$i];
            }

            $penalty = 0;
            $darkCount = 0;

            // === Rule 1 horizontal + dark count ===
            for ($y = 0; $y < $size; $y++) {
                $row = $maskedRows[$y];
                // Popcount via Brian Kernighan
                $v = $row;
                while ($v) {
                    $darkCount++;
                    $v &= ($v - 1);
                }
                // Run-length via transition detection
                $transitions = $row ^ ($row >> 1);
                $count = 1;
                for ($x = $sizeM1 - 1; $x >= 0; $x--) {
                    if (($transitions >> $x) & 1) {
                        if ($count >= 5) {
                            $penalty += $count - 2;
                        }
                        $count = 1;
                    } else {
                        $count++;
                    }
                }
                if ($count >= 5) {
                    $penalty += $count - 2;
                }
            }

            if ($penalty >= $bestScore) {
                continue;
            }

            // === Rule 1 vertical ===
            for ($x = 0; $x < $size; $x++) {
                $col = $maskedCols[$x];
                $transitions = $col ^ ($col >> 1);
                $count = 1;
                for ($y = $sizeM1 - 1; $y >= 0; $y--) {
                    if (($transitions >> $y) & 1) {
                        if ($count >= 5) {
                            $penalty += $count - 2;
                        }
                        $count = 1;
                    } else {
                        $count++;
                    }
                }
                if ($count >= 5) {
                    $penalty += $count - 2;
                }
            }

            if ($penalty >= $bestScore) {
                continue;
            }

            // === Rule 2: 2×2 blocks (bitwise) ===
            for ($y = 0; $y < $sizeM1; $y++) {
                $same = ~($maskedRows[$y] ^ $maskedRows[$y + 1]) & $sizeMask;
                $hSame = ~($maskedRows[$y] ^ ($maskedRows[$y] >> 1)) & $sizeM1Mask;
                $blocks = ($same & ($same >> 1)) & $hSame & $sizeM1Mask;
                while ($blocks) {
                    $penalty += 3;
                    $blocks &= ($blocks - 1);
                }
            }

            if ($penalty >= $bestScore) {
                continue;
            }

            // === Rule 3 horizontal: finder-like patterns ===
            for ($y = 0; $y < $size; $y++) {
                $row = $maskedRows[$y];
                for ($x = 0; $x < $sizeM10; $x++) {
                    $window = ($row >> ($sizeM1 - 10 - $x)) & $mask11;
                    if ($window === $pattern1 || $window === $pattern2) {
                        $penalty += 40;
                    }
                }
            }

            if ($penalty >= $bestScore) {
                continue;
            }

            // === Rule 3 vertical: finder-like patterns ===
            for ($x = 0; $x < $size; $x++) {
                $col = $maskedCols[$x];
                for ($y = 0; $y < $sizeM10; $y++) {
                    $window = ($col >> ($sizeM1 - 10 - $y)) & $mask11;
                    if ($window === $pattern1 || $window === $pattern2) {
                        $penalty += 40;
                    }
                }
            }

            // === Rule 4: Dark/light balance ===
            $percentage = ($darkCount * 100) / $totalModules;
            $deviation = abs($percentage - 50);
            $penalty += ((int)($deviation / 5)) * 50;

            if ($penalty < $bestScore) {
                $bestScore = $penalty;
                $bestMask = $mask;
            }
        }

        return $bestMask;
    }

    /**
     * Evaluate a single mask's penalty score.
     * Matrix must contain UNMASKED data.
     */
    public function evaluateMask(Matrix $matrix, int $maskPattern, ErrorCorrectionLevel $ecl): int
    {
        $size = $matrix->getSize();
        $version = $matrix->getVersion();
        $rawData = $matrix->getRawData();
        $reserved = $matrix->getReservedBitmap();

        $this->ensureMaskCache($version, $reserved, $size);

        $dataRows = self::packRows($rawData, $size);
        $dataCols = self::packCols($rawData, $size);
        $fmtDeltaRows = $this->getFormatInfoDeltaRows($version, $ecl, $size);
        $fmtDeltaCols = $this->getFormatInfoDeltaCols($version, $ecl, $size);

        $maskedRows = $dataRows;
        $maskedCols = $dataCols;
        for ($i = 0; $i < $size; $i++) {
            $maskedRows[$i] ^= self::$maskRowCache[$version][$maskPattern][$i] ^ $fmtDeltaRows[$maskPattern][$i];
            $maskedCols[$i] ^= self::$maskColCache[$version][$maskPattern][$i] ^ $fmtDeltaCols[$maskPattern][$i];
        }

        return $this->evaluateIntPacked($maskedRows, $maskedCols, $size);
    }

    /**
     * Full penalty evaluation on int-packed rows and cols.
     */
    private function evaluateIntPacked(array $rows, array $cols, int $size): int
    {
        $penalty = 0;
        $darkCount = 0;
        $sizeM1 = $size - 1;
        $sizeM10 = $size - 10;
        $sizeMask = (1 << $size) - 1;
        $sizeM1Mask = (1 << $sizeM1) - 1;
        $pattern1 = 0b10111010000;
        $pattern2 = 0b00001011101;
        $mask11 = (1 << 11) - 1;

        for ($y = 0; $y < $size; $y++) {
            $row = $rows[$y];
            $v = $row;
            while ($v) {
                $darkCount++;
                $v &= ($v - 1);
            }
            $transitions = $row ^ ($row >> 1);
            $count = 1;
            for ($x = $sizeM1 - 1; $x >= 0; $x--) {
                if (($transitions >> $x) & 1) {
                    if ($count >= 5) {
                        $penalty += $count - 2;
                    }
                    $count = 1;
                } else {
                    $count++;
                }
            }
            if ($count >= 5) {
                $penalty += $count - 2;
            }
        }

        for ($x = 0; $x < $size; $x++) {
            $col = $cols[$x];
            $transitions = $col ^ ($col >> 1);
            $count = 1;
            for ($y = $sizeM1 - 1; $y >= 0; $y--) {
                if (($transitions >> $y) & 1) {
                    if ($count >= 5) {
                        $penalty += $count - 2;
                    }
                    $count = 1;
                } else {
                    $count++;
                }
            }
            if ($count >= 5) {
                $penalty += $count - 2;
            }
        }

        for ($y = 0; $y < $sizeM1; $y++) {
            $same = ~($rows[$y] ^ $rows[$y + 1]) & $sizeMask;
            $hSame = ~($rows[$y] ^ ($rows[$y] >> 1)) & $sizeM1Mask;
            $blocks = ($same & ($same >> 1)) & $hSame & $sizeM1Mask;
            while ($blocks) {
                $penalty += 3;
                $blocks &= ($blocks - 1);
            }
        }

        for ($y = 0; $y < $size; $y++) {
            $row = $rows[$y];
            for ($x = 0; $x < $sizeM10; $x++) {
                $window = ($row >> ($sizeM1 - 10 - $x)) & $mask11;
                if ($window === $pattern1 || $window === $pattern2) {
                    $penalty += 40;
                }
            }
        }

        for ($x = 0; $x < $size; $x++) {
            $col = $cols[$x];
            for ($y = 0; $y < $sizeM10; $y++) {
                $window = ($col >> ($sizeM1 - 10 - $y)) & $mask11;
                if ($window === $pattern1 || $window === $pattern2) {
                    $penalty += 40;
                }
            }
        }

        $totalModules = $size * $size;
        $percentage = ($darkCount * 100) / $totalModules;
        $deviation = abs($percentage - 50);
        $penalty += ((int)($deviation / 5)) * 50;

        return $penalty;
    }

    /**
     * Pack flat bool[] into int[] rows (one int per row, MSB = leftmost column).
     * @return int[]
     */
    private static function packRows(array $data, int $size): array
    {
        $rows = [];
        for ($y = 0; $y < $size; $y++) {
            $val = 0;
            $rowOffset = $y * $size;
            for ($x = 0; $x < $size; $x++) {
                if ($data[$rowOffset + $x]) {
                    $val |= (1 << ($size - 1 - $x));
                }
            }
            $rows[$y] = $val;
        }
        return $rows;
    }

    /**
     * Pack flat bool[] into int[] columns (one int per column, MSB = topmost row).
     * @return int[]
     */
    private static function packCols(array $data, int $size): array
    {
        $cols = [];
        for ($x = 0; $x < $size; $x++) {
            $val = 0;
            for ($y = 0; $y < $size; $y++) {
                if ($data[$y * $size + $x]) {
                    $val |= (1 << ($size - 1 - $y));
                }
            }
            $cols[$x] = $val;
        }
        return $cols;
    }

    /**
     * Ensure mask int arrays are cached for this version.
     */
    private function ensureMaskCache(int $version, array $reserved, int $size): void
    {
        if (isset(self::$maskRowCache[$version])) {
            return;
        }

        $allRows = [];
        $allCols = [];

        for ($mask = 0; $mask < 8; $mask++) {
            $rows = array_fill(0, $size, 0);
            $cols = array_fill(0, $size, 0);

            for ($y = 0; $y < $size; $y++) {
                $rowOffset = $y * $size;
                $yEven = ($y & 1) === 0;
                $yHalf = $y >> 1;

                for ($x = 0; $x < $size; $x++) {
                    if ($reserved[$rowOffset + $x]) {
                        continue;
                    }

                    $cond = match ($mask) {
                        0 => (($x + $y) & 1) === 0,
                        1 => $yEven,
                        2 => $x % 3 === 0,
                        3 => ($x + $y) % 3 === 0,
                        4 => (($yHalf + (int)($x / 3)) & 1) === 0,
                        5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                        6 => ((($x * $y) % 2 + ($x * $y) % 3) & 1) === 0,
                        7 => (((($x + $y) & 1) + ($x * $y) % 3) & 1) === 0,
                    };

                    if ($cond) {
                        $rows[$y] |= (1 << ($size - 1 - $x));
                        $cols[$x] |= (1 << ($size - 1 - $y));
                    }
                }
            }

            $allRows[$mask] = $rows;
            $allCols[$mask] = $cols;
        }

        self::$maskRowCache[$version] = $allRows;
        self::$maskColCache[$version] = $allCols;
    }

    /**
     * Get format info XOR delta rows for each mask (cached).
     * @return array<int, int[]> [mask] => int[] rows
     */
    private function getFormatInfoDeltaRows(int $version, ErrorCorrectionLevel $ecl, int $size): array
    {
        $key = $version . ':' . $ecl->value . ':rows';
        if (isset(self::$formatInfoCache[$key])) {
            return self::$formatInfoCache[$key];
        }

        $result = $this->computeFormatInfoDeltas($ecl, $size, true);
        self::$formatInfoCache[$key] = $result;
        return $result;
    }

    /**
     * Get format info XOR delta cols for each mask (cached).
     * @return array<int, int[]> [mask] => int[] cols
     */
    private function getFormatInfoDeltaCols(int $version, ErrorCorrectionLevel $ecl, int $size): array
    {
        $key = $version . ':' . $ecl->value . ':cols';
        if (isset(self::$formatInfoCache[$key])) {
            return self::$formatInfoCache[$key];
        }

        $result = $this->computeFormatInfoDeltas($ecl, $size, false);
        self::$formatInfoCache[$key] = $result;
        return $result;
    }

    /**
     * Compute format info XOR deltas for each mask.
     *
     * The unmasked matrix has format info for mask=0 as placeholder.
     * For each mask m, we compute the XOR delta between mask=0 and mask=m format info
     * at all format info positions.
     *
     * @return array<int, int[]> [mask] => int[] (rows or cols depending on $asRows)
     */
    private function computeFormatInfoDeltas(ErrorCorrectionLevel $ecl, int $size, bool $asRows): array
    {
        $baseBits = self::getFormatBits($ecl, 0);
        $result = [];

        // Format info positions: [x, y, bit_index]
        // Top-left vertical
        $positions = [
            [8, 0, 0], [8, 1, 1], [8, 2, 2], [8, 3, 3],
            [8, 4, 4], [8, 5, 5], [8, 7, 6], [8, 8, 7],
        ];
        // Top-left horizontal
        $positions[] = [7, 8, 8];
        $positions[] = [5, 8, 9];
        $positions[] = [4, 8, 10];
        $positions[] = [3, 8, 11];
        $positions[] = [2, 8, 12];
        $positions[] = [1, 8, 13];
        $positions[] = [0, 8, 14];
        // Top-right (row 8)
        for ($i = 0; $i < 8; $i++) {
            $positions[] = [$size - 1 - $i, 8, $i];
        }
        // Bottom-left (col 8)
        for ($i = 8; $i < 15; $i++) {
            $positions[] = [8, $size - 15 + $i, $i];
        }

        for ($mask = 0; $mask < 8; $mask++) {
            $arr = array_fill(0, $size, 0);

            if ($mask !== 0) {
                $maskBits = self::getFormatBits($ecl, $mask);
                $delta = $baseBits ^ $maskBits;

                foreach ($positions as [$x, $y, $bit]) {
                    if (($delta >> $bit) & 1) {
                        if ($asRows) {
                            $arr[$y] ^= (1 << ($size - 1 - $x));
                        } else {
                            $arr[$x] ^= (1 << ($size - 1 - $y));
                        }
                    }
                }
            }

            $result[$mask] = $arr;
        }

        return $result;
    }

    /**
     * Compute format info bits (same algorithm as MatrixBuilder).
     */
    private static function getFormatBits(ErrorCorrectionLevel $level, int $maskPattern): int
    {
        $eccBits = match ($level) {
            ErrorCorrectionLevel::Low => 0b01,
            ErrorCorrectionLevel::Medium => 0b00,
            ErrorCorrectionLevel::Quartile => 0b11,
            ErrorCorrectionLevel::High => 0b10,
        };

        $data = ($eccBits << 3) | $maskPattern;
        $generator = 0x537;
        $format = $data << 10;

        for ($i = 14; $i >= 10; $i--) {
            if (($format >> $i) & 1) {
                $format ^= $generator << ($i - 10);
            }
        }

        return (($data << 10) | $format) ^ 0x5412;
    }
}
