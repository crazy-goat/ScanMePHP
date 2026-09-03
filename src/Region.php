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
 *
 * The {@see RegionRole} says whether drawing it is optional. For almost
 * everything it is: the modules are in the grid and the region is a styling
 * hint. MaxiCode's bullseye is the exception and the reason the role exists.
 */
final class Region
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
        public readonly RegionRole $role = RegionRole::InGrid,
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
