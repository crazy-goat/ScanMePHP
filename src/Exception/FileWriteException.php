<?php

declare(strict_types=1);

namespace ScanMePHP\Exception;

use Exception;

class FileWriteException extends Exception
{
    public static function cannotWriteToFile(string $path): self
    {
        return new self(sprintf('Cannot write to file: %s', $path));
    }

    public static function directoryNotWritable(string $path): self
    {
        return new self(sprintf('Directory is not writable: %s', $path));
    }
}
