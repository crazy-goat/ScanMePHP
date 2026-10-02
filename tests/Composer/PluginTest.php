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
use Composer\Package\RootPackageInterface;
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

    public function testPackageInstallReplacesUnverifiedBinaryWhenNoChecksumIsPinned(): void
    {
        if (extension_loaded('scanmeqr')) {
            $this->markTestSkipped('scanmeqr extension loaded; the plugin skips binary installation entirely');
        }

        $binaryName = $this->extensionBinaryName();
        $binaryDir = $this->installPath . '/ext-binaries';
        mkdir($binaryDir, 0777, true);
        $binaryPath = $binaryDir . '/' . $binaryName;
        file_put_contents($binaryPath, 'unverified-binary-content');

        $extDownloader = new FailingStubBinaryDownloader($binaryDir);
        $plugin = new StubDownloaderPlugin($this->downloadFactory($extDownloader));

        $output = $this->runPackageInstall(['name' => 'test/project'], $plugin);

        $output = implode("\n", $output);
        $this->assertStringContainsString('cannot be verified', $output);
        $this->assertStringNotContainsString('already exists', $output);
        $this->assertFileDoesNotExist($binaryPath, 'an unverifiable binary must not stay where the loader can pick it up');
        $this->assertSame(1, $extDownloader->downloadCalls, 'the verified download path must be attempted once');
    }

    public function testPackageInstallSurvivesANonStringPin(): void
    {
        if (extension_loaded('scanmeqr')) {
            $this->markTestSkipped('scanmeqr extension loaded; the plugin skips binary installation entirely');
        }

        $binaryName = $this->extensionBinaryName();
        $binaryDir = $this->installPath . '/ext-binaries';
        mkdir($binaryDir, 0777, true);
        $binaryPath = $binaryDir . '/' . $binaryName;
        file_put_contents($binaryPath, 'unverified-binary-content');

        $extDownloader = new FailingStubBinaryDownloader($binaryDir);
        $plugin = new StubDownloaderPlugin($this->downloadFactory($extDownloader));

        // A hand-edited composer.json can hold a number where a digest belongs.
        // On main that value comes back out of getChecksum(): ?string as an int,
        // which is a TypeError — and an Error, so it escapes the installer's
        // catch(\Exception) and kills the whole composer install.
        $output = $this->runPackageInstall([
            'name' => 'test/project',
            'extra' => [
                'scanmephp' => [
                    'checksums' => ['0.4.6' => [$binaryName => 12345]],
                ],
            ],
        ], $plugin);

        $output = implode("\n", $output);
        $this->assertStringContainsString('cannot be verified', $output);
        $this->assertFileDoesNotExist($binaryPath);
        $this->assertSame(1, $extDownloader->downloadCalls);
    }

    public function testPackageInstallSurvivesANonStringChecksumSection(): void
    {
        if (extension_loaded('scanmeqr')) {
            $this->markTestSkipped('scanmeqr extension loaded; the plugin skips binary installation entirely');
        }

        $binaryName = $this->extensionBinaryName();
        $binaryDir = $this->installPath . '/ext-binaries';
        mkdir($binaryDir, 0777, true);
        $binaryPath = $binaryDir . '/' . $binaryName;
        file_put_contents($binaryPath, 'unverified-binary-content');

        $extDownloader = new FailingStubBinaryDownloader($binaryDir);
        $plugin = new StubDownloaderPlugin($this->downloadFactory($extDownloader));

        // A scalar where the version map belongs. On main this reached the
        // ?array property assignment and raised a TypeError, which is an Error
        // and therefore escapes the plugin's catch (\Exception).
        $output = $this->runPackageInstall([
            'name' => 'test/project',
            'extra' => ['scanmephp' => ['checksums' => 'oops']],
        ], $plugin);

        $output = implode("\n", $output);
        $this->assertStringContainsString('cannot be verified', $output);
        $this->assertFileDoesNotExist($binaryPath);
        $this->assertSame(1, $extDownloader->downloadCalls);
    }

    public function testPackageInstallRefusesAWholeSha256SumLineBeforeDownloading(): void
    {
        if (extension_loaded('scanmeqr')) {
            $this->markTestSkipped('scanmeqr extension loaded; the plugin skips binary installation entirely');
        }

        $binaryName = $this->extensionBinaryName();
        $digest = hash('sha256', 'verified-binary-content');

        // The mistake the README warns about: the whole checksums.txt line as
        // the value. No stub downloader here on purpose — the real one refuses
        // a download without a usable digest before it opens a connection, so
        // "refused" in the output is the pre-download refusal and nothing was
        // fetched.
        $output = $this->runPackageInstall([
            'name' => 'test/project',
            'extra' => [
                'scanmephp' => [
                    'checksums' => ['0.4.6' => [$binaryName => $digest . '  ' . $binaryName]],
                ],
            ],
        ]);

        $output = implode("\n", $output);
        $this->assertStringContainsString('refused', $output);
        $this->assertStringContainsString('extra.scanmephp.checksums', $output);
        $this->assertStringNotContainsString('downloaded successfully', $output);
    }

    public function testUnremovableBinaryIsReportedInsteadOfDownloaded(): void
    {
        if (extension_loaded('scanmeqr')) {
            $this->markTestSkipped('scanmeqr extension loaded; the plugin skips binary installation entirely');
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions, so unlink() cannot be made to fail');
        }

        $binaryName = $this->extensionBinaryName();
        $binaryDir = $this->installPath . '/ext-binaries';
        mkdir($binaryDir, 0777, true);
        $binaryPath = $binaryDir . '/' . $binaryName;
        file_put_contents($binaryPath, 'unverified-binary-content');
        // Read-only directory: unlink() of the entry fails, which must not be
        // swallowed — the loaders would still find the file there.
        chmod($binaryDir, 0500);

        try {
            $extDownloader = new FailingStubBinaryDownloader($binaryDir);
            $plugin = new StubDownloaderPlugin($this->downloadFactory($extDownloader));

            $output = implode("\n", $this->runPackageInstall(['name' => 'test/project'], $plugin));

            $this->assertStringContainsString('Could not remove the unverifiable binary', $output);
            $this->assertStringNotContainsString('Re-downloading the verified binary', $output);
            $this->assertStringNotContainsString('Downloading extension binary', $output);
            $this->assertSame(0, $extDownloader->downloadCalls, 'a binary that is still there must not be replaced silently');
            $this->assertSame('unverified-binary-content', file_get_contents($binaryPath));
        } finally {
            chmod($binaryDir, 0700);
            unlink($binaryPath);
        }
    }

    public function testUnremovableFfiLibraryIsReportedInsteadOfDownloaded(): void
    {
        if (extension_loaded('scanmeqr') || !extension_loaded('ffi')) {
            $this->markTestSkipped('requires the FFI extension (FAQ-003) and no preloaded scanmeqr extension');
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions, so unlink() cannot be made to fail');
        }

        $os = PlatformDetector::getOperatingSystem();
        $arch = PlatformDetector::getArchitecture();
        $variant = $os === 'linux' ? PlatformDetector::getLinuxVariant() : null;
        $binaryName = PlatformDetector::getBinaryName($os, $variant, $arch);
        $binaryDir = $this->installPath . '/ffi-binaries';
        mkdir($binaryDir, 0777, true);
        $binaryPath = $binaryDir . '/' . $binaryName;
        file_put_contents($binaryPath, 'unverified-ffi-binary-content');
        chmod($binaryDir, 0500);

        try {
            $extDownloader = new FailingStubBinaryDownloader($this->installPath . '/ext-binaries');
            $ffiDownloader = new FailingStubBinaryDownloader($binaryDir);
            $plugin = new StubDownloaderPlugin($this->downloadFactory($extDownloader, $ffiDownloader));

            $output = implode("\n", $this->runPackageInstall(['name' => 'test/project'], $plugin));

            // Same guarantee as the extension branch: no download into a path
            // that could not be cleared, and the file is still there.
            $this->assertStringContainsString('Could not remove the unverifiable binary', $output);
            $this->assertStringNotContainsString('Re-downloading the verified library', $output);
            $this->assertStringNotContainsString('Downloading FFI library', $output);
            $this->assertSame(0, $ffiDownloader->downloadCalls);
            $this->assertSame('unverified-ffi-binary-content', file_get_contents($binaryPath));
        } finally {
            chmod($binaryDir, 0700);
            unlink($binaryPath);
        }
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
        $this->assertStringContainsString('failed SHA-256 verification.', $output);
        $this->assertStringContainsString('Re-downloading the verified', $output);
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
        $this->assertStringContainsString('failed SHA-256 verification.', $output);
        $this->assertStringContainsString('Re-downloading the verified', $output);
        $this->assertFileDoesNotExist($binaryPath, 'the mismatched FFI library must be unlinked before the re-download');
        $this->assertStringContainsString('FFI library download failed', $output);
        $this->assertSame(1, $ffiDownloader->downloadCalls, 'the FFI verified download path must be invoked exactly once');
    }

    public function testDownloadUrlUsesLibraryVersionNotRootPackageVersion(): void
    {
        if (extension_loaded('scanmeqr')) {
            $this->markTestSkipped('scanmeqr extension loaded; the plugin skips binary installation entirely');
        }

        $plugin = new UrlRecordingPlugin();

        $this->runPackageInstall(['name' => 'test/project'], $plugin);

        $this->assertNotSame([], $plugin->urls, 'the plugin must create at least one downloader');
        foreach ($plugin->urls as $url) {
            $this->assertSame('https://github.com/crazy-goat/scanmephp/releases/download/v0.4.6/binary', $url);
        }
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
     * @param array<string, mixed> $composerJson
     *
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

        // The consuming application has its own, unrelated version (#69).
        $rootPackage = $this->createMock(RootPackageInterface::class);
        $rootPackage->method('getName')->willReturn('test/project');
        $rootPackage->method('getPrettyVersion')->willReturn('v9.9.9');
        $rootPackage->method('getVersion')->willReturn('9.9.9.0');
        $composer->method('getPackage')->willReturn($rootPackage);

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

/**
 * Plugin that keeps the production createDownloader() and records the URL its
 * downloader would use, then hands back an offline stub.
 */
final class UrlRecordingPlugin extends Plugin
{
    /** @var list<string> */
    public array $urls = [];

    protected function createDownloader(string $binaryPath, string $version, ChecksumManager $checksumManager): \CrazyGoat\ScanMePHP\Tests\Composer\FailingStubBinaryDownloader
    {
        $this->urls[] = parent::createDownloader($binaryPath, $version, $checksumManager)->getDownloadUrl('binary');

        return new FailingStubBinaryDownloader($binaryPath);
    }
}
