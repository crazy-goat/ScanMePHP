<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests\Composer;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Installer\InstallationManager;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use CrazyGoat\ScanMePHP\BinaryDownloader;
use CrazyGoat\ScanMePHP\ChecksumManager;
use CrazyGoat\ScanMePHP\Composer\Plugin;
use CrazyGoat\ScanMePHP\PlatformDetector;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase
{
    private string $tempDir;
    private string $installPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scanme_plugin_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->installPath = $this->tempDir . '/vendor/crazy-goat/scanmephp';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
    }

    public function testPackageInstallRefusesBinaryDownloadWithoutChecksums(): void
    {
        $output = $this->runPackageInstall(['name' => 'test/project']);

        if (!extension_loaded('scanmeqr')) {
            $output = implode("\n", $output);
            $this->assertStringContainsString('refused', $output);
            $this->assertStringContainsString('extra.scanmephp.checksums', $output);
        }

        foreach ([$this->installPath . '/ext-binaries', $this->installPath . '/ffi-binaries'] as $dir) {
            $this->assertSame([], glob($dir . '/*') ?: []);
        }
    }

    public function testPackageInstallKeepsExistingBinaryWhenChecksumMatches(): void
    {
        if (extension_loaded('scanmeqr')) {
            $this->markTestSkipped('scanmeqr extension loaded; the plugin skips binary installation entirely');
        }

        $binaryName = $this->extensionBinaryName();
        $binaryDir = $this->installPath . '/ext-binaries';
        mkdir($binaryDir, 0777, true);
        $binaryPath = $binaryDir . '/' . $binaryName;
        file_put_contents($binaryPath, 'verified-binary-content');

        $output = $this->runPackageInstall([
            'name' => 'test/project',
            'extra' => [
                'scanmephp' => [
                    'checksums' => [
                        '0.4.6' => [$binaryName => hash('sha256', 'verified-binary-content')],
                    ],
                ],
            ],
        ]);

        $output = implode("\n", $output);
        $this->assertStringContainsString('already exists', $output);
        $this->assertStringContainsString('extension=' . $binaryPath, $output);
        $this->assertStringNotContainsString('Re-downloading', $output);
        $this->assertSame('verified-binary-content', file_get_contents($binaryPath));
        $this->assertSame([$binaryName], array_map(basename(...), glob($binaryDir . '/*') ?: []));
    }

    public function testPackageInstallKeepsExistingBinaryWhenNoChecksumConfigured(): void
    {
        if (extension_loaded('scanmeqr')) {
            $this->markTestSkipped('scanmeqr extension loaded; the plugin skips binary installation entirely');
        }

        $binaryName = $this->extensionBinaryName();
        $binaryDir = $this->installPath . '/ext-binaries';
        mkdir($binaryDir, 0777, true);
        $binaryPath = $binaryDir . '/' . $binaryName;
        file_put_contents($binaryPath, 'unverified-binary-content');

        $output = $this->runPackageInstall(['name' => 'test/project']);

        $output = implode("\n", $output);
        $this->assertStringContainsString('already exists', $output);
        $this->assertStringNotContainsString('refused', $output);
        $this->assertStringNotContainsString('Re-downloading', $output);
        $this->assertSame('unverified-binary-content', file_get_contents($binaryPath));
    }

    public function testPackageInstallReplacesBinaryWhenExistingChecksumMismatches(): void
    {
        if (extension_loaded('scanmeqr')) {
            $this->markTestSkipped('scanmeqr extension loaded; the plugin skips binary installation entirely');
        }

        $binaryName = $this->extensionBinaryName();
        $binaryDir = $this->installPath . '/ext-binaries';
        mkdir($binaryDir, 0777, true);
        $binaryPath = $binaryDir . '/' . $binaryName;
        file_put_contents($binaryPath, 'tampered-binary-content');

        // The re-download stub never writes: if the target file is absent after
        // the install, the unlink in the mismatch branch actually happened
        // (an overwrite-success stub would mask a missing unlink).
        $extDownloader = new FailingStubBinaryDownloader($binaryDir);
        $plugin = new StubDownloaderPlugin($this->downloadFactory($extDownloader));

        $output = $this->runPackageInstall([
            'name' => 'test/project',
            'extra' => [
                'scanmephp' => [
                    'checksums' => [
                        '0.4.6' => [$binaryName => hash('sha256', 'verified-binary-content')],
                    ],
                ],
            ],
        ], $plugin);

        $output = implode("\n", $output);
        $this->assertStringContainsString('failed SHA-256 verification. Re-downloading', $output);
        $this->assertStringNotContainsString('already exists', $output);
        $this->assertFileDoesNotExist($binaryPath, 'the mismatched binary must be unlinked before the re-download');
        $this->assertStringContainsString('Extension download failed', $output);
        $this->assertSame(1, $extDownloader->downloadCalls, 'the verified download path must be invoked exactly once for the extension');
    }

    public function testPackageInstallReplacesFfiBinaryWhenExistingChecksumMismatches(): void
    {
        if (extension_loaded('scanmeqr') || !extension_loaded('ffi')) {
            $this->markTestSkipped('requires the FFI extension (FAQ-003) and no preloaded scanmeqr extension');
        }

        $os = PlatformDetector::getOperatingSystem();
        $arch = PlatformDetector::getArchitecture();
        $variant = $os === 'linux' ? PlatformDetector::getLinuxVariant() : null;
        $binaryName = PlatformDetector::getBinaryName($os, $variant, $arch);
        $binaryDir = $this->installPath . '/ffi-binaries';
        mkdir($binaryDir, 0777, true);
        $binaryPath = $binaryDir . '/' . $binaryName;
        file_put_contents($binaryPath, 'tampered-ffi-binary-content');

        $extDownloader = new FailingStubBinaryDownloader($this->installPath . '/ext-binaries');
        $ffiDownloader = new FailingStubBinaryDownloader($binaryDir);
        $plugin = new StubDownloaderPlugin($this->downloadFactory($extDownloader, $ffiDownloader));

        $output = $this->runPackageInstall([
            'name' => 'test/project',
            'extra' => [
                'scanmephp' => [
                    'checksums' => [
                        '0.4.6' => [
                            $binaryName => hash('sha256', 'verified-ffi-binary-content'),
                        ],
                    ],
                ],
            ],
        ], $plugin);

        $output = implode("\n", $output);
        $this->assertStringContainsString('failed SHA-256 verification. Re-downloading', $output);
        $this->assertFileDoesNotExist($binaryPath, 'the mismatched FFI library must be unlinked before the re-download');
        $this->assertStringContainsString('FFI library download failed', $output);
        $this->assertSame(1, $ffiDownloader->downloadCalls, 'the FFI verified download path must be invoked exactly once');
    }

    public function testPackageInstallWithDirectoryAtFfiTargetPathFailsCleanly(): void
    {
        if (extension_loaded('scanmeqr') || !extension_loaded('ffi')) {
            $this->markTestSkipped('requires the FFI extension (FAQ-003) and no preloaded scanmeqr extension');
        }

        $os = PlatformDetector::getOperatingSystem();
        $arch = PlatformDetector::getArchitecture();
        $variant = $os === 'linux' ? PlatformDetector::getLinuxVariant() : null;
        $binaryName = PlatformDetector::getBinaryName($os, $variant, $arch);
        $binaryDir = $this->installPath . '/ffi-binaries';
        mkdir($binaryDir, 0777, true);
        // Mirror of the ext directory test: the FFI branch's is_file() guard
        // must not be mistaken for the ext one being enough (F-11).
        mkdir($binaryDir . '/' . $binaryName, 0777, true);

        $extDownloader = new FailingStubBinaryDownloader($this->installPath . '/ext-binaries');
        $ffiDownloader = new FailingStubBinaryDownloader($binaryDir);
        $plugin = new StubDownloaderPlugin($this->downloadFactory($extDownloader, $ffiDownloader));

        $output = $this->runPackageInstall([
            'name' => 'test/project',
            'extra' => [
                'scanmephp' => [
                    'checksums' => [
                        '0.4.6' => [
                            $binaryName => hash('sha256', 'verified-ffi-binary-content'),
                        ],
                    ],
                ],
            ],
        ], $plugin);

        $output = implode("\n", $output);
        $this->assertStringNotContainsString('failed SHA-256 verification', $output);
        $this->assertStringContainsString('FFI library download failed', $output);
        $this->assertSame(1, $ffiDownloader->downloadCalls);
        $this->assertDirectoryExists($binaryDir . '/' . $binaryName, 'the directory must be left untouched');
    }

    public function testPackageInstallWithDirectoryAtTargetPathFailsCleanly(): void
    {
        if (extension_loaded('scanmeqr')) {
            $this->markTestSkipped('scanmeqr extension loaded; the plugin skips binary installation entirely');
        }

        $binaryName = $this->extensionBinaryName();
        $binaryDir = $this->installPath . '/ext-binaries';
        mkdir($binaryDir, 0777, true);
        // A directory at the target path must not be mistaken for an existing
        // binary (is_file() guard): no unlink attempt, no verification warning.
        mkdir($binaryDir . '/' . $binaryName, 0777, true);

        $extDownloader = new FailingStubBinaryDownloader($binaryDir);
        $plugin = new StubDownloaderPlugin($this->downloadFactory($extDownloader));

        $output = $this->runPackageInstall([
            'name' => 'test/project',
            'extra' => [
                'scanmephp' => [
                    'checksums' => [
                        '0.4.6' => [$binaryName => hash('sha256', 'verified-binary-content')],
                    ],
                ],
            ],
        ], $plugin);

        $output = implode("\n", $output);
        $this->assertStringNotContainsString('failed SHA-256 verification', $output);
        $this->assertStringContainsString('download failed', $output);
        $this->assertSame(1, $extDownloader->downloadCalls);
        $this->assertDirectoryExists($binaryDir . '/' . $binaryName, 'the directory must be left untouched');
    }

    /**
     * @return list<string>
     */
    private function runPackageInstall(array $composerJson, ?Plugin $plugin = null): array
    {
        file_put_contents($this->tempDir . '/composer.json', json_encode($composerJson));

        $output = [];
        $io = $this->createMock(IOInterface::class);
        $io->method('write')->willReturnCallback(function (string $message) use (&$output): void {
            $output[] = $message;
        });

        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn($this->tempDir . '/vendor');

        $installManager = $this->createMock(InstallationManager::class);
        $installManager->method('getInstallPath')->willReturn($this->installPath);

        $composer = $this->createMock(Composer::class);
        $composer->method('getConfig')->willReturn($config);
        $composer->method('getInstallationManager')->willReturn($installManager);

        $package = $this->createMock(PackageInterface::class);
        $package->method('getName')->willReturn('crazy-goat/scanmephp');
        $package->method('getPrettyVersion')->willReturn('v0.4.6');

        $operation = new InstallOperation($package);
        $event = $this->createPackageEvent($composer, $io, $operation);

        $plugin ??= new Plugin();
        $plugin->activate($composer, $io);
        $plugin->onPackageInstall($event);

        return $output;
    }

    /**
     * Mirrors Plugin::getExtensionBinaryName() on purpose: if the plugin's
     * naming ever changes, these tests fail loudly instead of silently
     * testing a stale file path.
     */
    private function extensionBinaryName(): string
    {
        $os = PlatformDetector::getOperatingSystem();
        $arch = PlatformDetector::getArchitecture();
        $variant = $os === 'linux' ? PlatformDetector::getLinuxVariant() : null;

        if (!preg_match('/^(\d+\.\d+)/', PHP_VERSION, $matches)) {
            $this->fail('Could not determine PHP version');
        }
        $phpVersion = str_replace('.', '', $matches[1]);

        return match ($os) {
            'linux' => sprintf('php-ext-linux-%s-%s-php%s.so', $variant ?? 'glibc', $arch, $phpVersion),
            'macos' => sprintf('php-ext-macos-%s-php%s.so', $arch, $phpVersion),
            'windows' => sprintf('php-ext-windows-%s-php%s.dll', $arch, $phpVersion),
            default => $this->fail('Unsupported OS: ' . $os),
        };
    }

    /**
     * Factory for StubDownloaderPlugin: the extension path (the one under
     * test) always receives the same recording stub; the FFI path gets its
     * own failing stub rooted at its own directory, so ffi-present and
     * ffi-absent environments behave identically for the assertions.
     */
    private function downloadFactory(
        FailingStubBinaryDownloader $extDownloader,
        ?FailingStubBinaryDownloader $ffiDownloader = null
    ): \Closure {
        return function (string $binaryPath, string $version, ChecksumManager $checksumManager) use ($extDownloader, $ffiDownloader): BinaryDownloader {
            if (str_contains($binaryPath, 'ext-binaries')) {
                return $extDownloader;
            }

            return $ffiDownloader ?? new FailingStubBinaryDownloader($binaryPath);
        };
    }

    private function createPackageEvent(Composer $composer, IOInterface $io, InstallOperation $operation): PackageEvent
    {
        $localRepo = $this->createMock(RepositoryInterface::class);

        return new PackageEvent(
            PackageEvents::POST_PACKAGE_INSTALL,
            $composer,
            $io,
            false,
            $localRepo,
            [$operation],
            $operation
        );
    }

    private function recursiveDelete(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}

/**
 * Offline stand-in for a productive BinaryDownloader: never writes anything,
 * records invocations, and reports the download as failed. Used to prove the
 * unlink + fall-through orchestration of issue #185 without network access.
 */
final class FailingStubBinaryDownloader extends BinaryDownloader
{
    public int $downloadCalls = 0;

    public function __construct(string $downloadPath)
    {
        parent::__construct('crazy-goat/scanmephp', '0.4.6', $downloadPath);
    }

    public function download(string $binaryName, ?string $expectedChecksum = null): string
    {
        $this->downloadCalls++;
        throw new \RuntimeException('stub: simulated download failure');
    }
}

/**
 * Plugin that swaps the real downloader for stubs via a factory, so both the
 * extension and the FFI install paths receive a stub rooted at their own
 * directory (createDownloader() is called per path).
 */
final class StubDownloaderPlugin extends Plugin
{
    /**
     * @param \Closure(string, string, ChecksumManager): BinaryDownloader $factory
     */
    public function __construct(private readonly \Closure $factory)
    {
    }

    protected function createDownloader(string $binaryPath, string $version, ChecksumManager $checksumManager): BinaryDownloader
    {
        return ($this->factory)($binaryPath, $version, $checksumManager);
    }
}
