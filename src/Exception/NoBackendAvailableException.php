<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Exception;

use Exception;

class NoBackendAvailableException extends Exception
{
    /** @param list<string> $backends */
    public static function forSymbology(string $symbology, array $backends): self
    {
        return new self(sprintf(
            'No usable encoding backend for %s on this host; tried: %s',
            $symbology,
            implode(', ', $backends)
        ));
    }
}
