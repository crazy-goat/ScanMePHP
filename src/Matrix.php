<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * The QR encoders' mutable working grid.
 *
 * @internal Not part of the public API: its packed-row and reserved-bitmap
 *           accessors exist for bitwise mask selection and are free to change
 *           with any optimisation pass. Public code receives a Symbol.
 */
class Matrix
{
    /**
     * Flat size*size module storage indexed as [y * size + x]; truthiness of
     * an element marks a dark module. Three representations share the same
     * read path (`(bool) $this->data[$i]`):
     *   - bool[]           — pure-PHP encoders, and after normalize();
     *   - int[] of 0/1     — straight from unpack() (FastEncoder);
     *   - string of '0'/'1' — native encoders (ext / FFI) hand in one byte per
     *                        module, which avoids building size*size zvals
     *                        (~3 µs of a 9 µs encode at v10, ~35 µs at v40).
     * Any write goes through normalize() first, so mutation always sees bool[].
     *
     * @var list<bool|int>|string
     */
    private array|string $data;
    private readonly int $version;
    private readonly int $size;

    /** @var bool[]|null Lazily computed reserved bitmap (flat) */
    private ?array $reserved = null;

    /** False while $data may still be a '0'/'1' string or hold 0/1 ints. */
    private bool $normalized;

    /** Cached toModuleString() result for array-backed data; reset on every write. */
    private ?string $moduleString = null;

    /**
     * @param list<bool|int>|null $data Prefilled flat module data (size*size
     *        entries, [y * size + x]); null allocates an all-light matrix.
     * @param bool $normalized Pass false when $data holds 0/1 ints instead of
     *        bools; the public raw getters then convert lazily on first use.
     */
    public function __construct(int $version, ?array $data = null, bool $normalized = true)
    {
        $this->version = $version;
        $this->size = 17 + ($version * 4);
        $this->data = $data ?? array_fill(0, $this->size * $this->size, false);
        $this->normalized = $data === null || $normalized;
    }

    /**
     * Build a matrix from one byte per module, '0' = light, '1' = dark,
     * ordered [y * size + x]. This is the zero-copy entry point for the native
     * encoders: the string is stored as-is and only expanded to bool[] if a
     * caller mutates the matrix or asks for the raw array.
     *
     * @internal Used by NativeEncoder (php-ext) and FfiEncoder.
     */
    public static function fromModuleString(int $version, string $modules): self
    {
        $matrix = new self($version, [], normalized: false);
        if (\strlen($modules) !== $matrix->size * $matrix->size) {
            throw new \InvalidArgumentException(sprintf(
                'Module string for version %d must hold %d bytes, got %d',
                $version,
                $matrix->size * $matrix->size,
                \strlen($modules)
            ));
        }
        $matrix->data = $modules;

        return $matrix;
    }

    /**
     * Ensure $data is strictly bool[] before exposing or mutating it.
     * Renderers only go through get()/fastGet() (which cast), so this runs at
     * most once and only for matrices built from native modules.
     */
    private function normalize(): void
    {
        if ($this->normalized) {
            return;
        }
        if (\is_string($this->data)) {
            /** @var list<int> $ints */
            $ints = array_values((array) unpack('C*', strtr($this->data, '01', "\0\1")));
            $this->data = array_map(boolval(...), $ints);
        } else {
            $this->data = array_map(boolval(...), $this->data);
        }
        $this->normalized = true;
    }

    /**
     * One byte per module, '0' = light, '1' = dark, ordered [y * size + x].
     * This is the form the renderers consume: a row is a substr(), a glyph or
     * markup mapping is a strtr(), dark runs are a preg_match_all() — all C
     * loops instead of one method call per module. Free for matrices built by
     * the native encoders; one pack()+strtr() pass (~5 µs at v10) otherwise.
     */
    public function toModuleString(): string
    {
        if (\is_string($this->data)) {
            return $this->data;
        }
        if ($this->moduleString === null) {
            // int[] straight from unpack() implodes to '0'/'1' as-is (12 µs at
            // v10); bool[] needs pack() first, since false implodes to ''.
            $this->moduleString = $this->normalized
                ? strtr(pack('C*', ...$this->data), "\0\1", '01')
                : implode('', $this->data);
        }

        return $this->moduleString;
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
        return (bool) $this->data[$y * $this->size + $x];
    }

    public function set(int $x, int $y, bool $value): void
    {
        if ($x >= 0 && $x < $this->size && $y >= 0 && $y < $this->size) {
            $this->normalize();
            $this->moduleString = null;
            $this->data[$y * $this->size + $x] = $value;
        }
    }

    /**
     * Fast inline get — no bounds check. Caller must guarantee valid coords.
     */
    public function fastGet(int $x, int $y): bool
    {
        return (bool) $this->data[$y * $this->size + $x];
    }

    /**
     * Fast inline set — no bounds check. Caller must guarantee valid coords.
     * Matrices built from native module strings pay a one-off normalize() on
     * the first write; the pure-PHP pipeline never does.
     */
    public function fastSet(int $x, int $y, bool $value): void
    {
        if (!$this->normalized) {
            $this->normalize();
        }
        $this->moduleString = null;
        $this->data[$y * $this->size + $x] = $value;
    }

    /**
     * Get the raw flat data array. For high-performance iteration.
     *
     * @return list<bool>
     */
    public function getRawData(): array
    {
        $this->normalize();
        \assert(\is_array($this->data));
        return $this->data;
    }

    /**
     * Set the raw flat data array. For high-performance bulk operations.
     */
    public function setRawData(array $data): void
    {
        $this->data = $data;
        $this->normalized = true;
        $this->moduleString = null;
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
        $this->normalize();
        $this->moduleString = null;
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
        $this->normalize();
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
        $this->normalized = true;
        $this->moduleString = null;
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
                    if ($cx <= 8 && $cy <= 8) {
                        continue;
                    }
                    if ($cx >= $sizeM8 && $cy <= 8) {
                        continue;
                    }
                    if ($cx <= 8 && $cy >= $sizeM8) {
                        continue;
                    }

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
        $clone->normalized = $this->normalized;
        $clone->moduleString = $this->moduleString;
        $clone->reserved = $this->reserved;
        return $clone;
    }
}
