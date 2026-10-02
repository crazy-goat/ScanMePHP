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

    public function testExistingBinaryIsInvalidWhenNoChecksumConfigured(): void
    {
        $tempDir = $this->createChecksumFixture(null);

        try {
            // No pinned checksum: the file on disk cannot be verified at all, so
            // it is not accepted (fail-closed) and the caller re-downloads it.
            $binaryPath = $tempDir . '/libscanme_qr-linux-glibc-x86_64.so';
            file_put_contents($binaryPath, 'unverified-binary-content');

            $manager = new ChecksumManager($tempDir);

            $this->assertFalse(
                $manager->existingBinaryIsValid('v0.4.4', 'libscanme_qr-linux-glibc-x86_64.so', $binaryPath)
            );
        } finally {
            $this->cleanupFixture($tempDir, ['libscanme_qr-linux-glibc-x86_64.so']);
        }
    }

    public function testExistingBinaryIsInvalidWhenOnlyThePackageShipsNoChecksum(): void
    {
        $tempDir = $this->createChecksumFixture(null);
        $packageDir = $this->createChecksumFixture(null, 'crazy-goat/scanmephp');

        try {
            $binaryPath = $tempDir . '/libscanme_qr-linux-glibc-x86_64.so';
            file_put_contents($binaryPath, 'unverified-binary-content');

            $manager = new ChecksumManager($tempDir, $packageDir);

            $this->assertFalse(
                $manager->existingBinaryIsValid('v0.4.4', 'libscanme_qr-linux-glibc-x86_64.so', $binaryPath)
            );
        } finally {
            $this->cleanupFixture($tempDir, ['libscanme_qr-linux-glibc-x86_64.so']);
            $this->cleanupFixture($packageDir);
        }
    }

    public function testReadsChecksumsShippedWithTheInstalledPackage(): void
    {
        $tempDir = $this->createChecksumFixture(null);
        $packageDir = $this->createChecksumFixture([
            '0.5.2' => [
                'libscanme_qr-linux-glibc-x86_64.so' => hash('sha256', 'shipped-binary-content'),
            ],
        ], 'crazy-goat/scanmephp');

        try {
            $binaryPath = $packageDir . '/libscanme_qr-linux-glibc-x86_64.so';
            file_put_contents($binaryPath, 'shipped-binary-content');

            $manager = new ChecksumManager($tempDir, $packageDir);

            $this->assertTrue($manager->hasChecksum('0.5.2', 'libscanme_qr-linux-glibc-x86_64.so'));
            $this->assertTrue(
                $manager->existingBinaryIsValid('0.5.2', 'libscanme_qr-linux-glibc-x86_64.so', $binaryPath)
            );
        } finally {
            $this->cleanupFixture($tempDir);
            $this->cleanupFixture($packageDir, ['libscanme_qr-linux-glibc-x86_64.so']);
        }
    }

    public function testRootProjectChecksumsWinOverTheShippedOnes(): void
    {
        $tempDir = $this->createChecksumFixture([
            '0.5.2' => [
                'libscanme_qr-linux-glibc-x86_64.so' => hash('sha256', 'root-pinned-content'),
            ],
        ]);
        $packageDir = $this->createChecksumFixture([
            '0.5.2' => [
                'libscanme_qr-linux-glibc-x86_64.so' => hash('sha256', 'shipped-content'),
            ],
        ], 'crazy-goat/scanmephp');

        try {
            $binaryPath = $tempDir . '/libscanme_qr-linux-glibc-x86_64.so';
            file_put_contents($binaryPath, 'root-pinned-content');

            $manager = new ChecksumManager($tempDir, $packageDir);

            $this->assertSame(
                hash('sha256', 'root-pinned-content'),
                $manager->getChecksum('0.5.2', 'libscanme_qr-linux-glibc-x86_64.so')
            );
            $this->assertTrue(
                $manager->existingBinaryIsValid('0.5.2', 'libscanme_qr-linux-glibc-x86_64.so', $binaryPath)
            );
        } finally {
            $this->cleanupFixture($tempDir, ['libscanme_qr-linux-glibc-x86_64.so']);
            $this->cleanupFixture($packageDir);
        }
    }

    public function testMissingPackageRootIsIgnored(): void
    {
        $tempDir = $this->createChecksumFixture([
            '0.5.2' => [
                'libscanme_qr-linux-glibc-x86_64.so' => 'abc123',
            ],
        ]);

        try {
            $manager = new ChecksumManager($tempDir, $tempDir . '/not-installed');

            $this->assertSame('abc123', $manager->getChecksum('0.5.2', 'libscanme_qr-linux-glibc-x86_64.so'));
        } finally {
            $this->cleanupFixture($tempDir);
        }
    }

    public function testEmptyChecksumValueIsNotAValidPin(): void
    {
        $tempDir = $this->createChecksumFixture([
            '0.5.2' => ['libscanme_qr-linux-glibc-x86_64.so' => ''],
        ]);

        try {
            $manager = new ChecksumManager($tempDir);

            $this->assertFalse($manager->hasChecksum('0.5.2', 'libscanme_qr-linux-glibc-x86_64.so'));
        } finally {
            $this->cleanupFixture($tempDir);
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
    private function createChecksumFixture(?array $checksums, string $packageName = 'test/project'): string
    {
        $tempDir = sys_get_temp_dir() . '/scanme_checksum_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $composerJson = ['name' => $packageName];
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
