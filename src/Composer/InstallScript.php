<?php
declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Composer;

use CrazyGoat\ScanMePHP\BinaryDownloader;
use CrazyGoat\ScanMePHP\Builder;
use CrazyGoat\ScanMePHP\ChecksumManager;
use CrazyGoat\ScanMePHP\PlatformDetector;

class InstallScript
{
    private const PACKAGE_NAME = 'crazy-goat/scanmephp';
    private const BINARY_DIR = 'ffi-binaries';

    public static function run(): void
    {
        echo "ScanMePHP FFI Binary Installer\n";
        echo "================================\n\n";
        
        // Check if FFI is available
        if (!extension_loaded('ffi')) {
            echo "⚠️  FFI extension is not available. Skipping binary download.\n";
            echo "   The pure PHP encoder will be used instead.\n";
            return;
        }
        
        echo "✓ FFI extension is available\n";
        
        // Detect platform
        try {
            $os = PlatformDetector::getOperatingSystem();
            $arch = PlatformDetector::getArchitecture();
            $variant = $os === 'linux' ? PlatformDetector::getLinuxVariant() : null;
            
            echo sprintf("✓ Detected platform: %s %s%s\n", 
                $os, 
                $variant ? $variant . ' ' : '', 
                $arch
            );
        } catch (\RuntimeException $e) {
            echo "⚠️  Platform detection failed: " . $e->getMessage() . "\n";
            echo "   Skipping binary download.\n";
            return;
        }
        
        // Get binary name
        $binaryName = PlatformDetector::getBinaryName($os, $variant, $arch);
        echo "✓ Target binary: $binaryName\n";
        
        // Determine paths
        $projectRoot = self::findProjectRoot();
        $binaryPath = self::getBinaryPath($projectRoot);
        
        // Check if binary already exists
        $targetFile = $binaryPath . '/' . $binaryName;
        if (file_exists($targetFile)) {
            echo "✓ Binary already exists at: $targetFile\n";
            echo "  To re-download, delete the file and run composer install again.\n";
            return;
        }
        
        // Get package version
        try {
            $version = self::getPackageVersion($projectRoot);
            echo "✓ Package version: $version\n";
        } catch (\RuntimeException $e) {
            echo "⚠️  Could not determine package version: " . $e->getMessage() . "\n";
            echo "   Skipping binary download.\n";
            return;
        }
        
        // Download binary
        echo "\n📥 Downloading binary...\n";
        
        try {
            $checksumManager = new ChecksumManager($projectRoot);
            
            $downloader = new BinaryDownloader(
                'crazy-goat/scanmephp',
                $version,
                $binaryPath,
                $checksumManager
            );
            
            $downloadedPath = $downloader->download($binaryName);
            
            echo "✓ Binary downloaded successfully to: $downloadedPath\n";
            echo "\n🎉 FFI binary is ready to use!\n";
            echo "   Use FfiEncoder with: '$downloadedPath'\n";
        } catch (\Exception $e) {
            echo "⚠️  Download failed: " . $e->getMessage() . "\n";
            
            // Try to build from source
            echo "\n🔧 Attempting to build from source...\n";
            
            try {
                $builder = new Builder($projectRoot);
                
                if (!$builder->isBuildAvailable()) {
                    echo "⚠️  Build tools not available (cmake and C++ compiler required)\n";
                    echo "   The pure PHP encoder will be used instead.\n";
                    echo "   You can manually download the binary from GitHub releases.\n";
                    return;
                }
                
                echo "✓ Build tools detected\n";
                
                $builtPath = $builder->build();
                
                // Copy to ffi-binaries directory
                $targetPath = $binaryPath . '/' . basename($builtPath);
                copy($builtPath, $targetPath);
                chmod($targetPath, 0755);
                
                echo "✓ Binary built and installed at: $targetPath\n";
                echo "\n🎉 FFI binary is ready to use!\n";
            } catch (\Exception $buildError) {
                echo "⚠️  Build failed: " . $buildError->getMessage() . "\n";
                echo "   The pure PHP encoder will be used instead.\n";
                echo "   You can manually download the binary from GitHub releases.\n";
            }
        }
    }

    public static function getBinaryPath(string $projectRoot): string
    {
        $path = $projectRoot . '/' . self::BINARY_DIR;
        
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        
        return $path;
    }

    public static function getPackageVersion(string $projectRoot): string
    {
        // Try to get version from git tag (preferred for development/root project)
        if (is_dir($projectRoot . '/.git')) {
            $tag = trim(shell_exec('git describe --tags --abbrev=0 2>/dev/null') ?: '');
            if ($tag !== '') {
                return ltrim($tag, 'v');
            }
        }

        // Fallback: try to read from composer/installed.json
        $installedJsonPath = $projectRoot . '/vendor/composer/installed.json';

        if (file_exists($installedJsonPath)) {
            $installed = json_decode(file_get_contents($installedJsonPath), true);

            if (json_last_error() === JSON_ERROR_NONE) {
                // Handle both old and new composer formats
                $packages = $installed['packages'] ?? $installed;

                foreach ($packages as $package) {
                    if ($package['name'] === self::PACKAGE_NAME) {
                        $version = $package['version'];
                        // Normalize version (remove 'v' prefix if present for consistency)
                        return ltrim($version, 'v');
                    }
                }
            }
        }

        throw new \RuntimeException('Cannot determine package version: no git tag and package not found in installed.json');
    }

    private static function findProjectRoot(): string
    {
        // Start from current directory and go up until we find composer.json
        $dir = getcwd();
        
        while ($dir !== '/') {
            if (file_exists($dir . '/composer.json')) {
                return $dir;
            }
            
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        
        // Fallback to current directory
        return getcwd() ?: __DIR__ . '/../../..';
    }
}
