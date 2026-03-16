<?php

declare(strict_types=1);

namespace ScanMePHP\Encoding;

use ScanMePHP\Matrix;

class MaskSelector
{
    public function selectBestMask(Matrix $matrix): int
    {
        $bestMask = 0;
        $bestScore = PHP_INT_MAX;

        $size = $matrix->getSize();
        $rawData = $matrix->getRawData();
        $reserved = $matrix->getReservedBitmap();

        // Pre-compute XOR positions for each mask pattern.
        // Instead of iterating all size*size modules and checking reserved+condition,
        // we store only the indices that need flipping. This turns mask application
        // into a tight foreach over ~2000 ints instead of ~4700 with branch-heavy logic.
        $xorPositions = $this->computeXorPositions($reserved, $size);

        for ($mask = 0; $mask < 8; $mask++) {
            $masked = $rawData; // COW copy — actual copy deferred until first flip
            foreach ($xorPositions[$mask] as $idx) {
                $masked[$idx] = !$masked[$idx];
            }

            $score = $this->evaluateWithEarlyExit($masked, $size, $bestScore);

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMask = $mask;
            }
        }

        return $bestMask;
    }

    /**
     * Pre-compute which flat-array indices need XOR for each of the 8 mask patterns.
     * Only non-reserved modules where the mask condition is true are included.
     *
     * @return array<int, int[]> 8-element array, each containing the indices to flip
     */
    private function computeXorPositions(array $reserved, int $size): array
    {
        $positions = [[], [], [], [], [], [], [], []];

        for ($y = 0; $y < $size; $y++) {
            $rowOffset = $y * $size;
            $yEven = ($y & 1) === 0;
            $yHalf = $y >> 1;

            for ($x = 0; $x < $size; $x++) {
                $idx = $rowOffset + $x;
                if ($reserved[$idx]) {
                    continue;
                }

                // Mask 0: (x + y) % 2 == 0
                if ((($x + $y) & 1) === 0) {
                    $positions[0][] = $idx;
                }
                // Mask 1: y % 2 == 0
                if ($yEven) {
                    $positions[1][] = $idx;
                }
                // Mask 2: x % 3 == 0
                if ($x % 3 === 0) {
                    $positions[2][] = $idx;
                }
                // Mask 3: (x + y) % 3 == 0
                if (($x + $y) % 3 === 0) {
                    $positions[3][] = $idx;
                }
                // Mask 4: (y/2 + x/3) % 2 == 0
                if ((($yHalf + (int)($x / 3)) & 1) === 0) {
                    $positions[4][] = $idx;
                }

                $xy = $x * $y;
                // Mask 5: (xy)%2 + (xy)%3 == 0
                if ($xy % 2 + $xy % 3 === 0) {
                    $positions[5][] = $idx;
                }
                // Mask 6: ((xy)%2 + (xy)%3) % 2 == 0
                if ((($xy % 2 + $xy % 3) & 1) === 0) {
                    $positions[6][] = $idx;
                }
                // Mask 7: (((x+y)%2) + (xy)%3) % 2 == 0
                if (((($x + $y) & 1) + $xy % 3) & 1) {
                    // condition is false — do nothing
                } else {
                    $positions[7][] = $idx;
                }
            }
        }

        return $positions;
    }

    /**
     * Evaluate all 4 penalty rules with early exit.
     * Returns PHP_INT_MAX if penalty exceeds bestScore at any checkpoint.
     */
    private function evaluateWithEarlyExit(array $masked, int $size, int $bestScore): int
    {
        $penalty = 0;
        $darkCount = 0;

        // === Rule 1 horizontal + Rule 4 dark count (combined single pass) ===
        for ($y = 0; $y < $size; $y++) {
            $rowOffset = $y * $size;
            $count = 1;
            $prev = $masked[$rowOffset];
            if ($prev) $darkCount++;

            for ($x = 1; $x < $size; $x++) {
                $cur = $masked[$rowOffset + $x];
                if ($cur) $darkCount++;

                if ($cur === $prev) {
                    $count++;
                } else {
                    if ($count >= 5) {
                        $penalty += $count - 2;
                    }
                    $count = 1;
                    $prev = $cur;
                }
            }
            if ($count >= 5) {
                $penalty += $count - 2;
            }
        }

        if ($penalty >= $bestScore) {
            return PHP_INT_MAX;
        }

        // === Rule 1 vertical ===
        for ($x = 0; $x < $size; $x++) {
            $count = 1;
            $prev = $masked[$x];

            for ($y = 1; $y < $size; $y++) {
                $cur = $masked[$y * $size + $x];
                if ($cur === $prev) {
                    $count++;
                } else {
                    if ($count >= 5) {
                        $penalty += $count - 2;
                    }
                    $count = 1;
                    $prev = $cur;
                }
            }
            if ($count >= 5) {
                $penalty += $count - 2;
            }
        }

        if ($penalty >= $bestScore) {
            return PHP_INT_MAX;
        }

        // === Rule 2: 2×2 blocks ===
        $sizeM1 = $size - 1;
        for ($y = 0; $y < $sizeM1; $y++) {
            $rowOffset = $y * $size;
            $nextRowOffset = $rowOffset + $size;
            for ($x = 0; $x < $sizeM1; $x++) {
                $bit = $masked[$rowOffset + $x];
                if ($masked[$rowOffset + $x + 1] === $bit &&
                    $masked[$nextRowOffset + $x] === $bit &&
                    $masked[$nextRowOffset + $x + 1] === $bit) {
                    $penalty += 3;
                }
            }
        }

        if ($penalty >= $bestScore) {
            return PHP_INT_MAX;
        }

        // === Rule 3: Finder-like patterns (11-module sequences) ===
        $sizeM10 = $size - 10;

        // Horizontal
        for ($y = 0; $y < $size; $y++) {
            $rowOffset = $y * $size;
            for ($x = 0; $x < $sizeM10; $x++) {
                $i = $rowOffset + $x;
                if ($masked[$i] &&
                    !$masked[$i + 1] &&
                    $masked[$i + 2] &&
                    $masked[$i + 3] &&
                    $masked[$i + 4] &&
                    !$masked[$i + 5] &&
                    $masked[$i + 6] &&
                    !$masked[$i + 7] &&
                    !$masked[$i + 8] &&
                    !$masked[$i + 9] &&
                    !$masked[$i + 10]) {
                    $penalty += 40;
                }
                if (!$masked[$i] &&
                    !$masked[$i + 1] &&
                    !$masked[$i + 2] &&
                    !$masked[$i + 3] &&
                    $masked[$i + 4] &&
                    !$masked[$i + 5] &&
                    $masked[$i + 6] &&
                    $masked[$i + 7] &&
                    $masked[$i + 8] &&
                    !$masked[$i + 9] &&
                    $masked[$i + 10]) {
                    $penalty += 40;
                }
            }
        }

        if ($penalty >= $bestScore) {
            return PHP_INT_MAX;
        }

        // Vertical
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $sizeM10; $y++) {
                $i0 = $y * $size + $x;
                if ($masked[$i0] &&
                    !$masked[$i0 + $size] &&
                    $masked[$i0 + 2 * $size] &&
                    $masked[$i0 + 3 * $size] &&
                    $masked[$i0 + 4 * $size] &&
                    !$masked[$i0 + 5 * $size] &&
                    $masked[$i0 + 6 * $size] &&
                    !$masked[$i0 + 7 * $size] &&
                    !$masked[$i0 + 8 * $size] &&
                    !$masked[$i0 + 9 * $size] &&
                    !$masked[$i0 + 10 * $size]) {
                    $penalty += 40;
                }
                if (!$masked[$i0] &&
                    !$masked[$i0 + $size] &&
                    !$masked[$i0 + 2 * $size] &&
                    !$masked[$i0 + 3 * $size] &&
                    $masked[$i0 + 4 * $size] &&
                    !$masked[$i0 + 5 * $size] &&
                    $masked[$i0 + 6 * $size] &&
                    $masked[$i0 + 7 * $size] &&
                    $masked[$i0 + 8 * $size] &&
                    !$masked[$i0 + 9 * $size] &&
                    $masked[$i0 + 10 * $size]) {
                    $penalty += 40;
                }
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
     * Legacy method — kept for backward compatibility.
     */
    public function evaluateMask(Matrix $matrix, int $maskPattern): int
    {
        $size = $matrix->getSize();
        $rawData = $matrix->getRawData();
        $reserved = $matrix->getReservedBitmap();

        $xorPositions = $this->computeXorPositions($reserved, $size);

        $masked = $rawData;
        foreach ($xorPositions[$maskPattern] as $idx) {
            $masked[$idx] = !$masked[$idx];
        }

        return $this->evaluateWithEarlyExit($masked, $size, PHP_INT_MAX);
    }
}
