<?php

declare(strict_types=1);

namespace ScanMePHP;

class Matrix
{
    /** @var bool[] Flat array of size*size, indexed as [y * size + x] */
    private array $data;
    private int $version;
    private int $size;

    /** @var bool[]|null Lazily computed reserved bitmap (flat) */
    private ?array $reserved = null;

    public function __construct(int $version)
    {
        $this->version = $version;
        $this->size = 17 + ($version * 4);
        $this->data = array_fill(0, $this->size * $this->size, false);
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function get(int $x, int $y): bool
    {
        if ($x < 0 || $x >= $this->size || $y < 0 || $y >= $this->size) {
            return false;
        }
        return $this->data[$y * $this->size + $x];
    }

    public function set(int $x, int $y, bool $value): void
    {
        if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
            $this->data[$y * $this->size + $x] = $value;
        }
    }

    /**
     * Fast inline get — no bounds check. Caller must guarantee valid coords.
     */
    public function fastGet(int $x, int $y): bool
    {
        return $this->data[$y * $this->size + $x];
    }

    /**
     * Fast inline set — no bounds check. Caller must guarantee valid coords.
     */
    public function fastSet(int $x, int $y, bool $value): void
    {
        $this->data[$y * $this->size + $x] = $value;
    }

    /**
     * Get the raw flat data array. For high-performance iteration.
     */
    public function getRawData(): array
    {
        return $this->data;
    }

    /**
     * Set the raw flat data array. For high-performance bulk operations.
     */
    public function setRawData(array $data): void
    {
        $this->data = $data;
    }

    /**
     * Pack internal data into int[] rows (one int per row, MSB = leftmost column).
     * Operates directly on internal $data — no COW copy.
     * @return int[]
     */
    public function getPackedRows(): array
    {
        $size = $this->size;
        $data = $this->data;
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
     * Pack internal data into int[] columns (one int per column, MSB = topmost row).
     * Operates directly on internal $data — no COW copy.
     * @return int[]
     */
    public function getPackedCols(): array
    {
        $size = $this->size;
        $data = $this->data;
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
     * Apply int-packed XOR mask rows directly to internal data — zero COW copy.
     * Each int in $xorRows has bits set where data modules should be flipped.
     * @param int[] $xorRows One int per row, MSB = leftmost column
     */
    public function applyXorMask(array $xorRows): void
    {
        $size = $this->size;
        $sizeM1 = $size - 1;

        for ($y = 0; $y < $size; $y++) {
            $xorBits = $xorRows[$y];
            if ($xorBits === 0) {
                continue;
            }
            $rowOffset = $y * $size;
            for ($x = 0; $x < $size; $x++) {
                if (($xorBits >> ($sizeM1 - $x)) & 1) {
                    $this->data[$rowOffset + $x] = !$this->data[$rowOffset + $x];
                }
            }
        }
    }

    /**
     * Backward-compatible getData() — returns nested bool[][].
     * @return bool[][]
     */
    public function getData(): array
    {
        $result = [];
        $size = $this->size;
        for ($y = 0; $y < $size; $y++) {
            $offset = $y * $size;
            $row = [];
            for ($x = 0; $x < $size; $x++) {
                $row[] = $this->data[$offset + $x];
            }
            $result[] = $row;
        }
        return $result;
    }

    /**
     * Backward-compatible setData() — accepts nested bool[][].
     */
    public function setData(array $data): void
    {
        $size = $this->size;
        $flat = [];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $flat[] = $data[$y][$x];
            }
        }
        $this->data = $flat;
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

    /**
     * Get pre-computed reserved bitmap. Computed once, cached.
     * @return bool[] Flat array — true means module is reserved (function pattern)
     */
    public function getReservedBitmap(): array
    {
        if ($this->reserved === null) {
            $this->reserved = $this->computeReservedBitmap();
        }
        return $this->reserved;
    }

    private function computeReservedBitmap(): array
    {
        $size = $this->size;
        $version = $this->version;
        $reserved = array_fill(0, $size * $size, false);

        // Finder patterns + separators (top-left, top-right, bottom-left) — 9×9 regions
        for ($y = 0; $y < 9; $y++) {
            for ($x = 0; $x < 9; $x++) {
                $reserved[$y * $size + $x] = true; // top-left
            }
            for ($x = $size - 8; $x < $size; $x++) {
                $reserved[$y * $size + $x] = true; // top-right
            }
        }
        for ($y = $size - 8; $y < $size; $y++) {
            for ($x = 0; $x < 9; $x++) {
                $reserved[$y * $size + $x] = true; // bottom-left
            }
        }

        // Timing patterns (row 6 and column 6)
        for ($i = 8; $i < $size - 8; $i++) {
            $reserved[6 * $size + $i] = true; // horizontal
            $reserved[$i * $size + 6] = true; // vertical
        }

        // Dark module
        $reserved[(4 * $version + 9) * $size + 8] = true;

        // Format info areas
        // Already covered by the 9×9 finder regions above, but let's be explicit
        // for the edge cases that might not be covered:
        for ($i = 0; $i < 9; $i++) {
            $reserved[8 * $size + $i] = true;       // row 8, cols 0-8
            $reserved[$i * $size + 8] = true;        // col 8, rows 0-8
        }
        for ($i = $size - 8; $i < $size; $i++) {
            $reserved[8 * $size + $i] = true;        // row 8, right side
            $reserved[$i * $size + 8] = true;         // col 8, bottom side
        }

        // Version info (versions >= 7)
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = $size - 11; $j < $size - 8; $j++) {
                    $reserved[$j * $size + $i] = true;  // bottom-left block
                    $reserved[$i * $size + $j] = true;   // top-right block
                }
            }
        }

        // Alignment patterns
        if ($version >= 2) {
            $positions = self::ALIGNMENT_POSITIONS[$version];
            $sizeM8 = $size - 8;
            foreach ($positions as $cy) {
                foreach ($positions as $cx) {
                    // Skip if overlaps finder pattern
                    if ($cx <= 8 && $cy <= 8) continue;
                    if ($cx >= $sizeM8 && $cy <= 8) continue;
                    if ($cx <= 8 && $cy >= $sizeM8) continue;

                    for ($dy = -2; $dy <= 2; $dy++) {
                        $rowOffset = ($cy + $dy) * $size;
                        for ($dx = -2; $dx <= 2; $dx++) {
                            $reserved[$rowOffset + $cx + $dx] = true;
                        }
                    }
                }
            }
        }

        return $reserved;
    }

    /**
     * Legacy isReserved — delegates to pre-computed bitmap.
     */
    public function isReserved(int $x, int $y): bool
    {
        $bitmap = $this->getReservedBitmap();
        if ($x < 0 || $x >= $this->size || $y < 0 || $y >= $this->size) {
            return false;
        }
        return $bitmap[$y * $this->size + $x];
    }

    public function clone(): self
    {
        $clone = new self($this->version);
        $clone->data = $this->data;
        $clone->reserved = $this->reserved;
        return $clone;
    }
}
