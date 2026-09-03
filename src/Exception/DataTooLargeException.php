<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Exception;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use Exception;

class DataTooLargeException extends Exception
{
    public static function dataExceedsMaximumCapacity(
        int $dataLength,
        ErrorCorrectionLevel $level
    ): self {
        return new self(sprintf(
            'Data length (%d bytes) exceeds maximum capacity for error correction level %s even at version 40',
            $dataLength,
            $level->name
        ));
    }

    /**
     * Symbology-neutral variant, for the codeword-counting symbologies whose
     * capacity is a symbol size rather than a version plus a correction level.
     */
    public static function forSymbolSize(
        int $needed,
        int $capacity,
        string $symbolSize,
        string $unit = 'codewords'
    ): self {
        return new self(sprintf(
            'Data needs %d %s but %s holds %d',
            $needed,
            $unit,
            $symbolSize,
            $capacity
        ));
    }

    public static function dataDoesNotFitInVersion(
        int $dataLength,
        int $requestedVersion,
        ErrorCorrectionLevel $level,
        int $minimumRequiredVersion
    ): self {
        return new self(sprintf(
            'Data length (%d bytes) does not fit in version %d with error correction level %s. Minimum required version: %d',
            $dataLength,
            $requestedVersion,
            $level->name,
            $minimumRequiredVersion
        ));
    }
}
