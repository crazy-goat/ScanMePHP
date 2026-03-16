<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Exception;

use Exception;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;

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
