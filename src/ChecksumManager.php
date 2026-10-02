<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

class ChecksumManager
{
    private ?array $checksums = null;

    public function __construct(private readonly string $projectRoot)
    {
        $this->loadChecksums();
    }

    private function loadChecksums(): void
    {
        $composerJsonPath = $this->projectRoot . '/composer.json';

        if (!file_exists($composerJsonPath)) {
            return;
        }

        $composer = json_decode(file_get_contents($composerJsonPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return;
        }

        $this->checksums = $composer['extra']['scanmephp']['checksums'] ?? null;
    }

    public function getChecksum(string $version, string $binaryName): ?string
    {
        if ($this->checksums === null) {
            return null;
        }

        // Accept both '0.4.4' and 'v0.4.4' as composer.json version keys.
        $unprefixed = str_starts_with($version, 'v') ? substr($version, 1) : $version;

        $checksum = $this->checksums[$version][$binaryName]
            ?? $this->checksums['v' . $unprefixed][$binaryName]
            ?? $this->checksums[$unprefixed][$binaryName]
            ?? null;

        // An empty or non-string value is not a usable digest: a malformed
        // composer.json must not turn into a checksum that never matches.
        return is_string($checksum) && $checksum !== '' ? $checksum : null;
    }

    public function hasChecksum(string $version, string $binaryName): bool
    {
        return $this->getChecksum($version, $binaryName) !== null;
    }

    public function existingBinaryIsValid(string $version, string $binaryName, string $path): bool
    {
        $checksum = $this->getChecksum($version, $binaryName);

        // Fail-closed: without a pinned checksum the file on disk cannot be
        // verified at all, so it is not accepted. The caller re-downloads it
        // through the verified download path, which refuses without a checksum
        // too instead of trusting an unknown binary.
        if ($checksum === null) {
            return false;
        }

        // Fail-closed: a file that cannot be hashed (missing/unreadable) is invalid.
        return @hash_file('sha256', $path) === $checksum;
    }
}
