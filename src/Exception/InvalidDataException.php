<?php

declare(strict_types=1);

namespace ScanMePHP\Exception;

use Exception;

class InvalidDataException extends Exception
{
    public static function emptyData(): self
    {
        return new self('Data cannot be empty');
    }

    public static function invalidUrl(string $url): self
    {
        return new self(sprintf('Invalid URL provided: "%s"', $url));
    }

    public static function incompatibleMode(string $mode, string $data): self
    {
        return new self(sprintf(
            'Data "%s" is incompatible with forced encoding mode: %s',
            $data,
            $mode
        ));
    }
}
