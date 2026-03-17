<?php
declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests\Composer;

use CrazyGoat\ScanMePHP\Composer\InstallScript;
use PHPUnit\Framework\TestCase;

class InstallScriptTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scanme_composer_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->recursiveDelete($this->tempDir);
        }
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

    public function testGetBinaryPath(): void
    {
        $path = InstallScript::getBinaryPath($this->tempDir);
        $this->assertStringContainsString('ffi-binaries', $path);
    }

    public function testGetPackageVersionFromComposer(): void
    {
        // Create a mock composer.json
        $composerJson = [
            'name' => 'test/project',
            'require' => [
                'crazy-goat/scanmephp' => '^0.4.0',
            ],
        ];
        
        file_put_contents(
            $this->tempDir . '/composer.json',
            json_encode($composerJson, JSON_PRETTY_PRINT)
        );
        
        // Create vendor directory with installed.json
        mkdir($this->tempDir . '/vendor/composer', 0777, true);
        $installedJson = [
            'packages' => [
                [
                    'name' => 'crazy-goat/scanmephp',
                    'version' => 'v0.4.4',
                ],
            ],
        ];

        file_put_contents(
            $this->tempDir . '/vendor/composer/installed.json',
            json_encode($installedJson, JSON_PRETTY_PRINT)
        );

        $version = InstallScript::getPackageVersion($this->tempDir);
        $this->assertEquals('0.4.4', $version);
    }
}
