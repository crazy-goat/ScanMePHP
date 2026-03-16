<?php

declare(strict_types=1);

namespace ScanMePHP;

readonly class RenderOptions
{
    public function __construct(
        public int $margin = 4,
        public ?string $label = null,
        public string $foregroundColor = '#000000',
        public string $backgroundColor = '#FFFFFF',
        public ModuleStyle $moduleStyle = ModuleStyle::Square,
        public bool $invert = false,
    ) {
    }

    public function getEffectiveForegroundColor(): string
    {
        return $this->invert ? $this->backgroundColor : $this->foregroundColor;
    }

    public function getEffectiveBackgroundColor(): string
    {
        return $this->invert ? $this->foregroundColor : $this->backgroundColor;
    }
}
