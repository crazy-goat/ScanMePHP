<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Builder;
use CrazyGoat\ScanMePHP\Exception\BuildException;
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

    /**
     * When build tools are absent, build() must throw BuildException
     * (not a bare RuntimeException) with a sanitised message.
     */
    public function testBuildThrowsBuildExceptionWhenToolsUnavailable(): void
    {
        // tempDir has no clib/ directory, so isBuildAvailable() is false.
        $builder = new Builder($this->tempDir);

        $this->expectException(BuildException::class);
        $this->expectExceptionMessage('Build tools not available');

        $builder->build();
    }

    /**
     * Exception messages must not leak raw command output / local paths.
     * The factory message for a failed step is static and contains only
     * the exit code, never stdout/stderr.
     */
    public function testBuildExceptionMessageDoesNotLeakOutput(): void
    {
        // tempDir has no clib/ so we get the tools-not-available path.
        $builder = new Builder($this->tempDir);

        try {
            $builder->build();
            $this->fail('Expected BuildException was not thrown');
        } catch (BuildException $e) {
            $this->assertStringNotContainsString($this->tempDir, $e->getMessage());
            $this->assertStringNotContainsString('2>&1', $e->getMessage());
        }
    }

    /**
     * Every BuildException factory must produce a sanitised message:
     * no raw command output, no `2>&1`, no absolute paths, and the
     * exception must extend \RuntimeException for backward compatibility.
     * Guards the security invariant for all four throw paths, not just the
     * tools-not-available one reached by build() in the test environment.
     *
     * @dataProvider sanitisedFactoryProvider
     */
    public function testBuildExceptionFactoriesAreSanitised(string $factory, array $args, string $expectedFragment): void
    {
        /** @var BuildException $e */
        $e = call_user_func_array([BuildException::class, $factory], $args);

        $this->assertInstanceOf(BuildException::class, $e);
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertStringContainsString($expectedFragment, $e->getMessage());
        // Security invariant: no raw output merge, no absolute path leak.
        $this->assertStringNotContainsString('2>&1', $e->getMessage());
        $this->assertStringNotContainsString('/Users/', $e->getMessage());
        $this->assertStringNotContainsString('C:\\', $e->getMessage());
        $this->assertDoesNotMatchRegularExpression('#^/[A-Za-z]|\\\\[A-Z]:#', $e->getMessage());
    }

    /**
     * @return list<array{0:string,1:array<int,mixed>,2:string}>
     */
    public static function sanitisedFactoryProvider(): array
    {
        return [
            ['toolsNotAvailable', [], 'Build tools not available'],
            ['cmakeFailed', [127], 'exit code 127'],
            ['buildFailed', [2], 'exit code 2'],
            ['libraryNotFound', ['/Users/secret/project/clib/build'], 'build directory'],
        ];
    }
}
