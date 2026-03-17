<?php
declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

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
        $package = $event->getOperation()->getPackage();
        if ($package->getName() === self::PACKAGE_NAME) {
            $this->installBinaries($event->getComposer(), $package);
        }
    }

    public function onPackageUpdate(PackageEvent $event): void
    {
        $package = $event->getOperation()->getTargetPackage();
        if ($package->getName() === self::PACKAGE_NAME) {
            $this->installBinaries($event->getComposer(), $package);
        }
    }

    private function installBinaries(Composer $composer, $package): void
    {
        $this->io->write('ScanMePHP Binary Installer (Plugin)');
        $this->io->write('====================================');
        $this->io->write('');

        // Get installation path
        $installManager = $composer->getInstallationManager();
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
            $os = $this->getOperatingSystem();
            $arch = $this->getArchitecture();
            $variant = $os === 'linux' ? $this->getLinuxVariant() : null;

            $this->io->write(sprintf('✓ Detected platform: %s %s%s',
                $os,
                $variant ? $variant . ' ' : '',
                $arch
            ));
        } catch (\RuntimeException $e) {
            $this->io->write('⚠️  Platform detection failed: ' . $e->getMessage());
            $this->io->write('   Skipping binary download.');
            return;
        }

        // Try to install PHP extension first (preferred for performance)
        $extInstalled = $this->installExtensionBinary($installPath, $os, $variant, $arch);

        // If extension not available, try FFI
        if (!$extInstalled) {
            $this->installFfiBinary($installPath, $os, $variant, $arch);
        }

        $this->io->write('');
    }

    private function installExtensionBinary(string $installPath, string $os, ?string $variant, string $arch): bool
    {
        $this->io->write('');
        $this->io->write('📦 PHP Extension Installation');
        $this->io->write('─────────────────────────────');

        // Check if extension is already loaded
        if (extension_loaded('scanmeqr')) {
            $this->io->write('✓ PHP extension scanmeqr is already loaded');
            return true;
        }

        $binaryPath = rtrim($installPath, '/') . '/' . self::EXT_BINARY_DIR;
        $binaryName = $this->getExtensionBinaryName($os, $variant, $arch);
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
            $this->downloadBinary($binaryName, $binaryPath);
            $this->io->write('✓ Extension binary downloaded successfully to: ' . $targetFile);
            $this->io->write('');
            $this->io->write('📝 To enable the extension, add to your php.ini:');
            $this->io->write('   extension=' . $binaryName);
            $this->io->write('');
            $this->io->write('   Or copy it to your PHP extensions directory:');
            $this->io->write('   cp ' . $targetFile . ' $(php-config --extension-dir)/');
            $this->io->write('');
            return true;
        } catch (\Exception $e) {
            $this->io->write('⚠️  Extension download failed: ' . $e->getMessage());
            $this->io->write('   Falling back to FFI encoder.');
            return false;
        }
    }

    private function installFfiBinary(string $installPath, string $os, ?string $variant, string $arch): void
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
        $binaryName = $this->getFfiBinaryName($os, $variant, $arch);
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
            $this->downloadBinary($binaryName, $binaryPath);
            $this->io->write('✓ FFI library downloaded successfully to: ' . $targetFile);
            $this->io->write('');
            $this->io->write('🎉 FFI library is ready to use!');
        } catch (\Exception $e) {
            $this->io->write('⚠️  FFI library download failed: ' . $e->getMessage());
            $this->io->write('   The pure PHP encoder will be used instead.');
        }
    }

    private function downloadBinary(string $binaryName, string $binaryPath): void
    {
        // Get version from package
        $package = $this->composer->getPackage();
        $version = ltrim($package->getPrettyVersion(), 'v');

        // Normalize version to include 'v' prefix for GitHub releases URL
        $versionWithTag = str_starts_with($version, 'v') ? $version : 'v' . $version;

        $url = sprintf(
            'https://github.com/%s/releases/download/%s/%s',
            self::PACKAGE_NAME,
            $versionWithTag,
            $binaryName
        );

        $targetPath = $binaryPath . '/' . $binaryName;

        // Download using cURL
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL');
        }

        $fp = fopen($targetPath, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Failed to open target file');
        }

        try {
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 300);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $result = curl_exec($ch);

            if ($result === false) {
                throw new \RuntimeException(curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode !== 200) {
                throw new \RuntimeException('HTTP ' . $httpCode);
            }
        } finally {
            curl_close($ch);
            fclose($fp);
        }

        // Make executable on Unix systems
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($targetPath, 0755);
        }
    }

    private function getOperatingSystem(): string
    {
        return match (PHP_OS_FAMILY) {
            'Linux' => 'linux',
            'Darwin' => 'macos',
            'Windows' => 'windows',
            default => throw new \RuntimeException('Unsupported OS: ' . PHP_OS_FAMILY),
        };
    }

    private function getArchitecture(): string
    {
        $arch = php_uname('m');

        return match ($arch) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            default => throw new \RuntimeException('Unsupported architecture: ' . $arch),
        };
    }

    private function getLinuxVariant(): string
    {
        // Check for musl by looking at ldd output
        $lddOutput = shell_exec('ldd --version 2>&1');
        if ($lddOutput !== null && str_contains($lddOutput, 'musl')) {
            return 'musl';
        }

        // Check /proc/version for musl
        $procVersion = @file_get_contents('/proc/version');
        if ($procVersion !== false && str_contains($procVersion, 'musl')) {
            return 'musl';
        }

        return 'glibc';
    }

    private function getExtensionBinaryName(string $os, ?string $variant, string $arch): string
    {
        return match ($os) {
            'linux' => sprintf('php-ext-linux-%s-%s.so', $variant ?? 'glibc', $arch),
            'macos' => sprintf('php-ext-macos-%s.so', $arch),
            'windows' => sprintf('php-ext-windows-%s.dll', $arch),
            default => throw new \RuntimeException('Unsupported OS: ' . $os),
        };
    }

    private function getFfiBinaryName(string $os, ?string $variant, string $arch): string
    {
        return match ($os) {
            'linux' => sprintf('libscanme_qr-linux-%s-%s.so', $variant ?? 'glibc', $arch),
            'macos' => sprintf('libscanme_qr-macos-%s.dylib', $arch),
            'windows' => sprintf('scanme_qr-windows-%s.dll', $arch),
            default => throw new \RuntimeException('Unsupported OS: ' . $os),
        };
    }
}
