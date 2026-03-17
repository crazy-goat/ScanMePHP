<?php
declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

use CrazyGoat\ScanMePHP\Exception\DownloadException;

class BinaryDownloader
{
    private readonly string $baseUrl;
    
    public function __construct(
        private readonly string $repository,
        private readonly string $version,
        private readonly string $downloadPath,
        private readonly ?ChecksumManager $checksumManager = null
    ) {
        if (!preg_match('/^v?\d+\.\d+\.\d+$/', $version)) {
            throw DownloadException::invalidVersion($version);
        }

        // Normalize version to include 'v' prefix for GitHub releases URL
        $versionWithTag = str_starts_with($version, 'v') ? $version : 'v' . $version;

        $this->baseUrl = sprintf(
            'https://github.com/%s/releases/download/%s',
            $repository,
            $versionWithTag
        );

        if (!is_dir($downloadPath)) {
            mkdir($downloadPath, 0755, true);
        }
    }

    public function getDownloadUrl(string $binaryName): string
    {
        return $this->baseUrl . '/' . $binaryName;
    }

    public function download(string $binaryName, ?string $expectedChecksum = null): string
    {
        // If no checksum provided, try to get from manager
        if ($expectedChecksum === null && $this->checksumManager !== null) {
            $expectedChecksum = $this->checksumManager->getChecksum($this->version, $binaryName);
        }
        
        $url = $this->getDownloadUrl($binaryName);
        $targetPath = $this->downloadPath . '/' . $binaryName;
        
        // Download using cURL
        $ch = curl_init($url);
        if ($ch === false) {
            throw DownloadException::downloadFailed($url, 'Failed to initialize cURL');
        }
        
        $fp = fopen($targetPath, 'wb');
        if ($fp === false) {
            throw DownloadException::downloadFailed($url, 'Failed to open target file');
        }
        
        try {
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $result = curl_exec($ch);
            
            if ($result === false) {
                throw DownloadException::downloadFailed($url, curl_error($ch));
            }
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode !== 200) {
                throw DownloadException::downloadFailed($url, 'HTTP ' . $httpCode);
            }
        } finally {
            curl_close($ch);
            fclose($fp);
        }
        
        // Verify checksum if provided
        if ($expectedChecksum !== null) {
            $actualChecksum = hash_file('sha256', $targetPath);
            if ($actualChecksum !== $expectedChecksum) {
                unlink($targetPath);
                throw DownloadException::checksumMismatch($binaryName);
            }
        }
        
        // Make executable on Unix systems
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($targetPath, 0755);
        }
        
        return $targetPath;
    }

    public function isFfiAvailable(): bool
    {
        return extension_loaded('ffi');
    }

    public function downloadForCurrentPlatform(?string $expectedChecksum = null): string
    {
        if (!$this->isFfiAvailable()) {
            throw DownloadException::ffiNotAvailable();
        }
        
        $binaryName = PlatformDetector::getCurrentPlatformBinaryName();
        
        return $this->download($binaryName, $expectedChecksum);
    }
}
