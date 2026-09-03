<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Region;
use CrazyGoat\ScanMePHP\RegionRole;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * The geometry of a hexagonal module lattice, shared by the renderers that can
 * draw one.
 *
 * MaxiCode is the only symbology with {@see \CrazyGoat\ScanMePHP\ModuleShape::Hexagon},
 * and every number here is its. Rows are **not** one module apart: the hexagons
 * interlock, so a row sits about 0.866 of a module below the one above it and
 * every odd row is shifted half a module to the right. That is why a renderer
 * cannot simply substitute a hexagon for a square and why {@see Layout}, which
 * counts whole module rows, is not used for the vertical axis here.
 *
 * The bullseye is the other half of it. It is three concentric rings at the
 * centre of the symbol, not modules, so it does not appear in the module grid
 * at all — the symbol reports it as a finder region and it is drawn from these
 * radii. A renderer that skips it emits a symbol with a hole where its finder
 * should be, which no scanner will look at twice.
 *
 * The proportions are ISO/IEC 16023's and were checked the only way that
 * settles it: symbols drawn from them are read back by an independent decoder.
 *
 * @internal Renderer geometry, not part of the public API.
 */
final class HexagonLattice
{
    /** Vertical distance between neighbouring module rows, in modules. */
    public const ROW_PITCH = 0.86594;

    public const HALF_WIDTH = 0.43;

    public const HALF_HEIGHT = 0.5;

    /** Where the hexagon's sides become vertical, measured from its centre. */
    public const SHOULDER = 0.25;

    /** Ring radii of the bullseye, in modules, outermost first. */
    public const RING_RADII = [4.108, 2.539, 0.97];

    public const RING_STROKE = 0.785;

    /**
     * Total height of $rows of hexagons, in modules.
     *
     * One module for the first hexagon, then the pitch for every row after it —
     * so 33 rows are a little under 29 modules tall rather than 33.
     */
    public static function height(int $rows): float
    {
        return $rows <= 0 ? 0.0 : ($rows - 1) * self::ROW_PITCH + 2 * self::HALF_HEIGHT;
    }

    /** Centre of a module, in modules, with the quiet zone included. */
    public static function centreX(Layout $layout, int $row, int $column): float
    {
        return $layout->quietZone->left + $column + 0.5 + ($row % 2 === 0 ? 0.0 : 0.5);
    }

    /** Centre of a module row, in modules, with the quiet zone included. */
    public static function centreY(Layout $layout, int $row): float
    {
        return $layout->quietZone->top + self::HALF_HEIGHT + $row * self::ROW_PITCH;
    }

    /**
     * The six corners of a hexagon centred on ($x, $y), clockwise from the top.
     *
     * @return list<array{float, float}>
     */
    public static function corners(float $x, float $y): array
    {
        return [
            [$x, $y - self::HALF_HEIGHT],
            [$x + self::HALF_WIDTH, $y - self::SHOULDER],
            [$x + self::HALF_WIDTH, $y + self::SHOULDER],
            [$x, $y + self::HALF_HEIGHT],
            [$x - self::HALF_WIDTH, $y + self::SHOULDER],
            [$x - self::HALF_WIDTH, $y - self::SHOULDER],
        ];
    }

    /**
     * Half the width of a hexagon at $distance above or below its centre.
     *
     * Flat between the shoulders and tapering to a point beyond them, which is
     * all a scanline rasteriser needs to know about the shape.
     */
    public static function halfWidthAt(float $distance): float
    {
        $distance = abs($distance);
        if ($distance >= self::HALF_HEIGHT) {
            return 0.0;
        }

        return $distance <= self::SHOULDER
            ? self::HALF_WIDTH
            : self::HALF_WIDTH * (self::HALF_HEIGHT - $distance) / (self::HALF_HEIGHT - self::SHOULDER);
    }

    /**
     * Centre of the bullseye in modules, quiet zone included, or null when the
     * symbol reports no finder region.
     *
     * @return array{float, float}|null
     */
    public static function bullseye(Symbol $symbol, Layout $layout): ?array
    {
        $region = null;
        foreach ($symbol->getFinderRegions() as $candidate) {
            if ($candidate->role === RegionRole::RendererDrawn) {
                $region = $candidate;

                break;
            }
        }

        if (!$region instanceof Region) {
            return null;
        }

        $row = $region->y + intdiv($region->height - 1, 2);
        $column = $region->x + intdiv($region->width - 1, 2);

        return [self::centreX($layout, $row, $column), self::centreY($layout, $row)];
    }
}
