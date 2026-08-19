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

    public function testGetChecksumIgnoresVPrefixMismatch(): void
    {
        $tempDir = sys_get_temp_dir() . '/scanme_checksum_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        try {
            // composer.json keyed with 'v' prefix, lookup without prefix
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
            $checksum = $manager->getChecksum('0.4.4', 'libscanme_qr-linux-glibc-x86_64.so');

            $this->assertEquals('abc123def456', $checksum);
        } finally {
            if (is_dir($tempDir)) {
                unlink($tempDir . '/composer.json');
                rmdir($tempDir);
            }
        }
    }

    public function testHasChecksumResolvesUnprefixedKeys(): void
    {
        $tempDir = sys_get_temp_dir() . '/scanme_checksum_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        try {
            // composer.json keyed without prefix, lookup with 'v' prefix
            $composerJson = [
                'name' => 'test/project',
                'extra' => [
                    'scanmephp' => [
                        'checksums' => [
                            '0.4.4' => [
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

            $this->assertTrue($manager->hasChecksum('v0.4.4', 'libscanme_qr-linux-glibc-x86_64.so'));
        } finally {
            if (is_dir($tempDir)) {
                unlink($tempDir . '/composer.json');
                rmdir($tempDir);
            }
        }
    }

    public function testExistingBinaryIsValidWhenChecksumMatches(): void
    {
        $tempDir = $this->createChecksumFixture([
            'v0.4.4' => [
                'libscanme_qr-linux-glibc-x86_64.so' => hash('sha256', 'verified-binary-content'),
            ],
        ]);

        try {
            $binaryPath = $tempDir . '/libscanme_qr-linux-glibc-x86_64.so';
            file_put_contents($binaryPath, 'verified-binary-content');

            $manager = new ChecksumManager($tempDir);

            $this->assertTrue(
                $manager->existingBinaryIsValid('v0.4.4', 'libscanme_qr-linux-glibc-x86_64.so', $binaryPath)
            );
        } finally {
            $this->cleanupFixture($tempDir, ['libscanme_qr-linux-glibc-x86_64.so']);
        }
    }

    public function testExistingBinaryIsInvalidWhenChecksumMismatches(): void
    {
        $tempDir = $this->createChecksumFixture([
            'v0.4.4' => [
                'libscanme_qr-linux-glibc-x86_64.so' => hash('sha256', 'verified-binary-content'),
            ],
        ]);

        try {
            // Tampered content: on-disk file no longer matches the pinned checksum
            $binaryPath = $tempDir . '/libscanme_qr-linux-glibc-x86_64.so';
            file_put_contents($binaryPath, 'tampered-binary-content');

            $manager = new ChecksumManager($tempDir);

            $this->assertFalse(
                $manager->existingBinaryIsValid('v0.4.4', 'libscanme_qr-linux-glibc-x86_64.so', $binaryPath)
            );
        } finally {
            $this->cleanupFixture($tempDir, ['libscanme_qr-linux-glibc-x86_64.so']);
        }
    }

    public function testExistingBinaryIsValidWhenNoChecksumConfigured(): void
    {
        $tempDir = $this->createChecksumFixture(null);

        try {
            // No pinned checksum: legacy behavior is preserved (existing file accepted)
            $binaryPath = $tempDir . '/libscanme_qr-linux-glibc-x86_64.so';
            file_put_contents($binaryPath, 'unverified-binary-content');

            $manager = new ChecksumManager($tempDir);

            $this->assertTrue(
                $manager->existingBinaryIsValid('v0.4.4', 'libscanme_qr-linux-glibc-x86_64.so', $binaryPath)
            );
        } finally {
            $this->cleanupFixture($tempDir, ['libscanme_qr-linux-glibc-x86_64.so']);
        }
    }

    public function testExistingBinaryIsInvalidWhenFileMissing(): void
    {
        $tempDir = $this->createChecksumFixture([
            'v0.4.4' => [
                'libscanme_qr-linux-glibc-x86_64.so' => hash('sha256', 'verified-binary-content'),
            ],
        ]);

        try {
            $manager = new ChecksumManager($tempDir);

            $this->assertFalse(
                $manager->existingBinaryIsValid('v0.4.4', 'libscanme_qr-linux-glibc-x86_64.so', $tempDir . '/missing.so')
            );
        } finally {
            $this->cleanupFixture($tempDir);
        }
    }

    /**
     * @param array<string, array<string, string>>|null $checksums
     */
    private function createChecksumFixture(?array $checksums): string
    {
        $tempDir = sys_get_temp_dir() . '/scanme_checksum_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $composerJson = ['name' => 'test/project'];
        if ($checksums !== null) {
            $composerJson['extra'] = ['scanmephp' => ['checksums' => $checksums]];
        }

        file_put_contents($tempDir . '/composer.json', json_encode($composerJson, JSON_PRETTY_PRINT));

        return $tempDir;
    }

    /**
     * @param list<string> $binaryFiles
     */
    private function cleanupFixture(string $tempDir, array $binaryFiles = []): void
    {
        if (!is_dir($tempDir)) {
            return;
        }

        foreach ($binaryFiles as $file) {
            if (file_exists($tempDir . '/' . $file)) {
                unlink($tempDir . '/' . $file);
            }
        }
        unlink($tempDir . '/composer.json');
        rmdir($tempDir);
    }
}
