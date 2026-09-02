<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer\Options;

use CrazyGoat\ScanMePHP\ModuleStyle;

final class SvgOptions extends AbstractRenderOptions
{
    /**
     * @param bool $roundFinderRegions Draw the structurally special regions a
     *        symbology reports — the QR finder patterns — with rounded corners.
     *        Has no effect on symbologies that report none.
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
        public readonly ModuleStyle $moduleStyle = ModuleStyle::Square,
        public readonly bool $roundFinderRegions = true,
    ) {
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
