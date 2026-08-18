<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\BinaryDownloader;
use CrazyGoat\ScanMePHP\ChecksumManager;
use CrazyGoat\ScanMePHP\Exception\DownloadException;
use PHPUnit\Framework\TestCase;

class BinaryDownloaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scanme_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            array_map(unlink(...), glob($this->tempDir . '/*'));
            rmdir($this->tempDir);
        }
    }

    public function testConstructorSetsProperties(): void
    {
        $downloader = new BinaryDownloader(
            'crazy-goat/scanmephp',
            'v0.4.4',
            $this->tempDir
        );

        $this->assertInstanceOf(BinaryDownloader::class, $downloader);
    }

    public function testGeneratesDownloadUrl(): void
    {
        $downloader = new BinaryDownloader(
            'crazy-goat/scanmephp',
            'v0.4.4',
            $this->tempDir
        );

        $url = $downloader->getDownloadUrl('libscanme_qr-linux-glibc-x86_64.so');
        $this->assertEquals(
            'https://github.com/crazy-goat/scanmephp/releases/download/v0.4.4/libscanme_qr-linux-glibc-x86_64.so',
            $url
        );
    }

    public function testThrowsExceptionForInvalidVersion(): void
    {
        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('Invalid version format');

        new BinaryDownloader(
            'crazy-goat/scanmephp',
            'invalid',
            $this->tempDir
        );
    }

    public function testDownloadThrowsChecksumMissingWhenNotConfigured(): void
    {
        $rootDir = sys_get_temp_dir() . '/scanme_checksum_missing_' . uniqid();
        mkdir($rootDir, 0777, true);

        try {
            file_put_contents($rootDir . '/composer.json', json_encode(['name' => 'test/project']));

            $downloader = new BinaryDownloader(
                'crazy-goat/scanmephp',
                '0.4.4',
                $this->tempDir,
                new ChecksumManager($rootDir)
            );

            try {
                $downloader->download('libscanme_qr-linux-glibc-x86_64.so');
                $this->fail('Expected DownloadException when no checksum is configured');
            } catch (DownloadException $e) {
                $this->assertStringContainsString('checksum', $e->getMessage());
            }

            $this->assertFileDoesNotExist($this->tempDir . '/libscanme_qr-linux-glibc-x86_64.so');
        } finally {
            if (is_dir($rootDir)) {
                unlink($rootDir . '/composer.json');
                rmdir($rootDir);
            }
        }
    }

    public function testDownloadThrowsChecksumMissingWithoutManagerAndNoExplicitChecksum(): void
    {
        $downloader = new BinaryDownloader(
            'crazy-goat/scanmephp',
            '0.4.4',
            $this->tempDir
        );

        $this->expectException(DownloadException::class);
        $this->expectExceptionMessage('checksum');

        $downloader->download('libscanme_qr-linux-glibc-x86_64.so');
    }
}
