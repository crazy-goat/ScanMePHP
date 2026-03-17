<?php
declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\ChecksumManager;
use PHPUnit\Framework\TestCase;

class ChecksumManagerTest extends TestCase
{
    public function testLoadsChecksumsFromComposerExtra(): void
    {
        $tempDir = sys_get_temp_dir() . '/scanme_checksum_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        
        try {
            // Create mock composer.json with checksums
            $composerJson = [
                'name' => 'test/project',
                'extra' => [
                    'scanmephp' => [
                        'checksums' => [
                            'v0.4.4' => [
                                'libscanme_qr-linux-glibc-x86_64.so' => 'abc123def456',
                            ],
                        ],
                    ],
                ],
            ];
            
            file_put_contents(
                $tempDir . '/composer.json',
                json_encode($composerJson, JSON_PRETTY_PRINT)
            );
            
            $manager = new ChecksumManager($tempDir);
            $checksum = $manager->getChecksum('v0.4.4', 'libscanme_qr-linux-glibc-x86_64.so');
            
            $this->assertEquals('abc123def456', $checksum);
        } finally {
            if (is_dir($tempDir)) {
                unlink($tempDir . '/composer.json');
                rmdir($tempDir);
            }
        }
    }

    public function testReturnsNullForMissingChecksum(): void
    {
        $tempDir = sys_get_temp_dir() . '/scanme_checksum_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        
        try {
            $composerJson = ['name' => 'test/project'];
            file_put_contents(
                $tempDir . '/composer.json',
                json_encode($composerJson, JSON_PRETTY_PRINT)
            );
            
            $manager = new ChecksumManager($tempDir);
            $checksum = $manager->getChecksum('v0.4.4', 'nonexistent.so');
            
            $this->assertNull($checksum);
        } finally {
            if (is_dir($tempDir)) {
                unlink($tempDir . '/composer.json');
                rmdir($tempDir);
            }
        }
    }
}
