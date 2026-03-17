<?php
declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

class PlatformDetector
{
    public static function getOperatingSystem(): string
    {
        return match (PHP_OS_FAMILY) {
            'Linux' => 'linux',
            'Darwin' => 'macos',
            'Windows' => 'windows',
            default => throw new \RuntimeException('Unsupported OS: ' . PHP_OS_FAMILY),
        };
    }

    public static function getArchitecture(): string
    {
        $arch = php_uname('m');
        
        return match ($arch) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            default => throw new \RuntimeException('Unsupported architecture: ' . $arch),
        };
    }

    public static function getLinuxVariant(): string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException('Linux variant detection only works on Linux');
        }
        
        // Check for musl by looking at ldd output or /proc/version
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

    public static function getBinaryName(string $os, ?string $variant, string $arch): string
    {
        return match ($os) {
            'linux' => sprintf('libscanme_qr-linux-%s-%s.so', $variant ?? 'glibc', $arch),
            'macos' => sprintf('libscanme_qr-macos-%s.dylib', $arch),
            'windows' => sprintf('scanme_qr-windows-%s.dll', $arch),
            default => throw new \RuntimeException('Unsupported OS: ' . $os),
        };
    }

    public static function getCurrentPlatformBinaryName(): string
    {
        $os = self::getOperatingSystem();
        $arch = self::getArchitecture();
        $variant = $os === 'linux' ? self::getLinuxVariant() : null;
        
        return self::getBinaryName($os, $variant, $arch);
    }
}
