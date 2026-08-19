<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Exception;

class BuildException extends \RuntimeException
{
    public static function toolsNotAvailable(): self
    {
        return new self('Build tools not available (cmake and a C++ compiler are required)');
    }

    public static function cmakeFailed(int $exitCode): self
    {
        return new self(sprintf('CMake configuration failed (exit code %d). See logs for details.', $exitCode));
    }

    public static function buildFailed(int $exitCode): self
    {
        return new self(sprintf('Build failed (exit code %d). See logs for details.', $exitCode));
    }

    public static function libraryNotFound(string $buildPath): self
    {
        return new self(sprintf('Built library not found in build directory: %s', basename($buildPath)));
    }
}
