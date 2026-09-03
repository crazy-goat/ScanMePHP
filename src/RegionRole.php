<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * Who is responsible for the ink inside a {@see Region}.
 *
 * Finder regions started as a hint: QR reports its three corner patterns so a
 * renderer can round their corners, and a renderer that ignores them draws the
 * same scannable symbol. MaxiCode broke that assumption. Its bullseye is three
 * concentric rings, not modules — no arrangement of light and dark cells is a
 * circle — so the grid holds nothing where the finder goes and a renderer that
 * ignores the region emits a symbol with a hole in the middle that no scanner
 * will look at twice.
 *
 * The same field therefore carried two different contracts, and the difference
 * was invisible: both were a rectangle of modules. Naming it here makes the
 * obligation something {@see Compatibility} can check at the boundary, rather
 * than something a renderer author is expected to know.
 */
enum RegionRole: string
{
    /**
     * The modules are in the grid. The region only says they are structurally
     * special, and a renderer is free to draw them like any others.
     */
    case InGrid = 'in-grid';

    /**
     * The grid is blank here and the renderer has to supply the shape itself.
     * Ignoring this does not produce a plainer symbol; it produces a broken one.
     */
    case RendererDrawn = 'renderer-drawn';
}
