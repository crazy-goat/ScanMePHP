<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer\Options;

final class AsciiOptions extends AbstractRenderOptions
{
    /**
     * Text renderers ignore moduleSize and the colours: a character cell is
     * the module, and a terminal supplies the palette. They are accepted so a
     * caller can reuse one option bag across formats without special-casing.
     *
     * @param int $sideMargin Extra background columns on the left and right,
     *        on top of the symbology's quiet zone.
     */
    public function __construct(
        ?int $quietZone = null,
        ?int $barHeight = null,
        bool $invert = false,
        ?string $label = null,
        bool $showText = true,
        public readonly int $sideMargin = 0,
    ) {
        if ($this->sideMargin < 0) {
            throw new \InvalidArgumentException('Side margin cannot be negative, got ' . $this->sideMargin);
        }

        parent::__construct(
            moduleSize: 1,
            quietZone: $quietZone,
            barHeight: $barHeight,
            invert: $invert,
            label: $label,
            showText: $showText,
        );
    }
}
