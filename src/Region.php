<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * A rectangle of modules, in module coordinates relative to the symbol's
 * top-left (quiet zone excluded).
 *
 * Generators use this to point renderers at structurally special areas — the
 * QR finder patterns, for instance — so a renderer can treat them differently
 * without knowing which symbology it is drawing.
 */
final class Region
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
    ) {
    }

    public function contains(int $x, int $y): bool
    {
        return $x >= $this->x
            && $x < $this->x + $this->width
            && $y >= $this->y
            && $y < $this->y + $this->height;
    }
}
