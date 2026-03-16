<?php

declare(strict_types=1);

namespace ScanMePHP\Exception;

use Exception;

class InvalidConfigurationException extends Exception
{
    public static function invalidModuleSize(int $size): self
    {
        return new self(sprintf(
            'Module size must be greater than 0, got %d',
            $size
        ));
    }
}
