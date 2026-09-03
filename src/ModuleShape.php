<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * The geometry a symbology's modules sit on.
 *
 * This is the main axis along which a renderer can be incompatible with a
 * symbol: almost everything lays modules out on a regular square grid, but
 * MaxiCode uses hexagons on offset rows. Renderers declare the shapes they can
 * draw, symbols declare the shape they need, and the mismatch is reported
 * instead of silently mis-rendered.
 *
 * Whether a given renderer *could* grow hexagons is a separate question from
 * whether it has. A terminal genuinely cannot: its cells are a fixed raster
 * with no way to offset a row by half a cell, and the bullseye is three circles
 * besides. HTML is not in that position — `clip-path` draws a hexagon and
 * `border-radius` draws a ring — so the HTML renderers refuse for want of a
 * second rendering path, not for want of a way. That is on the roadmap rather
 * than in the model.
 */
enum ModuleShape: string
{
    /** Regular grid, one square cell per module. Everything except MaxiCode. */
    case Square = 'square';

    /** Hexagonal cells on rows offset by half a module. MaxiCode only. */
    case Hexagon = 'hexagon';
}
