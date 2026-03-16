<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

class RenderOptions
{
    public function __construct(
        public readonly int $margin = 4,
        public readonly ?string $label = null,
        public readonly string $foregroundColor = '#000000',
        public readonly string $backgroundColor = '#FFFFFF',
        public readonly ModuleStyle $moduleStyle = ModuleStyle::Square,
        public readonly bool $invert = false,
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
