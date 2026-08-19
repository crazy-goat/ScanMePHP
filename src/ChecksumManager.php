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

        return $this->checksums[$version][$binaryName]
            ?? $this->checksums['v' . $unprefixed][$binaryName]
            ?? $this->checksums[$unprefixed][$binaryName]
            ?? null;
    }

    public function hasChecksum(string $version, string $binaryName): bool
    {
        return $this->getChecksum($version, $binaryName) !== null;
    }

    public function existingBinaryIsValid(string $version, string $binaryName, string $path): bool
    {
        $checksum = $this->getChecksum($version, $binaryName);

        // No pinned checksum: keep the legacy behavior of accepting whatever is
        // already on disk (no regression for consumers without pinned checksums).
        if ($checksum === null) {
            return true;
        }

        // Fail-closed: a file that cannot be hashed (missing/unreadable) is invalid.
        return @hash_file('sha256', $path) === $checksum;
    }
}
