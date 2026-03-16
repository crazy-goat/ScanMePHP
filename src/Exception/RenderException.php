<?php

declare(strict_types=1);

namespace ScanMePHP\Exception;

use Exception;

class RenderException extends Exception
{
    public static function renderingFailed(string $reason): self
    {
        return new self(sprintf('Rendering failed: %s', $reason));
    }

    public static function unsupportedOperation(string $operation): self
    {
        return new self(sprintf('Operation not supported by this renderer: %s', $operation));
    }
}
