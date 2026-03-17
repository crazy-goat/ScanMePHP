<?php
declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Builder;
use PHPUnit\Framework\TestCase;

class BuilderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/scanme_build_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            // Cleanup
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            
            rmdir($this->tempDir);
        }
    }

    public function testDetectsBuildTools(): void
    {
        $builder = new Builder($this->tempDir);
        
        // Just test that it doesn't throw
        $available = $builder->isBuildAvailable();
        $this->assertIsBool($available);
    }

    public function testFindsClibDirectory(): void
    {
        // Create mock clib structure
        mkdir($this->tempDir . '/clib', 0777, true);
        mkdir($this->tempDir . '/clib/build', 0777, true);
        
        $builder = new Builder($this->tempDir);
        $clibPath = $builder->getClibPath();
        
        $this->assertEquals($this->tempDir . '/clib', $clibPath);
    }
}
