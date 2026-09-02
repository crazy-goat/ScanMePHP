<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Exception;

use Exception;

class UnsupportedDataException extends Exception
{
    public static function forSymbology(string $symbology, string $accepts): self
    {
        return new self($accepts === ''
            ? sprintf('The %s symbology cannot encode the given data', $symbology)
            : sprintf('The %s symbology cannot encode the given data; it accepts %s', $symbology, $accepts));
    }
}
