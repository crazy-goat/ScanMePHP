<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use CrazyGoat\ScanMePHP\BinaryDownloader;
use CrazyGoat\ScanMePHP\ChecksumManager;
use CrazyGoat\ScanMePHP\Exception\DownloadException;
use CrazyGoat\ScanMePHP\PlatformDetector;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE_NAME = 'crazy-goat/scanmephp';
    private const FFI_BINARY_DIR = 'ffi-binaries';
    private const EXT_BINARY_DIR = 'ext-binaries';

    private Composer $composer;
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => 'onPackageInstall',
            PackageEvents::POST_PACKAGE_UPDATE => 'onPackageUpdate',
        ];
    }

    public function onPackageInstall(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        if (!$operation instanceof \Composer\DependencyResolver\Operation\InstallOperation) {
            return;
        }
        $package = $operation->getPackage();
        if ($package->getName() === self::PACKAGE_NAME) {
            $this->installBinaries($package);
        }
    }

    public function onPackageUpdate(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        if (!$operation instanceof \Composer\DependencyResolver\Operation\UpdateOperation) {
            return;
        }
        $package = $operation->getTargetPackage();
        if ($package->getName() === self::PACKAGE_NAME) {
            $this->installBinaries($package);
        }
    }

    private function installBinaries(\Composer\Package\PackageInterface $package): void
    {
        $this->io->write('ScanMePHP Binary Installer (Plugin)');
        $this->io->write('====================================');
        $this->io->write('');

        // Get installation path
        $installManager = $this->composer->getInstallationManager();
        $installPath = $installManager->getInstallPath($package);

        // Get version - skip download for dev versions
        $version = ltrim($package->getPrettyVersion(), 'v');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            $this->io->write('⚠️  Development version detected (' . $version . '). Skipping binary download.');
            $this->io->write('   Run "composer require crazy-goat/scanmephp:^0.4.6" for stable release.');
            return;
        }

        $this->io->write('✓ Package version: ' . $version);

        // Detect platform
        try {
            $os = PlatformDetector::getOperatingSystem();
            $arch = PlatformDetector::getArchitecture();
            $variant = $os === 'linux' ? PlatformDetector::getLinuxVariant() : null;

            $this->io->write(sprintf(
                '✓ Detected platform: %s %s%s',
                $os,
                $variant ? $variant . ' ' : '',
                $arch
            ));
        } catch (\RuntimeException $e) {
            $this->io->write('⚠️  Platform detection failed: ' . $e->getMessage());
            $this->io->write('   Skipping binary download.');
            return;
        }

        // Checksums are pinned by the root project's composer.json (extra.scanmephp.checksums)
        $checksumManager = new ChecksumManager($this->getProjectRoot());

        // Try to install PHP extension first (preferred for performance)
        $extInstalled = $this->installExtensionBinary($installPath, $os, $variant, $arch, $version, $checksumManager);

        // If extension not available, try FFI
        if (!$extInstalled) {
            $this->installFfiBinary($installPath, $os, $variant, $arch, $version, $checksumManager);
        }

        $this->io->write('');
    }

    private function installExtensionBinary(string $installPath, string $os, ?string $variant, string $arch, string $version, ChecksumManager $checksumManager): bool
    {
        $this->io->write('');
        $this->io->write('📦 PHP Extension Installation');
        $this->io->write('─────────────────────────────');

        // Check if extension is already loaded
        if (extension_loaded('scanmeqr')) {
            $this->io->write('✓ PHP extension scanmeqr is already loaded');
            return true;
        }

        // Get PHP version
        $phpVersion = $this->getPhpVersion();
        $this->io->write('✓ Detected PHP version: ' . $phpVersion);

        $binaryPath = rtrim($installPath, '/') . '/' . self::EXT_BINARY_DIR;
        $binaryName = $this->getExtensionBinaryName($os, $variant, $arch, $phpVersion);
        $targetFile = $binaryPath . '/' . $binaryName;

        $this->io->write('✓ Target extension: ' . $binaryName);

        // Check if binary already exists
        if (file_exists($targetFile)) {
            $this->io->write('✓ Extension binary already exists at: ' . $targetFile);
            $this->io->write('');
            $this->io->write('📝 To enable the extension, add to your php.ini:');
            $this->io->write('   extension=' . $targetFile);
            return true;
        }

        // Create binary directory
        if (!is_dir($binaryPath)) {
            mkdir($binaryPath, 0755, true);
        }

        // Download binary
        $this->io->write('📥 Downloading extension binary...');

        try {
            $this->createDownloader($binaryPath, $version, $checksumManager)->download($binaryName);
            $this->io->write('✓ Extension binary downloaded successfully to: ' . $targetFile);
            $this->io->write('');
            $this->io->write('📝 To enable the extension, add to your php.ini:');
            $this->io->write('   extension=' . $binaryName);
            $this->io->write('');
            $this->io->write('   Or copy it to your PHP extensions directory:');
            $this->io->write('   cp ' . $targetFile . ' $(php-config --extension-dir)/');
            $this->io->write('');
            return true;
        } catch (DownloadException $e) {
            if ($e->getMessage() === DownloadException::checksumMissing($binaryName)->getMessage()) {
                $this->io->write('⛔ ' . $e->getMessage());
                $this->io->write('   Verified native extension install is disabled until a checksum is configured.');
                $this->io->write('   The pure PHP encoder will be used instead.');
            } else {
                $this->io->write('⚠️  Extension download failed: ' . $e->getMessage());
                $this->io->write('   Falling back to FFI encoder.');
            }
            return false;
        } catch (\Exception $e) {
            $this->io->write('⚠️  Extension download failed: ' . $e->getMessage());
            $this->io->write('   Falling back to FFI encoder.');
            return false;
        }
    }

    private function installFfiBinary(string $installPath, string $os, ?string $variant, string $arch, string $version, ChecksumManager $checksumManager): void
    {
        $this->io->write('');
        $this->io->write('📦 FFI Library Installation');
        $this->io->write('───────────────────────────');

        // Check if FFI is available
        if (!extension_loaded('ffi')) {
            $this->io->write('⚠️  FFI extension is not available.');
            $this->io->write('   The pure PHP encoder will be used instead.');
            return;
        }

        $this->io->write('✓ FFI extension is available');

        $binaryPath = rtrim($installPath, '/') . '/' . self::FFI_BINARY_DIR;
        $binaryName = PlatformDetector::getBinaryName($os, $variant, $arch);
        $targetFile = $binaryPath . '/' . $binaryName;

        $this->io->write('✓ Target library: ' . $binaryName);

        // Check if binary already exists
        if (file_exists($targetFile)) {
            $this->io->write('✓ FFI library already exists at: ' . $targetFile);
            $this->io->write('🎉 FFI library is ready to use!');
            return;
        }

        // Create binary directory
        if (!is_dir($binaryPath)) {
            mkdir($binaryPath, 0755, true);
        }

        // Download binary
        $this->io->write('📥 Downloading FFI library...');

        try {
            $this->createDownloader($binaryPath, $version, $checksumManager)->download($binaryName);
            $this->io->write('✓ FFI library downloaded successfully to: ' . $targetFile);
            $this->io->write('');
            $this->io->write('🎉 FFI library is ready to use!');
        } catch (DownloadException $e) {
            if ($e->getMessage() === DownloadException::checksumMissing($binaryName)->getMessage()) {
                $this->io->write('⛔ ' . $e->getMessage());
                $this->io->write('   Verified native FFI library install is disabled until a checksum is configured.');
                $this->io->write('   The pure PHP encoder will be used instead.');
            } else {
                $this->io->write('⚠️  FFI library download failed: ' . $e->getMessage());
                $this->io->write('   The pure PHP encoder will be used instead.');
            }
        } catch (\Exception $e) {
            $this->io->write('⚠️  FFI library download failed: ' . $e->getMessage());
            $this->io->write('   The pure PHP encoder will be used instead.');
        }
    }

    private function createDownloader(string $binaryPath, string $version, ChecksumManager $checksumManager): BinaryDownloader
    {
        return new BinaryDownloader(self::PACKAGE_NAME, $version, $binaryPath, $checksumManager);
    }

    private function getProjectRoot(): string
    {
        return dirname((string) $this->composer->getConfig()->get('vendor-dir'));
    }

    private function getPhpVersion(): string
    {
        $version = PHP_VERSION;
        // Extract major.minor version (e.g., 8.1 from 8.1.27)
        if (preg_match('/^(\d+\.\d+)/', $version, $matches)) {
            return str_replace('.', '', $matches[1]); // Return "81" for "8.1"
        }
        throw new \RuntimeException('Could not determine PHP version');
    }

    private function getExtensionBinaryName(string $os, ?string $variant, string $arch, string $phpVersion): string
    {
        return match ($os) {
            'linux' => sprintf('php-ext-linux-%s-%s-php%s.so', $variant ?? 'glibc', $arch, $phpVersion),
            'macos' => sprintf('php-ext-macos-%s-php%s.so', $arch, $phpVersion),
            'windows' => sprintf('php-ext-windows-%s-php%s.dll', $arch, $phpVersion),
            default => throw new \RuntimeException('Unsupported OS: ' . $os),
        };
    }
}
