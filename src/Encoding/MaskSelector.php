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
     * Byte-indexed popcount lookup table (0-255 → bit count).
     * @var int[]
     */
    private static array $popLut = [];

    private static function ensurePopLut(): void
    {
        if (self::$popLut !== []) {
            return;
        }
        for ($i = 0; $i < 256; $i++) {
            $c = 0;
            $v = $i;
            while ($v) {
                $c++;
                $v &= ($v - 1);
            }
            self::$popLut[$i] = $c;
        }
    }

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
        $reserved = $matrix->getReservedBitmap();

        // Get or compute cached mask patterns as int rows/cols
        $this->ensureMaskCache($version, $reserved, $size);
        $maskRows = self::$maskRowCache[$version];
        $maskCols = self::$maskColCache[$version];

        // Pack unmasked data directly from Matrix internals (no COW copy)
        $dataRows = $matrix->getPackedRows();
        $dataCols = $matrix->getPackedCols();

        // Get format info XOR deltas for each mask
        $fmtDeltaRows = $this->getFormatInfoDeltaRows($version, $ecl, $size);
        $fmtDeltaCols = $this->getFormatInfoDeltaCols($version, $ecl, $size);

        // Pre-compute constants
        self::ensurePopLut();
        $popLut = self::$popLut;
        $sizeM1 = $size - 1;
        $sizeM10 = $size - 10;
        $sizeMask = (1 << $size) - 1;
        $sizeM1Mask = (1 << $sizeM1) - 1;
        $runMask = $sizeM1Mask; // mask for transition bits (size-1 bits)
        $r3ValidMask = (1 << $sizeM10) - 1; // valid positions for 11-bit pattern match
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
            // Run-length penalty via bitwise cascade (no bit-by-bit loop):
            // transitions = row XOR (row >> 1) — bit set where adjacent bits differ
            // inv = ~transitions — bit set where adjacent bits are same
            // r4 = inv & (inv>>1) & (inv>>2) & (inv>>3) — marks positions in runs of 5+ same bits
            // For a run of N same bits: contributes (N-4) bits to r4
            // Penalty = (N-2) = (N-4) + 2, summed over all runs >= 5
            // = popcount(r4) + 2 * (number of distinct runs in r4)
            // Distinct runs = popcount(r4 & ~(r4 << 1)) — leading edges
            for ($y = 0; $y < $size; $y++) {
                $row = $maskedRows[$y];
                // Popcount via byte LUT
                $darkCount += $popLut[$row & 0xff] + $popLut[($row >> 8) & 0xff]
                    + $popLut[($row >> 16) & 0xff] + $popLut[($row >> 24) & 0xff]
                    + $popLut[($row >> 32) & 0xff] + $popLut[($row >> 40) & 0xff]
                    + $popLut[($row >> 48) & 0xff] + $popLut[($row >> 56) & 0xff];
                // Run-length via bitwise cascade (3-6× faster than bit-by-bit)
                $inv = (~($row ^ ($row >> 1))) & $runMask;
                $r4 = $inv & ($inv >> 1) & ($inv >> 2) & ($inv >> 3);
                if ($r4 !== 0) {
                    $penalty += $popLut[$r4 & 0xff] + $popLut[($r4 >> 8) & 0xff]
                        + $popLut[($r4 >> 16) & 0xff] + $popLut[($r4 >> 24) & 0xff]
                        + $popLut[($r4 >> 32) & 0xff] + $popLut[($r4 >> 40) & 0xff]
                        + $popLut[($r4 >> 48) & 0xff] + $popLut[($r4 >> 56) & 0xff];
                    $starts = $r4 & ~($r4 << 1);
                    $penalty += 2 * ($popLut[$starts & 0xff] + $popLut[($starts >> 8) & 0xff]
                        + $popLut[($starts >> 16) & 0xff] + $popLut[($starts >> 24) & 0xff]
                        + $popLut[($starts >> 32) & 0xff] + $popLut[($starts >> 40) & 0xff]
                        + $popLut[($starts >> 48) & 0xff] + $popLut[($starts >> 56) & 0xff]);
                }
            }

            if ($penalty >= $bestScore) {
                continue;
            }

            // === Rule 1 vertical (same bitwise cascade on columns) ===
            for ($x = 0; $x < $size; $x++) {
                $col = $maskedCols[$x];
                $inv = (~($col ^ ($col >> 1))) & $runMask;
                $r4 = $inv & ($inv >> 1) & ($inv >> 2) & ($inv >> 3);
                if ($r4 !== 0) {
                    $penalty += $popLut[$r4 & 0xff] + $popLut[($r4 >> 8) & 0xff]
                        + $popLut[($r4 >> 16) & 0xff] + $popLut[($r4 >> 24) & 0xff]
                        + $popLut[($r4 >> 32) & 0xff] + $popLut[($r4 >> 40) & 0xff]
                        + $popLut[($r4 >> 48) & 0xff] + $popLut[($r4 >> 56) & 0xff];
                    $starts = $r4 & ~($r4 << 1);
                    $penalty += 2 * ($popLut[$starts & 0xff] + $popLut[($starts >> 8) & 0xff]
                        + $popLut[($starts >> 16) & 0xff] + $popLut[($starts >> 24) & 0xff]
                        + $popLut[($starts >> 32) & 0xff] + $popLut[($starts >> 40) & 0xff]
                        + $popLut[($starts >> 48) & 0xff] + $popLut[($starts >> 56) & 0xff]);
                }
            }

            if ($penalty >= $bestScore) {
                continue;
            }

            // === Rule 2: 2×2 blocks (bitwise, LUT popcount) ===
            for ($y = 0; $y < $sizeM1; $y++) {
                $same = ~($maskedRows[$y] ^ $maskedRows[$y + 1]) & $sizeMask;
                $hSame = ~($maskedRows[$y] ^ ($maskedRows[$y] >> 1)) & $sizeM1Mask;
                $blocks = ($same & ($same >> 1)) & $hSame & $sizeM1Mask;
                if ($blocks !== 0) {
                    $penalty += 3 * ($popLut[$blocks & 0xff] + $popLut[($blocks >> 8) & 0xff]
                        + $popLut[($blocks >> 16) & 0xff] + $popLut[($blocks >> 24) & 0xff]
                        + $popLut[($blocks >> 32) & 0xff] + $popLut[($blocks >> 40) & 0xff]
                        + $popLut[($blocks >> 48) & 0xff] + $popLut[($blocks >> 56) & 0xff]);
                }
            }

            if ($penalty >= $bestScore) {
                continue;
            }

            // === Rule 3 horizontal: finder-like patterns (bitwise parallel) ===
            // Match 10111010000 and 00001011101 across all positions simultaneously.
            // Each shifted copy isolates one bit position of the 11-bit pattern.
            // AND/NAND of all 11 shifted copies yields a bitmask of match positions.
            for ($y = 0; $y < $size; $y++) {
                $row = $maskedRows[$y];
                $r1 = $row >> 1; $r2 = $row >> 2; $r3 = $row >> 3; $r4p = $row >> 4;
                $r5 = $row >> 5; $r6 = $row >> 6; $r7 = $row >> 7; $r8 = $row >> 8;
                $r9 = $row >> 9; $r10 = $row >> 10;
                // Pattern 10111010000: bits 10,8,7,6,4 set; bits 9,5,3,2,1,0 clear
                $m1 = $r10 & ~$r9 & $r8 & $r7 & $r6 & ~$r5 & $r4p & ~$r3 & ~$r2 & ~$r1 & ~$row;
                // Pattern 00001011101: bits 6,4,3,2,0 set; bits 10,9,8,7,5,1 clear
                $m2 = ~$r10 & ~$r9 & ~$r8 & ~$r7 & $r6 & ~$r5 & $r4p & $r3 & $r2 & ~$r1 & $row;
                $matches = ($m1 | $m2) & $r3ValidMask;
                if ($matches !== 0) {
                    $penalty += 40 * ($popLut[$matches & 0xff] + $popLut[($matches >> 8) & 0xff]
                        + $popLut[($matches >> 16) & 0xff] + $popLut[($matches >> 24) & 0xff]
                        + $popLut[($matches >> 32) & 0xff] + $popLut[($matches >> 40) & 0xff]
                        + $popLut[($matches >> 48) & 0xff] + $popLut[($matches >> 56) & 0xff]);
                }
            }

            if ($penalty >= $bestScore) {
                continue;
            }

            // === Rule 3 vertical: finder-like patterns (bitwise parallel) ===
            for ($x = 0; $x < $size; $x++) {
                $col = $maskedCols[$x];
                $r1 = $col >> 1; $r2 = $col >> 2; $r3 = $col >> 3; $r4p = $col >> 4;
                $r5 = $col >> 5; $r6 = $col >> 6; $r7 = $col >> 7; $r8 = $col >> 8;
                $r9 = $col >> 9; $r10 = $col >> 10;
                $m1 = $r10 & ~$r9 & $r8 & $r7 & $r6 & ~$r5 & $r4p & ~$r3 & ~$r2 & ~$r1 & ~$col;
                $m2 = ~$r10 & ~$r9 & ~$r8 & ~$r7 & $r6 & ~$r5 & $r4p & $r3 & $r2 & ~$r1 & $col;
                $matches = ($m1 | $m2) & $r3ValidMask;
                if ($matches !== 0) {
                    $penalty += 40 * ($popLut[$matches & 0xff] + $popLut[($matches >> 8) & 0xff]
                        + $popLut[($matches >> 16) & 0xff] + $popLut[($matches >> 24) & 0xff]
                        + $popLut[($matches >> 32) & 0xff] + $popLut[($matches >> 40) & 0xff]
                        + $popLut[($matches >> 48) & 0xff] + $popLut[($matches >> 56) & 0xff]);
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
        $reserved = $matrix->getReservedBitmap();

        $this->ensureMaskCache($version, $reserved, $size);

        $dataRows = $matrix->getPackedRows();
        $dataCols = $matrix->getPackedCols();
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
        self::ensurePopLut();
        $popLut = self::$popLut;
        $penalty = 0;
        $darkCount = 0;
        $sizeM1 = $size - 1;
        $sizeM10 = $size - 10;
        $sizeMask = (1 << $size) - 1;
        $sizeM1Mask = (1 << $sizeM1) - 1;
        $runMask = $sizeM1Mask; // mask for transition bits (size-1 bits)
        $r3ValidMask = (1 << $sizeM10) - 1; // valid positions for 11-bit pattern match

        // === Rule 1 horizontal + dark count (bitwise cascade) ===
        for ($y = 0; $y < $size; $y++) {
            $row = $rows[$y];
            $darkCount += $popLut[$row & 0xff] + $popLut[($row >> 8) & 0xff]
                + $popLut[($row >> 16) & 0xff] + $popLut[($row >> 24) & 0xff]
                + $popLut[($row >> 32) & 0xff] + $popLut[($row >> 40) & 0xff]
                + $popLut[($row >> 48) & 0xff] + $popLut[($row >> 56) & 0xff];
            $inv = (~($row ^ ($row >> 1))) & $runMask;
            $r4 = $inv & ($inv >> 1) & ($inv >> 2) & ($inv >> 3);
            if ($r4 !== 0) {
                $penalty += $popLut[$r4 & 0xff] + $popLut[($r4 >> 8) & 0xff]
                    + $popLut[($r4 >> 16) & 0xff] + $popLut[($r4 >> 24) & 0xff]
                    + $popLut[($r4 >> 32) & 0xff] + $popLut[($r4 >> 40) & 0xff]
                    + $popLut[($r4 >> 48) & 0xff] + $popLut[($r4 >> 56) & 0xff];
                $starts = $r4 & ~($r4 << 1);
                $penalty += 2 * ($popLut[$starts & 0xff] + $popLut[($starts >> 8) & 0xff]
                    + $popLut[($starts >> 16) & 0xff] + $popLut[($starts >> 24) & 0xff]
                    + $popLut[($starts >> 32) & 0xff] + $popLut[($starts >> 40) & 0xff]
                    + $popLut[($starts >> 48) & 0xff] + $popLut[($starts >> 56) & 0xff]);
            }
        }

        // === Rule 1 vertical (bitwise cascade on columns) ===
        for ($x = 0; $x < $size; $x++) {
            $col = $cols[$x];
            $inv = (~($col ^ ($col >> 1))) & $runMask;
            $r4 = $inv & ($inv >> 1) & ($inv >> 2) & ($inv >> 3);
            if ($r4 !== 0) {
                $penalty += $popLut[$r4 & 0xff] + $popLut[($r4 >> 8) & 0xff]
                    + $popLut[($r4 >> 16) & 0xff] + $popLut[($r4 >> 24) & 0xff]
                    + $popLut[($r4 >> 32) & 0xff] + $popLut[($r4 >> 40) & 0xff]
                    + $popLut[($r4 >> 48) & 0xff] + $popLut[($r4 >> 56) & 0xff];
                $starts = $r4 & ~($r4 << 1);
                $penalty += 2 * ($popLut[$starts & 0xff] + $popLut[($starts >> 8) & 0xff]
                    + $popLut[($starts >> 16) & 0xff] + $popLut[($starts >> 24) & 0xff]
                    + $popLut[($starts >> 32) & 0xff] + $popLut[($starts >> 40) & 0xff]
                    + $popLut[($starts >> 48) & 0xff] + $popLut[($starts >> 56) & 0xff]);
            }
        }

        // === Rule 2: 2×2 blocks (bitwise, LUT popcount) ===
        for ($y = 0; $y < $sizeM1; $y++) {
            $same = ~($rows[$y] ^ $rows[$y + 1]) & $sizeMask;
            $hSame = ~($rows[$y] ^ ($rows[$y] >> 1)) & $sizeM1Mask;
            $blocks = ($same & ($same >> 1)) & $hSame & $sizeM1Mask;
            if ($blocks !== 0) {
                $penalty += 3 * ($popLut[$blocks & 0xff] + $popLut[($blocks >> 8) & 0xff]
                    + $popLut[($blocks >> 16) & 0xff] + $popLut[($blocks >> 24) & 0xff]
                    + $popLut[($blocks >> 32) & 0xff] + $popLut[($blocks >> 40) & 0xff]
                    + $popLut[($blocks >> 48) & 0xff] + $popLut[($blocks >> 56) & 0xff]);
            }
        }

        // === Rule 3 horizontal: finder-like patterns (bitwise parallel) ===
        for ($y = 0; $y < $size; $y++) {
            $row = $rows[$y];
            $r1 = $row >> 1; $r2 = $row >> 2; $r3 = $row >> 3; $r4p = $row >> 4;
            $r5 = $row >> 5; $r6 = $row >> 6; $r7 = $row >> 7; $r8 = $row >> 8;
            $r9 = $row >> 9; $r10 = $row >> 10;
            $m1 = $r10 & ~$r9 & $r8 & $r7 & $r6 & ~$r5 & $r4p & ~$r3 & ~$r2 & ~$r1 & ~$row;
            $m2 = ~$r10 & ~$r9 & ~$r8 & ~$r7 & $r6 & ~$r5 & $r4p & $r3 & $r2 & ~$r1 & $row;
            $matches = ($m1 | $m2) & $r3ValidMask;
            if ($matches !== 0) {
                $penalty += 40 * ($popLut[$matches & 0xff] + $popLut[($matches >> 8) & 0xff]
                    + $popLut[($matches >> 16) & 0xff] + $popLut[($matches >> 24) & 0xff]
                    + $popLut[($matches >> 32) & 0xff] + $popLut[($matches >> 40) & 0xff]
                    + $popLut[($matches >> 48) & 0xff] + $popLut[($matches >> 56) & 0xff]);
            }
        }

        // === Rule 3 vertical: finder-like patterns (bitwise parallel) ===
        for ($x = 0; $x < $size; $x++) {
            $col = $cols[$x];
            $r1 = $col >> 1; $r2 = $col >> 2; $r3 = $col >> 3; $r4p = $col >> 4;
            $r5 = $col >> 5; $r6 = $col >> 6; $r7 = $col >> 7; $r8 = $col >> 8;
            $r9 = $col >> 9; $r10 = $col >> 10;
            $m1 = $r10 & ~$r9 & $r8 & $r7 & $r6 & ~$r5 & $r4p & ~$r3 & ~$r2 & ~$r1 & ~$col;
            $m2 = ~$r10 & ~$r9 & ~$r8 & ~$r7 & $r6 & ~$r5 & $r4p & $r3 & $r2 & ~$r1 & $col;
            $matches = ($m1 | $m2) & $r3ValidMask;
            if ($matches !== 0) {
                $penalty += 40 * ($popLut[$matches & 0xff] + $popLut[($matches >> 8) & 0xff]
                    + $popLut[($matches >> 16) & 0xff] + $popLut[($matches >> 24) & 0xff]
                    + $popLut[($matches >> 32) & 0xff] + $popLut[($matches >> 40) & 0xff]
                    + $popLut[($matches >> 48) & 0xff] + $popLut[($matches >> 56) & 0xff]);
            }
        }

        // === Rule 4: Dark/light balance ===
        $totalModules = $size * $size;
        $percentage = ($darkCount * 100) / $totalModules;
        $deviation = abs($percentage - 50);
        $penalty += ((int)($deviation / 5)) * 50;

        return $penalty;
    }

    /**
     * Get cached mask XOR rows for a given version and mask pattern.
     * Must be called after selectBestMask() or evaluateMask() which populate the cache.
     *
     * @return int[] One int per row, bits set where mask flips data modules
     */
    public function getMaskXorRows(int $version, int $maskPattern): array
    {
        return self::$maskRowCache[$version][$maskPattern];
    }

    /**
     * Check if mask cache is populated for a given version.
     */
    public function hasMaskCache(int $version): bool
    {
        return isset(self::$maskRowCache[$version]);
    }

    /**
     * Ensure mask int arrays are cached for this version.
     *
     * Loop order: y → x → all 8 masks. This computes shared values ($xy, $xyMod3,
     * $sumMod3, etc.) once per (x,y) and evaluates all 8 mask conditions together,
     * eliminating redundant multiplications and modulo operations.
     */
    private function ensureMaskCache(int $version, array $reserved, int $size): void
    {
        if (isset(self::$maskRowCache[$version])) {
            return;
        }

        // Initialize all 8 masks' row and col arrays
        $allRows = array_fill(0, 8, array_fill(0, $size, 0));
        $allCols = array_fill(0, 8, array_fill(0, $size, 0));
        $sizeM1 = $size - 1;

        for ($y = 0; $y < $size; $y++) {
            $rowOffset = $y * $size;
            $yEven = ($y & 1) === 0;
            $yHalf = $y >> 1;
            $rowBit = 1 << ($sizeM1 - $y);

            for ($x = 0; $x < $size; $x++) {
                if ($reserved[$rowOffset + $x]) {
                    continue;
                }

                // Shared computations — each calculated once per (x, y)
                $xy = $x * $y;
                $sum = $x + $y;
                $xyMod3 = $xy % 3;
                $xyBit = $xy & 1;
                $sumBit = $sum & 1;
                $colBit = 1 << ($sizeM1 - $x);

                // Evaluate all 8 masks using pre-computed values
                // Mask 0: (x + y) % 2 == 0
                if ($sumBit === 0) {
                    $allRows[0][$y] |= $colBit;
                    $allCols[0][$x] |= $rowBit;
                }
                // Mask 1: y % 2 == 0
                if ($yEven) {
                    $allRows[1][$y] |= $colBit;
                    $allCols[1][$x] |= $rowBit;
                }
                // Mask 2: x % 3 == 0
                if ($x % 3 === 0) {
                    $allRows[2][$y] |= $colBit;
                    $allCols[2][$x] |= $rowBit;
                }
                // Mask 3: (x + y) % 3 == 0
                if ($sum % 3 === 0) {
                    $allRows[3][$y] |= $colBit;
                    $allCols[3][$x] |= $rowBit;
                }
                // Mask 4: (y/2 + x/3) % 2 == 0
                if ((($yHalf + (int)($x / 3)) & 1) === 0) {
                    $allRows[4][$y] |= $colBit;
                    $allCols[4][$x] |= $rowBit;
                }
                // Mask 5: (x*y)%2 + (x*y)%3 == 0
                if ($xyBit + $xyMod3 === 0) {
                    $allRows[5][$y] |= $colBit;
                    $allCols[5][$x] |= $rowBit;
                }
                // Mask 6: ((x*y)%2 + (x*y)%3) % 2 == 0
                if ((($xyBit + $xyMod3) & 1) === 0) {
                    $allRows[6][$y] |= $colBit;
                    $allCols[6][$x] |= $rowBit;
                }
                // Mask 7: ((x+y)%2 + (x*y)%3) % 2 == 0
                if ((($sumBit + $xyMod3) & 1) === 0) {
                    $allRows[7][$y] |= $colBit;
                    $allCols[7][$x] |= $rowBit;
                }
            }
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
