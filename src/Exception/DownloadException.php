<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Exception;

class DownloadException extends \RuntimeException
{
    public static function invalidVersion(string $version): self
    {
        return new self(sprintf('Invalid version format: %s', $version));
    }

    public static function downloadFailed(string $url, string $error): self
    {
        return new self(sprintf('Failed to download %s: %s', $url, $error));
    }

    public static function checksumMismatch(string $file): self
    {
        return new self(sprintf('Checksum verification failed for: %s', $file));
    }

    public static function checksumMissing(string $binaryName): self
    {
        return new self(sprintf(
            'No SHA-256 checksum configured for binary %s. Download refused — add extra.scanmephp.checksums to the root composer.json.',
            $binaryName
        ));
    }

    public static function ffiNotAvailable(): self
    {
        return new self('FFI extension is not available');
    }
}
