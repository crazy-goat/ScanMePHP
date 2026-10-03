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

        // A malformed section is no pin at all, which is the fail-closed path.
        // Assigning a scalar to the ?array property would raise a TypeError, and
        // a TypeError is an \Error: it escapes the plugin's catch (\Exception)
        // and aborts the whole composer install.
        $checksums = $composer['extra']['scanmephp']['checksums'] ?? null;
        $this->checksums = is_array($checksums) ? $checksums : null;
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

        // Only a SHA-256 digest is usable. Anything else — an empty value, a
        // number, a whole `sha256sum` line pasted by mistake — is not a pin: it
        // can never match, so accepting it would fetch the binary and then throw
        // checksumMismatch on every install instead of refusing up front.
        return is_string($checksum) && preg_match('/^[0-9a-f]{64}$/', $checksum) === 1 ? $checksum : null;
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
