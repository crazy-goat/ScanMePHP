<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding;

use CrazyGoat\ScanMePHP\Matrix;

class MaskSelector
{
    public function selectBestMask(Matrix $matrix): int
    {
        $bestMask = 0;
        $bestScore = PHP_INT_MAX;
        $size = $matrix->getSize();
        $reserved = $matrix->getReservedBitmap();

        for ($mask = 0; $mask < 8; $mask++) {
            $score = $this->evaluatePenalty($matrix, $size, $mask, $reserved);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMask = $mask;
            }
        }

        return $bestMask;
    }

    public function evaluateMask(Matrix $matrix, int $maskPattern): int
    {
        $size = $matrix->getSize();
        $reserved = $matrix->getReservedBitmap();
        return $this->evaluatePenalty($matrix, $size, $maskPattern, $reserved);
    }

    private function evaluatePenalty(Matrix $matrix, int $size, int $maskPattern, array $reserved): int
    {
        // Pre-compute the masked module grid as a flat int array (0 or 1)
        // Only data modules get the mask XOR; reserved modules keep their raw value.
        $modules = $this->buildMaskedGrid($matrix, $size, $maskPattern, $reserved);

        $penalty = 0;
        $darkCount = 0;

        // === Rules 1 + 3 for rows ===
        for ($y = 0; $y < $size; $y++) {
            $rowOffset = $y * $size;
            $runColor = 0;
            $runLen = 0;
            $h0 = 0; $h1 = 0; $h2 = 0; $h3 = 0; $h4 = 0; $h5 = 0; $h6 = 0;

            for ($x = 0; $x < $size; $x++) {
                $c = $modules[$rowOffset + $x];
                $darkCount += $c;

                if ($c === $runColor) {
                    $runLen++;
                    if ($runLen === 5) {
                        $penalty += 3;
                    } elseif ($runLen > 5) {
                        $penalty++;
                    }
                } else {
                    // finderPenaltyAddHistory inline
                    if ($h0 === 0) {
                        $runLen += $size;
                    }
                    $h6 = $h5; $h5 = $h4; $h4 = $h3; $h3 = $h2; $h2 = $h1; $h1 = $h0; $h0 = $runLen;

                    // finderPenaltyCountPatterns inline (only when previous color was white/0)
                    if (!$runColor) {
                        if ($h1 > 0 && $h2 === $h1 && $h3 === $h1 * 3 && $h4 === $h1 && $h5 === $h1) {
                            if ($h0 >= $h1 * 4 && $h6 >= $h1) {
                                $penalty += 40;
                            }
                            if ($h6 >= $h1 * 4 && $h0 >= $h1) {
                                $penalty += 40;
                            }
                        }
                    }

                    $runColor = $c;
                    $runLen = 1;
                }
            }

            // finderPenaltyTerminateAndCount inline
            if ($runColor) {
                if ($h0 === 0) {
                    $runLen += $size;
                }
                $h6 = $h5; $h5 = $h4; $h4 = $h3; $h3 = $h2; $h2 = $h1; $h1 = $h0; $h0 = $runLen;
                $runLen = 0;
            }
            $runLen += $size;
            if ($h0 === 0) {
                $runLen += $size;
            }
            $h6 = $h5; $h5 = $h4; $h4 = $h3; $h3 = $h2; $h2 = $h1; $h1 = $h0; $h0 = $runLen;
            if ($h1 > 0 && $h2 === $h1 && $h3 === $h1 * 3 && $h4 === $h1 && $h5 === $h1) {
                if ($h0 >= $h1 * 4 && $h6 >= $h1) {
                    $penalty += 40;
                }
                if ($h6 >= $h1 * 4 && $h0 >= $h1) {
                    $penalty += 40;
                }
            }
        }

        // === Rules 1 + 3 for columns ===
        for ($x = 0; $x < $size; $x++) {
            $runColor = 0;
            $runLen = 0;
            $h0 = 0; $h1 = 0; $h2 = 0; $h3 = 0; $h4 = 0; $h5 = 0; $h6 = 0;

            for ($y = 0; $y < $size; $y++) {
                $c = $modules[$y * $size + $x];

                if ($c === $runColor) {
                    $runLen++;
                    if ($runLen === 5) {
                        $penalty += 3;
                    } elseif ($runLen > 5) {
                        $penalty++;
                    }
                } else {
                    if ($h0 === 0) {
                        $runLen += $size;
                    }
                    $h6 = $h5; $h5 = $h4; $h4 = $h3; $h3 = $h2; $h2 = $h1; $h1 = $h0; $h0 = $runLen;

                    if (!$runColor) {
                        if ($h1 > 0 && $h2 === $h1 && $h3 === $h1 * 3 && $h4 === $h1 && $h5 === $h1) {
                            if ($h0 >= $h1 * 4 && $h6 >= $h1) {
                                $penalty += 40;
                            }
                            if ($h6 >= $h1 * 4 && $h0 >= $h1) {
                                $penalty += 40;
                            }
                        }
                    }

                    $runColor = $c;
                    $runLen = 1;
                }
            }

            // finderPenaltyTerminateAndCount inline
            if ($runColor) {
                if ($h0 === 0) {
                    $runLen += $size;
                }
                $h6 = $h5; $h5 = $h4; $h4 = $h3; $h3 = $h2; $h2 = $h1; $h1 = $h0; $h0 = $runLen;
                $runLen = 0;
            }
            $runLen += $size;
            if ($h0 === 0) {
                $runLen += $size;
            }
            $h6 = $h5; $h5 = $h4; $h4 = $h3; $h3 = $h2; $h2 = $h1; $h1 = $h0; $h0 = $runLen;
            if ($h1 > 0 && $h2 === $h1 && $h3 === $h1 * 3 && $h4 === $h1 && $h5 === $h1) {
                if ($h0 >= $h1 * 4 && $h6 >= $h1) {
                    $penalty += 40;
                }
                if ($h6 >= $h1 * 4 && $h0 >= $h1) {
                    $penalty += 40;
                }
            }
        }

        // === Rule 2: 2×2 blocks of same color ===
        $sizeM1 = $size - 1;
        for ($y = 0; $y < $sizeM1; $y++) {
            $rowOffset = $y * $size;
            $nextRowOffset = ($y + 1) * $size;
            for ($x = 0; $x < $sizeM1; $x++) {
                $bit = $modules[$rowOffset + $x];
                if ($modules[$rowOffset + $x + 1] === $bit &&
                    $modules[$nextRowOffset + $x] === $bit &&
                    $modules[$nextRowOffset + $x + 1] === $bit) {
                    $penalty += 3;
                }
            }
        }

        // === Rule 4: Dark/light balance (nayuki integer formula) ===
        $totalModules = $size * $size;
        $k = intdiv(abs($darkCount * 20 - $totalModules * 10) + $totalModules - 1, $totalModules) - 1;
        $penalty += max($k, 0) * 10;

        return $penalty;
    }

    /**
     * Build a flat int[] grid of masked module values (0 or 1).
     * Only data (non-reserved) modules get the mask XOR applied.
     * Reserved modules keep their raw value from the matrix.
     *
     * @return int[] Flat array of size*size, values 0 or 1
     */
    private function buildMaskedGrid(Matrix $matrix, int $size, int $maskPattern, array $reserved): array
    {
        $rawData = $matrix->getRawData();
        $total = $size * $size;
        $grid = array_fill(0, $total, 0);

        for ($y = 0; $y < $size; $y++) {
            $rowOffset = $y * $size;

            // Pre-compute mask-pattern-dependent values for this row
            $yMod2 = $y % 2;
            $yDiv2 = intdiv($y, 2);

            for ($x = 0; $x < $size; $x++) {
                $idx = $rowOffset + $x;
                $bit = $rawData[$idx];

                if (!$reserved[$idx]) {
                    // Apply mask only to data modules
                    $condition = match ($maskPattern) {
                        0 => ($x + $y) % 2 === 0,
                        1 => $yMod2 === 0,
                        2 => $x % 3 === 0,
                        3 => ($x + $y) % 3 === 0,
                        4 => ($yDiv2 + intdiv($x, 3)) % 2 === 0,
                        5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                        6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                        7 => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
                        default => false,
                    };

                    $grid[$idx] = ($condition ? !$bit : $bit) ? 1 : 0;
                } else {
                    $grid[$idx] = $bit ? 1 : 0;
                }
            }
        }

        return $grid;
    }
}
