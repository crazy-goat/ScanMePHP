<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * The geometry a symbology's modules sit on.
 *
 * This is the main axis along which a renderer can be incompatible with a
 * symbol: almost everything lays modules out on a regular square grid, but
 * MaxiCode uses hexagons on offset rows, and no amount of scaling turns a
 * character-cell or table-cell renderer into something that can draw those.
 * Renderers declare the shapes they can draw, symbols declare the shape they
 * need, and the mismatch is reported instead of silently mis-rendered.
 */
enum ModuleShape: string
{
    /** Regular grid, one square cell per module. Everything except MaxiCode. */
    case Square = 'square';

    /** Hexagonal cells on rows offset by half a module. MaxiCode only. */
    case Hexagon = 'hexagon';
}
