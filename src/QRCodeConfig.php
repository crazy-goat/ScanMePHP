<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

use CrazyGoat\ScanMePHP\Renderer\FullBlocksRenderer;

class QRCodeConfig
{
    public function __construct(
        public readonly RendererInterface $engine = new FullBlocksRenderer(),
        public readonly ErrorCorrectionLevel $errorCorrectionLevel = ErrorCorrectionLevel::Medium,
        public readonly ?string $label = null,
        public readonly int $size = 0,  // 0 = auto
        public readonly int $margin = 4,
        public readonly string $foregroundColor = '#000000',
        public readonly string $backgroundColor = '#FFFFFF',
        public readonly ModuleStyle $moduleStyle = ModuleStyle::Square,
        public readonly bool $invert = false,
    ) {
    }

    public function toRenderOptions(): RenderOptions
    {
        return new RenderOptions(
            margin: $this->margin,
            label: $this->label,
            foregroundColor: $this->foregroundColor,
            backgroundColor: $this->backgroundColor,
            moduleStyle: $this->moduleStyle,
            invert: $this->invert,
        );
    }
}
