<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Matrix;

class MaskSelector
{
    public function selectBestMask(Matrix $matrix, ErrorCorrectionLevel $ecl): int
    {
        $bestMask = 0;
        $bestScore = PHP_INT_MAX;
        $size = $matrix->getSize();
        $reserved = $matrix->getReservedBitmap();

        for ($mask = 0; $mask < 8; $mask++) {
            $score = $this->evaluatePenalty($matrix, $size, $mask, $reserved, $ecl);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMask = $mask;
            }
        }

        return $bestMask;
    }

    public function evaluateMask(Matrix $matrix, int $maskPattern, ErrorCorrectionLevel $ecl): int
    {
        $size = $matrix->getSize();
        $reserved = $matrix->getReservedBitmap();
        return $this->evaluatePenalty($matrix, $size, $maskPattern, $reserved, $ecl);
    }

    private function evaluatePenalty(Matrix $matrix, int $size, int $maskPattern, array $reserved, ErrorCorrectionLevel $ecl): int
    {
        $modules = $this->buildMaskedGrid($matrix, $size, $maskPattern, $reserved, $ecl);

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

    private function buildMaskedGrid(Matrix $matrix, int $size, int $maskPattern, array $reserved, ErrorCorrectionLevel $ecl): array
    {
        $rawData = $matrix->getRawData();
        $total = $size * $size;
        $grid = array_fill(0, $total, 0);

        for ($y = 0; $y < $size; $y++) {
            $rowOffset = $y * $size;
            $yMod2 = $y % 2;
            $yDiv2 = intdiv($y, 2);

            for ($x = 0; $x < $size; $x++) {
                $idx = $rowOffset + $x;
                $bit = $rawData[$idx];

                if (!$reserved[$idx]) {
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

        $this->applyFormatInfoToGrid($grid, $size, $ecl, $maskPattern);

        return $grid;
    }

    private function applyFormatInfoToGrid(array &$grid, int $size, ErrorCorrectionLevel $ecl, int $maskPattern): void
    {
        $formatBits = $this->computeFormatBits($ecl, $maskPattern);

        $grid[0 * $size + 8] = ($formatBits >> 0) & 1;
        $grid[1 * $size + 8] = ($formatBits >> 1) & 1;
        $grid[2 * $size + 8] = ($formatBits >> 2) & 1;
        $grid[3 * $size + 8] = ($formatBits >> 3) & 1;
        $grid[4 * $size + 8] = ($formatBits >> 4) & 1;
        $grid[5 * $size + 8] = ($formatBits >> 5) & 1;
        $grid[7 * $size + 8] = ($formatBits >> 6) & 1;
        $grid[8 * $size + 8] = ($formatBits >> 7) & 1;
        $grid[8 * $size + 7] = ($formatBits >> 8) & 1;
        $grid[8 * $size + 5] = ($formatBits >> 9) & 1;
        $grid[8 * $size + 4] = ($formatBits >> 10) & 1;
        $grid[8 * $size + 3] = ($formatBits >> 11) & 1;
        $grid[8 * $size + 2] = ($formatBits >> 12) & 1;
        $grid[8 * $size + 1] = ($formatBits >> 13) & 1;
        $grid[8 * $size + 0] = ($formatBits >> 14) & 1;

        for ($i = 0; $i < 8; $i++) {
            $grid[8 * $size + ($size - 1 - $i)] = ($formatBits >> $i) & 1;
        }
        for ($i = 8; $i < 15; $i++) {
            $grid[($size - 15 + $i) * $size + 8] = ($formatBits >> $i) & 1;
        }
    }

    private function computeFormatBits(ErrorCorrectionLevel $ecl, int $maskPattern): int
    {
        $eccBits = match ($ecl) {
            ErrorCorrectionLevel::Low => 0b01,
            ErrorCorrectionLevel::Medium => 0b00,
            ErrorCorrectionLevel::Quartile => 0b11,
            ErrorCorrectionLevel::High => 0b10,
        };

        $data = ($eccBits << 3) | $maskPattern;
        $format = $data << 10;

        for ($i = 14; $i >= 10; $i--) {
            if (($format >> $i) & 1) {
                $format ^= 0x537 << ($i - 10);
            }
        }

        return (($data << 10) | $format) ^ 0x5412;
    }
}
