<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Exception;

use Exception;

class UnknownRendererException extends Exception
{
    /** @param list<string> $known */
    public static function named(string $format, array $known): self
    {
        sort($known);

        return new self(sprintf(
            'No renderer registered for output format "%s". Available: %s',
            $format,
            $known === [] ? '(none)' : implode(', ', $known)
        ));
    }
}
