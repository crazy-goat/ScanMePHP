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
                                'libscanme_qr-linux-glibc-x86_64.so' => hash('sha256', 'abc123def456'),
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

            $this->assertEquals(hash('sha256', 'abc123def456'), $checksum);
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
                                'libscanme_qr-linux-glibc-x86_64.so' => hash('sha256', 'abc123def456'),
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

            $this->assertEquals(hash('sha256', 'abc123def456'), $checksum);
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
                                'libscanme_qr-linux-glibc-x86_64.so' => hash('sha256', 'abc123def456'),
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
            // it must not be accepted (fail-closed) — the caller re-downloads it.
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

    public function testEmptyChecksumValueIsNotAPin(): void
    {
        $tempDir = $this->createChecksumFixture([
            'v0.4.4' => ['libscanme_qr-linux-glibc-x86_64.so' => ''],
        ]);

        try {
            $manager = new ChecksumManager($tempDir);

            $this->assertFalse($manager->hasChecksum('v0.4.4', 'libscanme_qr-linux-glibc-x86_64.so'));
            $this->assertNull($manager->getChecksum('v0.4.4', 'libscanme_qr-linux-glibc-x86_64.so'));
        } finally {
            $this->cleanupFixture($tempDir);
        }
    }

    public function testNonStringChecksumValueIsNotAPin(): void
    {
        // A hand-edited composer.json can hold anything; a number must not come
        // back as a checksum (it would raise a TypeError under strict_types).
        $tempDir = $this->createChecksumFixture([
            'v0.4.4' => ['libscanme_qr-linux-glibc-x86_64.so' => 12345],
        ]);

        try {
            $manager = new ChecksumManager($tempDir);

            $this->assertFalse($manager->hasChecksum('v0.4.4', 'libscanme_qr-linux-glibc-x86_64.so'));
        } finally {
            $this->cleanupFixture($tempDir);
        }
    }

    public function testNonStringChecksumSectionIsNotAPin(): void
    {
        // A scalar where the version map belongs used to be assigned straight to
        // the ?array property: a TypeError, i.e. an \Error, which escapes the
        // plugin's catch (\Exception) and aborts the whole composer install.
        // Written by hand, because the fixture helper takes a version map.
        $tempDir = sys_get_temp_dir() . '/scanme_checksum_test_' . uniqid();
        mkdir($tempDir, 0777, true);
        file_put_contents($tempDir . '/composer.json', json_encode([
            'name' => 'test/project',
            'extra' => ['scanmephp' => ['checksums' => 'oops']],
        ]));

        try {
            $manager = new ChecksumManager($tempDir);

            $this->assertFalse($manager->hasChecksum('v0.4.4', 'libscanme_qr-linux-glibc-x86_64.so'));
            $this->assertNull($manager->getChecksum('v0.4.4', 'libscanme_qr-linux-glibc-x86_64.so'));
        } finally {
            $this->cleanupFixture($tempDir);
        }
    }

    public function testWholeSha256SumLineIsNotAPin(): void
    {
        // The README tells users to copy the digest out of checksums.txt; a whole
        // `sha256sum` line pasted by mistake can never match, so it must be
        // refused before the multi-megabyte download, not after it.
        $binaryName = 'libscanme_qr-linux-glibc-x86_64.so';
        $digest = hash('sha256', 'binary-content');
        $tempDir = $this->createChecksumFixture([
            'v0.4.4' => [$binaryName => $digest . '  ' . $binaryName],
        ]);

        try {
            $manager = new ChecksumManager($tempDir);

            $this->assertFalse($manager->hasChecksum('v0.4.4', $binaryName));
            $this->assertNull($manager->getChecksum('v0.4.4', $binaryName));
        } finally {
            $this->cleanupFixture($tempDir);
        }
    }

    public function testUppercaseAndShortDigestsAreNotPins(): void
    {
        $binaryName = 'libscanme_qr-linux-glibc-x86_64.so';
        $tempDir = $this->createChecksumFixture([
            'v0.4.4' => [$binaryName => strtoupper(hash('sha256', 'binary-content'))],
        ]);

        try {
            $this->assertFalse((new ChecksumManager($tempDir))->hasChecksum('v0.4.4', $binaryName));
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
     * @param array<string, mixed> $checksums
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
