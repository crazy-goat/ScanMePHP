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
        
        for ($mask = 0; $mask < 8; $mask++) {
            $score = $this->evaluateMask($matrix, $mask);
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
        $penalty = 0;
        
        // Rule 1: Adjacent modules in row/column
        $penalty += $this->evaluateRule1($matrix, $size, $maskPattern);
        
        // Rule 2: Block of modules in same color
        $penalty += $this->evaluateRule2($matrix, $size, $maskPattern);
        
        // Rule 3: Patterns resembling finder patterns
        $penalty += $this->evaluateRule3($matrix, $size, $maskPattern);
        
        // Rule 4: Balance of dark and light modules
        $penalty += $this->evaluateRule4($matrix, $size, $maskPattern);
        
        return $penalty;
    }

    private function evaluateRule1(Matrix $matrix, int $size, int $maskPattern): int
    {
        $penalty = 0;
        
        // Check rows
        for ($y = 0; $y < $size; $y++) {
            $count = 1;
            $prevBit = $this->getMaskedBit($matrix, 0, $y, $maskPattern);
            
            for ($x = 1; $x < $size; $x++) {
                $bit = $this->getMaskedBit($matrix, $x, $y, $maskPattern);
                if ($bit === $prevBit) {
                    $count++;
                } else {
                    if ($count >= 5) {
                        $penalty += 3 + ($count - 5);
                    }
                    $count = 1;
                    $prevBit = $bit;
                }
            }
            if ($count >= 5) {
                $penalty += 3 + ($count - 5);
            }
        }
        
        // Check columns
        for ($x = 0; $x < $size; $x++) {
            $count = 1;
            $prevBit = $this->getMaskedBit($matrix, $x, 0, $maskPattern);
            
            for ($y = 1; $y < $size; $y++) {
                $bit = $this->getMaskedBit($matrix, $x, $y, $maskPattern);
                if ($bit === $prevBit) {
                    $count++;
                } else {
                    if ($count >= 5) {
                        $penalty += 3 + ($count - 5);
                    }
                    $count = 1;
                    $prevBit = $bit;
                }
            }
            if ($count >= 5) {
                $penalty += 3 + ($count - 5);
            }
        }
        
        return $penalty;
    }

    private function evaluateRule2(Matrix $matrix, int $size, int $maskPattern): int
    {
        $penalty = 0;
        
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $bit = $this->getMaskedBit($matrix, $x, $y, $maskPattern);
                if ($this->getMaskedBit($matrix, $x + 1, $y, $maskPattern) === $bit &&
                    $this->getMaskedBit($matrix, $x, $y + 1, $maskPattern) === $bit &&
                    $this->getMaskedBit($matrix, $x + 1, $y + 1, $maskPattern) === $bit) {
                    $penalty += 3;
                }
            }
        }
        
        return $penalty;
    }

    private function evaluateRule3(Matrix $matrix, int $size, int $maskPattern): int
    {
        $penalty = 0;
        
        // Pattern: dark-light-dark-dark-dark-light-dark (1:1:3:1:1 ratio)
        $pattern1 = [1, 0, 1, 1, 1, 0, 1];
        $pattern2 = [1, 0, 1, 1, 1, 0, 1, 0, 1]; // With 4 light modules on either side
        
        // Check rows
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x <= $size - 7; $x++) {
                if ($this->matchesPattern($matrix, $x, $y, $pattern1, true, $maskPattern) ||
                    $this->matchesPattern($matrix, $x, $y, $pattern2, true, $maskPattern)) {
                    $penalty += 40;
                }
            }
        }
        
        // Check columns
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y <= $size - 7; $y++) {
                if ($this->matchesPattern($matrix, $x, $y, $pattern1, false, $maskPattern) ||
                    $this->matchesPattern($matrix, $x, $y, $pattern2, false, $maskPattern)) {
                    $penalty += 40;
                }
            }
        }
        
        return $penalty;
    }

    private function evaluateRule4(Matrix $matrix, int $size, int $maskPattern): int
    {
        $darkCount = 0;
        $totalModules = $size * $size;
        
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($this->getMaskedBit($matrix, $x, $y, $maskPattern)) {
                    $darkCount++;
                }
            }
        }
        
        $percentage = ($darkCount * 100) / $totalModules;
        $prevMultiple = (int) (abs($percentage - 50) / 5) * 5;
        
        return $prevMultiple * 10;
    }

    private function getMaskedBit(Matrix $matrix, int $x, int $y, int $maskPattern): bool
    {
        $bit = $matrix->get($x, $y);
        
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

    private function matchesPattern(
        Matrix $matrix,
        int $startX,
        int $startY,
        array $pattern,
        bool $horizontal,
        int $maskPattern
    ): bool {
        for ($i = 0; $i < count($pattern); $i++) {
            $x = $horizontal ? $startX + $i : $startX;
            $y = $horizontal ? $startY : $startY + $i;
            
            $expected = (bool) $pattern[$i];
            $actual = $this->getMaskedBit($matrix, $x, $y, $maskPattern);
            
            if ($expected !== $actual) {
                return false;
            }
        }
        
        return true;
    }
}
