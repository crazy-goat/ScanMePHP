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
use CrazyGoat\ScanMePHP\Composer\Plugin;
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
        file_put_contents($this->tempDir . '/composer.json', json_encode(['name' => 'test/project']));

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

        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->onPackageInstall($event);

        if (!extension_loaded('scanmeqr')) {
            $output = implode("\n", $output);
            $this->assertStringContainsString('refused', $output);
            $this->assertStringContainsString('extra.scanmephp.checksums', $output);
        }

        foreach ([$this->installPath . '/ext-binaries', $this->installPath . '/ffi-binaries'] as $dir) {
            $this->assertSame([], glob($dir . '/*') ?: []);
        }
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
