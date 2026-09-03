<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\MicroQr;

use CrazyGoat\ScanMePHP\Encoding\MicroQr\Specs;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;

/**
 * The four Micro QR symbol sizes.
 *
 * These are an enum rather than QR's plain integer because they are not
 * interchangeable with QR's forty versions and the names are not the numbers:
 * a caller writing `version: 2` for a QR symbol asks for twenty-five modules
 * across, and for a Micro QR symbol asks for thirteen. Spelling them M1 to M4,
 * as the standard does, is what keeps the two from being confused in a call
 * that would otherwise compile either way.
 */
enum Version: int
{
    case M1 = 1;
    case M2 = 2;
    case M3 = 3;
    case M4 = 4;

    /** Width and height in modules. */
    public function size(): int
    {
        return Specs::size($this->value);
    }

    /**
     * The error correction levels this version offers, weakest first.
     *
     * Empty for M1, which has none: its two check codewords detect a misread
     * and cannot repair one, so there is nothing for a caller to choose
     * between and {@see MicroQrOptions} refuses a level pinned alongside it.
     *
     * @return list<ErrorCorrectionLevel>
     */
    public function levels(): array
    {
        return Specs::levels($this->value);
    }

    public function supports(?ErrorCorrectionLevel $level): bool
    {
        return Specs::supports($this->value, $level);
    }
}
