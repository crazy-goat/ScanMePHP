<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * Mandatory blank margin around a symbol, in modules, per side.
 *
 * The spec-required width is a property of the symbology, not of the caller's
 * taste: QR wants 4 modules on every side, EAN-13 wants 11 on the left and 7
 * on the right, ITF wants 10 horizontally and none vertically. Generators
 * therefore ship the correct zone with the symbol and render options only
 * scale it, so a caller cannot accidentally produce an unscannable symbol.
 */
final class QuietZone
{
    public function __construct(
        public readonly int $left = 0,
        public readonly int $right = 0,
        public readonly int $top = 0,
        public readonly int $bottom = 0,
    ) {
        if ($this->left < 0 || $this->right < 0 || $this->top < 0 || $this->bottom < 0) {
            throw new \InvalidArgumentException('Quiet zone widths cannot be negative');
        }
    }

    public static function uniform(int $modules): self
    {
        return new self($modules, $modules, $modules, $modules);
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * Widen every side to at least $modules, keeping any side the symbology
     * already requires to be wider.
     */
    public function atLeast(int $modules): self
    {
        return new self(
            max($this->left, $modules),
            max($this->right, $modules),
            max($this->top, $modules),
            max($this->bottom, $modules),
        );
    }

    public function isEmpty(): bool
    {
        return $this->left === 0 && $this->right === 0 && $this->top === 0 && $this->bottom === 0;
    }
}
