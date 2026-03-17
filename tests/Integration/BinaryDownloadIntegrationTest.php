<?php
declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests\Integration;

use CrazyGoat\ScanMePHP\BinaryDownloader;
use CrazyGoat\ScanMePHP\PlatformDetector;
use PHPUnit\Framework\TestCase;

class BinaryDownloadIntegrationTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scanme_integration_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            array_map('unlink', glob($this->tempDir . '/*'));
            rmdir($this->tempDir);
        }
    }

    public function testCanGenerateDownloadUrlForCurrentPlatform(): void
    {
        $downloader = new BinaryDownloader(
            'crazy-goat/scanmephp',
            'v0.4.4',
            $this->tempDir
        );
        
        $binaryName = PlatformDetector::getCurrentPlatformBinaryName();
        $url = $downloader->getDownloadUrl($binaryName);
        
        $this->assertStringStartsWith('https://github.com/', $url);
        $this->assertStringContainsString($binaryName, $url);
    }

    public function testPlatformDetectorReturnsValidBinaryName(): void
    {
        $binaryName = PlatformDetector::getCurrentPlatformBinaryName();
        
        $this->assertIsString($binaryName);
        $this->assertMatchesRegularExpression(
            '/^(libscanme_qr|scanme_qr)/',
            $binaryName
        );
    }
}
