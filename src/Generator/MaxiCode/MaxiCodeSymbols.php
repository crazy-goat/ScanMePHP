<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\MaxiCode;

use CrazyGoat\ScanMePHP\Encoding\MaxiCode\Specs;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Region;
use CrazyGoat\ScanMePHP\RegionRole;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Converts the MaxiCode encoder's module grid into the public Symbol.
 *
 * Two things here are unlike every other symbology in this library.
 *
 * The grid is **ragged**: hexagons on odd rows are offset half a module and
 * there are 29 of them rather than 30. Symbol stores a rectangle, so the last
 * column of every odd row is a module that does not exist, and it is always
 * light. A square-grid renderer would draw it, which is one more reason such a
 * renderer must refuse the symbol outright rather than approximate it.
 *
 * The bullseye is **not modules**. It is three concentric rings at the centre,
 * and no arrangement of light and dark cells is it. So it is reported as a
 * finder region and drawn by the renderer, which is a real division of labour
 * rather than a hint: a renderer that ignores the region produces a symbol with
 * a hole in the middle that nothing can find.
 */
final class MaxiCodeSymbols
{
    /** ISO/IEC 16023 requires one module of clear space on every side. */
    public const QUIET_ZONE = 1;

    /**
     * The bullseye's extent in modules, centred on the middle module.
     *
     * Both are odd so the region has a module at its centre rather than a
     * boundary. They differ because the rings are round in real units and the
     * lattice is not square: rows are about 0.866 of a module apart, so the
     * same radius spans more rows than columns.
     */
    public const BULLSEYE_COLUMNS = 9;

    public const BULLSEYE_ROWS = 11;

    /**
     * @param list<list<bool>> $matrix
     * @param array<string, int|string|bool> $metadata
     */
    public static function fromModules(array $matrix, array $metadata = []): Symbol
    {
        $modules = '';
        foreach ($matrix as $row) {
            foreach ($row as $module) {
                $modules .= $module ? '1' : '0';
            }
        }

        return new Symbol(
            width: Specs::COLUMNS,
            height: Specs::ROWS,
            modules: $modules,
            moduleShape: ModuleShape::Hexagon,
            quietZone: QuietZone::uniform(self::QUIET_ZONE),
            finderRegions: [new Region(
                x: Specs::BULLSEYE_COLUMN - intdiv(self::BULLSEYE_COLUMNS, 2),
                y: Specs::BULLSEYE_ROW - intdiv(self::BULLSEYE_ROWS, 2),
                width: self::BULLSEYE_COLUMNS,
                height: self::BULLSEYE_ROWS,
                role: RegionRole::RendererDrawn,
            )],
            metadata: [
                'symbology' => Symbology::MaxiCode->value,
                ...$metadata,
            ],
        );
    }
}
