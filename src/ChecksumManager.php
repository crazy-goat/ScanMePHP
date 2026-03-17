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
        
        return $this->checksums[$version][$binaryName] ?? null;
    }

    public function hasChecksum(string $version, string $binaryName): bool
    {
        return $this->getChecksum($version, $binaryName) !== null;
    }
}
