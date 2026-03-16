<?php

declare(strict_types=1);

namespace ScanMePHP;

use ScanMePHP\Renderer\FullBlocksRenderer;

readonly class QRCodeConfig
{
    public function __construct(
        public RendererInterface $engine = new FullBlocksRenderer(),
        public ErrorCorrectionLevel $errorCorrectionLevel = ErrorCorrectionLevel::Medium,
        public ?string $label = null,
        public int $size = 0,  // 0 = auto
        public int $margin = 4,
        public string $foregroundColor = '#000000',
        public string $backgroundColor = '#FFFFFF',
        public ModuleStyle $moduleStyle = ModuleStyle::Square,
        public bool $invert = false,
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
