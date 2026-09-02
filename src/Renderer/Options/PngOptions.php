<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer\Options;

final class PngOptions extends AbstractRenderOptions
{
    /**
     * @param int $compressionLevel zlib level 0–9 for the IDAT stream.
     *        Scanlines are stored with the PNG "Up" filter, so every repeated
     *        pixel row is all zeros; level 1 already shrinks that to a few KB
     *        and is ~7× faster than zlib's default 6 (QR v10: 31 vs 206 µs for
     *        2.4 vs 1.5 KB).
     */
    public function __construct(
        int $moduleSize = 10,
        ?int $quietZone = null,
        ?int $barHeight = null,
        string $foregroundColor = '#000000',
        string $backgroundColor = '#FFFFFF',
        bool $invert = false,
        ?string $label = null,
        bool $showText = true,
        public readonly int $compressionLevel = 1,
    ) {
        if ($this->compressionLevel < 0 || $this->compressionLevel > 9) {
            throw new \InvalidArgumentException(
                'PNG compression level must be between 0 and 9, got ' . $this->compressionLevel
            );
        }

        parent::__construct(
            $moduleSize,
            $quietZone,
            $barHeight,
            $foregroundColor,
            $backgroundColor,
            $invert,
            $label,
            $showText,
        );
    }
}
