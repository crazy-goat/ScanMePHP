<?php

declare(strict_types=1);

namespace ScanMePHP\Encoding;

use ScanMePHP\ErrorCorrectionLevel;
use ScanMePHP\Matrix;

class MatrixBuilder
{
    /**
     * Build a complete QR matrix (backward-compatible entry point).
     */
    public function build(
        int $version,
        array $dataCodewords,
        array $eccCodewords,
        ErrorCorrectionLevel $errorCorrectionLevel,
        int $maskPattern
    ): Matrix {
        $matrix = $this->buildBase($version, $errorCorrectionLevel, $maskPattern);
        $this->placeData($matrix, array_merge($dataCodewords, $eccCodewords), $maskPattern);
        return $matrix;
    }

    /**
     * Build the base matrix with all function patterns but NO data modules.
     * This can be reused across mask evaluations.
     */
    public function buildBase(
        int $version,
        ErrorCorrectionLevel $errorCorrectionLevel,
        int $maskPattern
    ): Matrix {
        $matrix = new Matrix($version);

        $this->addFinderPatterns($matrix);
        $this->addSeparators($matrix);
        $this->addTimingPatterns($matrix);
        $this->addAlignmentPatterns($matrix);
        $this->addDarkModule($matrix);
        $this->addFormatInfo($matrix, $errorCorrectionLevel, $maskPattern);

        if ($version >= 7) {
            $this->addVersionInfo($matrix);
        }

        return $matrix;
    }

    /**
     * Apply data codewords + mask to a matrix clone.
     * Uses pre-computed reserved bitmap for O(1) reserved checks.
     */
    public function applyDataAndMask(Matrix $baseMatrix, array $allCodewords, int $maskPattern, ErrorCorrectionLevel $ecl): Matrix
    {
        $matrix = $baseMatrix->clone();

        // Re-apply format info for this specific mask pattern
        $this->addFormatInfo($matrix, $ecl, $maskPattern);

        $this->placeData($matrix, $allCodewords, $maskPattern);
        return $matrix;
    }

    /**
     * Place data codewords WITHOUT applying any mask.
     * Returns a NEW matrix with raw (unmasked) data for correct mask evaluation.
     */
    public function placeDataUnmasked(Matrix $baseMatrix, array $allCodewords, ErrorCorrectionLevel $ecl): Matrix
    {
        $matrix = $baseMatrix->clone();
        $this->placeDataUnmaskedInPlace($matrix, $allCodewords, $ecl);
        return $matrix;
    }

    /**
     * Place data codewords WITHOUT applying any mask, modifying the matrix IN-PLACE.
     * Eliminates clone overhead when the base matrix is not reused.
     */
    public function placeDataUnmaskedInPlace(Matrix $matrix, array $allCodewords, ErrorCorrectionLevel $ecl): void
    {
        // Format info with mask=0 as placeholder (will be overwritten by applyMaskInPlace)
        $this->addFormatInfo($matrix, $ecl, 0);

        $size = $matrix->getSize();
        $reserved = $matrix->getReservedBitmap();
        $codewordCount = count($allCodewords);
        $bitIndex = 0;

        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }

            $up = ((($size - 1 - $col) >> 1) & 1) === 0;

            for ($row = $up ? $size - 1 : 0; $up ? $row >= 0 : $row < $size; $up ? $row-- : $row++) {
                for ($c = 0; $c < 2; $c++) {
                    $x = $col - $c;
                    $y = $row;
                    $idx = $y * $size + $x;

                    if (!$reserved[$idx]) {
                        $byteIndex = $bitIndex >> 3;
                        $bitOffset = 7 - ($bitIndex & 7);

                        if ($byteIndex < $codewordCount) {
                            $matrix->fastSet($x, $y, (($allCodewords[$byteIndex] >> $bitOffset) & 1) === 1);
                        }

                        $bitIndex++;
                    }
                }
            }
        }
    }

    /**
     * Apply mask + correct format info to a matrix IN-PLACE.
     * Uses int-packed XOR rows from MaskSelector cache for bulk application.
     * The matrix is modified directly — no clone overhead.
     *
     * @param int[]|null $maskXorRows Pre-computed XOR rows from MaskSelector cache.
     *                                If null, falls back to per-module closure (slower).
     */
    public function applyMaskInPlace(Matrix $matrix, int $maskPattern, ErrorCorrectionLevel $ecl, ?array $maskXorRows = null): void
    {
        $size = $matrix->getSize();

        // Apply correct format info for this mask
        $this->addFormatInfo($matrix, $ecl, $maskPattern);

        if ($maskXorRows !== null) {
            // Fast path: apply int-packed XOR directly to Matrix internals — zero COW copy
            $matrix->applyXorMask($maskXorRows);
        } else {
            // Slow fallback: per-module closure
            $reserved = $matrix->getReservedBitmap();
            $maskFn = match ($maskPattern) {
                0 => static fn(int $x, int $y): bool => (($x + $y) & 1) === 0,
                1 => static fn(int $x, int $y): bool => ($y & 1) === 0,
                2 => static fn(int $x, int $y): bool => ($x % 3) === 0,
                3 => static fn(int $x, int $y): bool => (($x + $y) % 3) === 0,
                4 => static fn(int $x, int $y): bool => ((($y >> 1) + (int)($x / 3)) & 1) === 0,
                5 => static function (int $x, int $y): bool { $xy = $x * $y; return ($xy & 1) + $xy % 3 === 0; },
                6 => static function (int $x, int $y): bool { $xy = $x * $y; return ((($xy & 1) + $xy % 3) & 1) === 0; },
                7 => static function (int $x, int $y): bool { return (((($x + $y) & 1) + ($x * $y) % 3) & 1) === 0; },
                default => static fn(int $x, int $y): bool => false,
            };

            for ($y = 0; $y < $size; $y++) {
                $rowOffset = $y * $size;
                for ($x = 0; $x < $size; $x++) {
                    $idx = $rowOffset + $x;
                    if (!$reserved[$idx] && $maskFn($x, $y)) {
                        $matrix->fastSet($x, $y, !$matrix->fastGet($x, $y));
                    }
                }
            }
        }
    }

    private function addFinderPatterns(Matrix $matrix): void
    {
        $size = $matrix->getSize();

        // Finder pattern (7x7) — hardcoded for speed
        $pattern = [
            0b1111111,
            0b1000001,
            0b1011101,
            0b1011101,
            0b1011101,
            0b1000001,
            0b1111111,
        ];

        for ($y = 0; $y < 7; $y++) {
            $bits = $pattern[$y];
            for ($x = 0; $x < 7; $x++) {
                $val = (bool)(($bits >> (6 - $x)) & 1);
                $matrix->fastSet($x, $y, $val);                           // top-left
                $matrix->fastSet($size - 7 + $x, $y, $val);               // top-right
                $matrix->fastSet($x, $size - 7 + $y, $val);               // bottom-left
            }
        }
    }

    private function addSeparators(Matrix $matrix): void
    {
        $size = $matrix->getSize();

        for ($i = 0; $i < 8; $i++) {
            // Top-left
            $matrix->fastSet($i, 7, false);
            $matrix->fastSet(7, $i, false);
            // Top-right
            $matrix->fastSet($size - 8 + $i, 7, false);
            $matrix->fastSet($size - 8, $i, false);
            // Bottom-left
            $matrix->fastSet($i, $size - 8, false);
            $matrix->fastSet(7, $size - 8 + $i, false);
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
        $size = $matrix->getSize();

        // Alignment pattern (5x5) as bitmask rows
        $pattern = [
            0b11111,
            0b10001,
            0b10101,
            0b10001,
            0b11111,
        ];

        foreach ($positions as $cy) {
            foreach ($positions as $cx) {
                if ($this->overlapsFinderPattern($cx, $cy, $size)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    $bits = $pattern[$dy + 2];
                    $py = $cy + $dy;
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $matrix->fastSet($cx + $dx, $py, (bool)(($bits >> (2 - $dx)) & 1));
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

        for ($i = 8; $i < $size - 8; $i++) {
            $val = ($i & 1) === 0;
            $matrix->fastSet($i, 6, $val); // horizontal
            $matrix->fastSet(6, $i, $val); // vertical
        }
    }

    private function addDarkModule(Matrix $matrix): void
    {
        $matrix->fastSet(8, 4 * $matrix->getVersion() + 9, true);
    }

    private function addFormatInfo(Matrix $matrix, ErrorCorrectionLevel $level, int $maskPattern): void
    {
        $size = $matrix->getSize();
        $formatBits = $this->getFormatBits($level, $maskPattern);

        // Top-left format info
        $matrix->fastSet(8, 0, (bool)(($formatBits >> 0) & 1));
        $matrix->fastSet(8, 1, (bool)(($formatBits >> 1) & 1));
        $matrix->fastSet(8, 2, (bool)(($formatBits >> 2) & 1));
        $matrix->fastSet(8, 3, (bool)(($formatBits >> 3) & 1));
        $matrix->fastSet(8, 4, (bool)(($formatBits >> 4) & 1));
        $matrix->fastSet(8, 5, (bool)(($formatBits >> 5) & 1));
        $matrix->fastSet(8, 7, (bool)(($formatBits >> 6) & 1));
        $matrix->fastSet(8, 8, (bool)(($formatBits >> 7) & 1));
        $matrix->fastSet(7, 8, (bool)(($formatBits >> 8) & 1));
        $matrix->fastSet(5, 8, (bool)(($formatBits >> 9) & 1));
        $matrix->fastSet(4, 8, (bool)(($formatBits >> 10) & 1));
        $matrix->fastSet(3, 8, (bool)(($formatBits >> 11) & 1));
        $matrix->fastSet(2, 8, (bool)(($formatBits >> 12) & 1));
        $matrix->fastSet(1, 8, (bool)(($formatBits >> 13) & 1));
        $matrix->fastSet(0, 8, (bool)(($formatBits >> 14) & 1));

        // Top-right and bottom-left
        for ($i = 0; $i < 8; $i++) {
            $matrix->fastSet($size - 1 - $i, 8, (bool)(($formatBits >> $i) & 1));
        }
        for ($i = 8; $i < 15; $i++) {
            $matrix->fastSet(8, $size - 15 + $i, (bool)(($formatBits >> $i) & 1));
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
        $generator = 0x537;
        $format = $data << 10;

        for ($i = 14; $i >= 10; $i--) {
            if (($format >> $i) & 1) {
                $format ^= $generator << ($i - 10);
            }
        }

        return (($data << 10) | $format) ^ 0x5412;
    }

    private function addVersionInfo(Matrix $matrix): void
    {
        $version = $matrix->getVersion();
        $size = $matrix->getSize();
        $versionBits = $this->getVersionBits($version);

        for ($i = 0; $i < 18; $i++) {
            $bit = (bool)(($versionBits >> $i) & 1);
            $row = (int)($i / 3);
            $col = $i % 3;
            $matrix->fastSet($row, $size - 11 + $col, $bit);
            $matrix->fastSet($size - 11 + $col, $row, $bit);
        }
    }

    private function getVersionBits(int $version): int
    {
        $data = $version;
        $generator = 0x1f25;
        $versionInfo = $data << 12;

        for ($i = 17; $i >= 12; $i--) {
            if (($versionInfo >> $i) & 1) {
                $versionInfo ^= $generator << ($i - 12);
            }
        }

        return ($data << 12) | $versionInfo;
    }

    /**
     * Place data codewords into the matrix using pre-computed reserved bitmap.
     */
    private function placeData(Matrix $matrix, array $codewords, int $maskPattern): void
    {
        $size = $matrix->getSize();
        $reserved = $matrix->getReservedBitmap();
        $codewordCount = count($codewords);
        $bitIndex = 0;

        // Pre-compute mask function as a closure for this specific pattern
        // This avoids match() on every single module
        $maskFn = match ($maskPattern) {
            0 => static fn(int $x, int $y): bool => (($x + $y) & 1) === 0,
            1 => static fn(int $x, int $y): bool => ($y & 1) === 0,
            2 => static fn(int $x, int $y): bool => ($x % 3) === 0,
            3 => static fn(int $x, int $y): bool => (($x + $y) % 3) === 0,
            4 => static fn(int $x, int $y): bool => ((($y >> 1) + (int)($x / 3)) & 1) === 0,
            5 => static function (int $x, int $y): bool { $xy = $x * $y; return ($xy & 1) + $xy % 3 === 0; },
            6 => static function (int $x, int $y): bool { $xy = $x * $y; return ((($xy & 1) + $xy % 3) & 1) === 0; },
            7 => static function (int $x, int $y): bool { return (((($x + $y) & 1) + ($x * $y) % 3) & 1) === 0; },
            default => static fn(int $x, int $y): bool => false,
        };

        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }

            $up = ((($size - 1 - $col) >> 1) & 1) === 0;

            for ($row = $up ? $size - 1 : 0; $up ? $row >= 0 : $row < $size; $up ? $row-- : $row++) {
                for ($c = 0; $c < 2; $c++) {
                    $x = $col - $c;
                    $y = $row;
                    $idx = $y * $size + $x;

                    if (!$reserved[$idx]) {
                        $byteIndex = $bitIndex >> 3;
                        $bitOffset = 7 - ($bitIndex & 7);

                        if ($byteIndex < $codewordCount) {
                            $bit = (($codewords[$byteIndex] >> $bitOffset) & 1) === 1;
                        } else {
                            $bit = false; // Remainder bits are 0
                        }
                        $bit = $maskFn($x, $y) ? !$bit : $bit;
                        $matrix->fastSet($x, $y, $bit);

                        $bitIndex++;
                    }
                }
            }
        }
    }
}
