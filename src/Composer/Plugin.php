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
    private const BINARY_DIR = 'ffi-binaries';

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
            $this->installBinary($event->getComposer(), $package);
        }
    }

    public function onPackageUpdate(PackageEvent $event): void
    {
        $package = $event->getOperation()->getTargetPackage();
        if ($package->getName() === self::PACKAGE_NAME) {
            $this->installBinary($event->getComposer(), $package);
        }
    }

    private function installBinary(Composer $composer, $package): void
    {
        $this->io->write('ScanMePHP FFI Binary Installer (Plugin)');
        $this->io->write('========================================');
        $this->io->write('');

        // Check if FFI is available
        if (!extension_loaded('ffi')) {
            $this->io->write('⚠️  FFI extension is not available. Skipping binary download.');
            $this->io->write('   The pure PHP encoder will be used instead.');
            return;
        }

        $this->io->write('✓ FFI extension is available');

        // Get installation path
        $installManager = $composer->getInstallationManager();
        $installPath = $installManager->getInstallPath($package);
        $binaryPath = rtrim($installPath, '/') . '/' . self::BINARY_DIR;

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

        // Get binary name
        $binaryName = $this->getBinaryName($os, $variant, $arch);
        $this->io->write('✓ Target binary: ' . $binaryName);

        // Check if binary already exists
        $targetFile = $binaryPath . '/' . $binaryName;
        if (file_exists($targetFile)) {
            $this->io->write('✓ Binary already exists at: ' . $targetFile);
            return;
        }

        // Create binary directory
        if (!is_dir($binaryPath)) {
            mkdir($binaryPath, 0755, true);
        }

        // Get version - skip download for dev versions
        $version = ltrim($package->getPrettyVersion(), 'v');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            $this->io->write('⚠️  Development version detected (' . $version . '). Skipping binary download.');
            $this->io->write('   Run "composer require crazy-goat/scanmephp:^0.4.6" for stable release.');
            $this->io->write('   The pure PHP encoder will be used instead.');
            return;
        }

        $this->io->write('✓ Package version: ' . $version);

        // Download binary
        $this->io->write('');
        $this->io->write('📥 Downloading binary...');

        try {
            $this->downloadBinary($version, $binaryName, $binaryPath);
            $this->io->write('✓ Binary downloaded successfully to: ' . $targetFile);
            $this->io->write('');
            $this->io->write('🎉 FFI binary is ready to use!');
        } catch (\Exception $e) {
            $this->io->write('⚠️  Download failed: ' . $e->getMessage());
            $this->io->write('   The pure PHP encoder will be used instead.');
        }
    }

    private function downloadBinary(string $version, string $binaryName, string $binaryPath): void
    {
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

    private function getBinaryName(string $os, ?string $variant, string $arch): string
    {
        return match ($os) {
            'linux' => sprintf('libscanme_qr-linux-%s-%s.so', $variant ?? 'glibc', $arch),
            'macos' => sprintf('libscanme_qr-macos-%s.dylib', $arch),
            'windows' => sprintf('scanme_qr-windows-%s.dll', $arch),
            default => throw new \RuntimeException('Unsupported OS: ' . $os),
        };
    }
}
