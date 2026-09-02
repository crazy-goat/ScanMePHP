<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * A render-ready barcode: an immutable width × height grid of light/dark
 * modules plus the presentation facts a renderer needs but cannot derive.
 *
 * This is the only type that crosses the generator → renderer boundary, and it
 * is deliberately symbology-agnostic: nothing here knows about QR versions,
 * EAN check digits or error correction. Anything symbology-specific a caller
 * may still want travels in $metadata.
 *
 * Module storage keeps the three interchangeable representations the encoders
 * naturally produce, all read through toModuleString():
 *   - bool[]            — pure-PHP generators;
 *   - int[] of 0/1      — straight from unpack() (QR bitset backend);
 *   - string of '0'/'1' — native backends (ext / FFI) hand over one byte per
 *                         module, which avoids materialising width*height
 *                         zvals (~3 µs of a 9 µs QR encode at v10, ~35 µs at
 *                         v40).
 * The symbol is immutable, so unlike the mutable QR-internal Matrix it never
 * has to normalise storage back to bool[] in order to write.
 */
final class Symbol
{
    /** @var list<bool|int>|string */
    private readonly array|string $modules;

    /** @var list<int> One height weight per module row; see getRowHeights(). */
    private readonly array $rowHeights;

    /** Cached toModuleString() result for array-backed storage. */
    private ?string $moduleString = null;

    /**
     * @param list<bool|int>|string $modules Flat width*height grid indexed as
     *        [y * width + x]; truthiness (or the byte '1') marks a dark module.
     * @param list<int>|null $rowHeights Relative height of each module row, in
     *        module units — see getRowHeights(). Null means every row is one
     *        module tall, which is what every matrix symbology wants.
     * @param string|null $text Human-readable interpretation to print beneath
     *        the symbol (the digits under an EAN barcode). Null when the
     *        symbology has no such convention.
     * @param list<Region> $finderRegions Structurally special module rectangles
     *        a renderer may draw differently; empty for symbologies with none.
     * @param array<string, int|string|bool> $metadata Symbology-specific facts
     *        for callers that care, e.g. ['version' => 10] for QR.
     */
    public function __construct(
        private readonly int $width,
        private readonly int $height,
        array|string $modules,
        private readonly Dimension $dimension = Dimension::Matrix,
        private readonly ModuleShape $moduleShape = ModuleShape::Square,
        private readonly QuietZone $quietZone = new QuietZone(),
        ?array $rowHeights = null,
        private readonly ?string $text = null,
        private readonly array $finderRegions = [],
        private readonly array $metadata = [],
    ) {
        if ($this->width <= 0 || $this->height <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'Symbol dimensions must be positive, got %d × %d',
                $this->width,
                $this->height
            ));
        }

        $expected = $this->width * $this->height;
        $actual = \is_string($modules) ? \strlen($modules) : \count($modules);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException(sprintf(
                'Module data for a %d × %d symbol must hold %d entries, got %d',
                $this->width,
                $this->height,
                $expected,
                $actual
            ));
        }

        $rowHeights ??= array_fill(0, $this->height, 1);
        if (\count($rowHeights) !== $this->height) {
            throw new \InvalidArgumentException(sprintf(
                'Row heights must hold one entry per module row (%d), got %d',
                $this->height,
                \count($rowHeights)
            ));
        }
        foreach ($rowHeights as $rowHeight) {
            if ($rowHeight < 1) {
                throw new \InvalidArgumentException('Every row height must be at least 1 module');
            }
        }

        $this->modules = $modules;
        $this->rowHeights = array_values($rowHeights);
    }

    /**
     * A linear symbol from a single row of modules.
     *
     * The bar height is presentation rather than data, so it lives in the row
     * height; render options may scale it.
     *
     * @param list<bool|int>|string $modules One row, width entries long
     * @param array<string, int|string|bool> $metadata
     */
    public static function linear(
        array|string $modules,
        QuietZone $quietZone,
        int $barHeight,
        ?string $text = null,
        array $metadata = [],
    ): self {
        $width = \is_string($modules) ? \strlen($modules) : \count($modules);

        return new self(
            width: $width,
            height: 1,
            modules: $modules,
            dimension: Dimension::Linear,
            quietZone: $quietZone,
            rowHeights: [$barHeight],
            text: $text,
            metadata: $metadata,
        );
    }

    /**
     * A square matrix symbol.
     *
     * @param list<bool|int>|string $modules Flat size*size grid
     * @param list<Region> $finderRegions
     * @param array<string, int|string|bool> $metadata
     */
    public static function square(
        int $size,
        array|string $modules,
        QuietZone $quietZone,
        array $finderRegions = [],
        array $metadata = [],
    ): self {
        return new self(
            width: $size,
            height: $size,
            modules: $modules,
            dimension: Dimension::Matrix,
            quietZone: $quietZone,
            finderRegions: $finderRegions,
            metadata: $metadata,
        );
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    /** Number of module rows. Not the rendered height — see getModuleHeight(). */
    public function getHeight(): int
    {
        return $this->height;
    }

    public function getDimension(): Dimension
    {
        return $this->dimension;
    }

    public function getModuleShape(): ModuleShape
    {
        return $this->moduleShape;
    }

    public function getQuietZone(): QuietZone
    {
        return $this->quietZone;
    }

    /**
     * Height of each module row, in module units.
     *
     * Every matrix symbology returns all 1s. Linear symbologies put their bar
     * height here (one row, e.g. 60). Four-state postal codes — Intelligent
     * Mail, RM4SCC, Australia Post — are the reason this is per-row rather than
     * one number: their information lives in bar height, so they emit three
     * rows (ascender, tracker, descender) with the spec's differing heights and
     * let the module grid stay a plain two-level bitmap.
     *
     * @return list<int>
     */
    public function getRowHeights(): array
    {
        return $this->rowHeights;
    }

    /** Total rendered height in module units, i.e. the sum of the row heights. */
    public function getModuleHeight(): int
    {
        return array_sum($this->rowHeights);
    }

    /** True when every row is exactly one module tall — the common fast path. */
    public function hasUniformRows(): bool
    {
        return $this->getModuleHeight() === $this->height;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    /** @return list<Region> */
    public function getFinderRegions(): array
    {
        return $this->finderRegions;
    }

    /** @return array<string, int|string|bool> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getMetadataValue(string $key): int|string|bool|null
    {
        return $this->metadata[$key] ?? null;
    }

    /**
     * One byte per module, '0' = light, '1' = dark, ordered [y * width + x].
     *
     * This is the form renderers consume: a row is a substr(), a glyph or
     * markup mapping is a strtr(), dark runs are a preg_match_all() — all C
     * loops instead of one method call per module. Free for symbols built by
     * the native backends; one pack()+strtr() pass otherwise.
     */
    public function toModuleString(): string
    {
        if (\is_string($this->modules)) {
            return $this->modules;
        }

        if ($this->moduleString === null) {
            // pack() turns both bools and 0/1 ints into NUL/SOH bytes; a plain
            // implode() would drop false, which renders as a missing module.
            $this->moduleString = strtr(pack('C*', ...$this->modules), "\0\1", '01');
        }

        return $this->moduleString;
    }

    public function get(int $x, int $y): bool
    {
        if ($x < 0 || $x >= $this->width || $y < 0 || $y >= $this->height) {
            return false;
        }

        $index = ($y * $this->width) + $x;

        return \is_string($this->modules)
            ? $this->modules[$index] === '1'
            : (bool) $this->modules[$index];
    }

    /**
     * The module rows as '0'/'1' strings.
     *
     * @return list<string>
     */
    public function rows(): array
    {
        /** @var list<string> $rows */
        $rows = str_split($this->toModuleString(), $this->width);

        return $rows;
    }
}
