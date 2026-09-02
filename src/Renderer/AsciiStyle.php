<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

enum AsciiStyle: string
{
    /** One full block per module. Widest, works in any terminal font. */
    case Blocks = 'ascii-blocks';

    /**
     * Two module rows per text line via half-height blocks, so the symbol
     * comes out roughly square instead of twice as tall as it is wide — the
     * only style that fits a QR v10 in an 80×24 terminal.
     */
    case HalfBlocks = 'ascii-half-blocks';

    /** A dot per dark module. Loosest, but readable where blocks render oddly. */
    case Dots = 'ascii-dots';
}
