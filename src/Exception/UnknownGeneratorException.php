<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Exception;

use Exception;

class UnknownGeneratorException extends Exception
{
    /** @param list<string> $known */
    public static function named(string $name, array $known): self
    {
        sort($known);

        return new self(sprintf(
            'No generator registered as "%s". Available: %s',
            $name,
            $known === [] ? '(none)' : implode(', ', $known)
        ));
    }
}
