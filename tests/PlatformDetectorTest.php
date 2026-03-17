<?php
declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\PlatformDetector;
use PHPUnit\Framework\TestCase;

class PlatformDetectorTest extends TestCase
{
    public function testDetectsOperatingSystem(): void
    {
        $os = PlatformDetector::getOperatingSystem();
        $this->assertIsString($os);
        $this->assertContains($os, ['linux', 'macos', 'windows']);
    }

    public function testDetectsArchitecture(): void
    {
        $arch = PlatformDetector::getArchitecture();
        $this->assertIsString($arch);
        $this->assertContains($arch, ['x86_64', 'arm64']);
    }

    public function testDetectsLinuxVariant(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('Linux only test');
        }
        
        $variant = PlatformDetector::getLinuxVariant();
        $this->assertIsString($variant);
        $this->assertContains($variant, ['glibc', 'musl']);
    }

    public function testGeneratesBinaryName(): void
    {
        $name = PlatformDetector::getBinaryName('linux', 'glibc', 'x86_64');
        $this->assertEquals('libscanme_qr-linux-glibc-x86_64.so', $name);
        
        $name = PlatformDetector::getBinaryName('macos', null, 'arm64');
        $this->assertEquals('libscanme_qr-macos-arm64.dylib', $name);
        
        $name = PlatformDetector::getBinaryName('windows', null, 'x86_64');
        $this->assertEquals('scanme_qr-windows-x86_64.dll', $name);
    }
}
