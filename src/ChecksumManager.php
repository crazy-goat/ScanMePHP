<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

class ChecksumManager
{
    /**
     * Checksum maps in lookup order. The first source with an entry for the
     * requested version and binary wins:
     *
     * - the root project may pin checksums itself (extra.scanmephp.checksums),
     * - the installed package ships the checksums of its own release.
     *
     * @var list<array<string, array<string, string>>>
     */
    private array $sources = [];

    /**
     * @param string      $projectRoot directory of the root project (its composer.json may pin checksums)
     * @param string|null $packageRoot directory of the installed package (its composer.json ships the
     *                                 checksums of the release); omit it when there is no package
     */
    public function __construct(string $projectRoot, ?string $packageRoot = null)
    {
        $this->addSource($projectRoot);

        if ($packageRoot !== null) {
            $this->addSource($packageRoot);
        }
    }

    private function addSource(string $root): void
    {
        $composerJsonPath = $root . '/composer.json';

        if (!is_file($composerJsonPath)) {
            return;
        }

        $contents = file_get_contents($composerJsonPath);

        if ($contents === false) {
            return;
        }

        $composer = json_decode($contents, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($composer)) {
            return;
        }

        $checksums = $composer['extra']['scanmephp']['checksums'] ?? null;

        if (!is_array($checksums)) {
            return;
        }

        $this->sources[] = $checksums;
    }

    public function getChecksum(string $version, string $binaryName): ?string
    {
        // Accept both '0.4.4' and 'v0.4.4' as composer.json version keys.
        $unprefixed = str_starts_with($version, 'v') ? substr($version, 1) : $version;

        foreach ($this->sources as $checksums) {
            $checksum = $checksums[$version][$binaryName]
                ?? $checksums['v' . $unprefixed][$binaryName]
                ?? $checksums[$unprefixed][$binaryName]
                ?? null;

            if (is_string($checksum) && $checksum !== '') {
                return $checksum;
            }
        }

        return null;
    }

    public function hasChecksum(string $version, string $binaryName): bool
    {
        return $this->getChecksum($version, $binaryName) !== null;
    }

    public function existingBinaryIsValid(string $version, string $binaryName, string $path): bool
    {
        $checksum = $this->getChecksum($version, $binaryName);

        // Fail-closed: without a pinned checksum an on-disk binary cannot be
        // verified, so it is not accepted. The caller re-downloads it through
        // the verified download path, which refuses without a checksum too.
        if ($checksum === null) {
            return false;
        }

        // Fail-closed: a file that cannot be hashed (missing/unreadable) is invalid.
        return @hash_file('sha256', $path) === $checksum;
    }
}
