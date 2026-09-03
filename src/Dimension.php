<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * Whether a symbology encodes along one axis or two.
 *
 * Linear symbols (EAN, Code128, ITF) carry a single logical module row that a
 * renderer stretches vertically; the height is presentation, not data. Matrix
 * symbols (QR, DataMatrix, Aztec) encode in both axes, so every module row is
 * meaningful.
 *
 * Meaningful is not the same as one module tall, and PDF417 is the exception
 * that shows the difference: it is a matrix symbology whose rows are stacked
 * linear codes, each independently readable, so every row carries data and yet
 * their conventional three-module height is presentation. A matrix symbol
 * states its own row heights and a renderer honours them; what it will not
 * accept is a caller's override, because for every other matrix symbology that
 * would corrupt the symbol.
 */
enum Dimension: string
{
    case Linear = 'linear';
    case Matrix = 'matrix';
}
