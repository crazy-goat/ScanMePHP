<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * Whether a symbology encodes along one axis or two.
 *
 * Linear symbols (EAN, Code128, ITF) carry a single logical module row that a
 * renderer stretches vertically; the height is presentation, not data. Matrix
 * symbols (QR, DataMatrix, Aztec) encode in both axes, so every module row is
 * meaningful and must render one module tall.
 */
enum Dimension: string
{
    case Linear = 'linear';
    case Matrix = 'matrix';
}
