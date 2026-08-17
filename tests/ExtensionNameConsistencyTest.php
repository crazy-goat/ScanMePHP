<?php

declare(strict_types=1);

use CrazyGoat\ScanMePHP\EncoderInterface;
use CrazyGoat\ScanMePHP\FfiEncoder;
use CrazyGoat\ScanMePHP\NativeEncoder;
use CrazyGoat\ScanMePHP\QRCode;
use PHPUnit\Framework\TestCase;

class ExtensionNameConsistencyTest extends TestCase
{
    private const EXTENSION_NAME = 'scanmeqr';

    public function testAllEncoderSelectionPathsUseTheSameExtensionName(): void
    {
        $expected = sprintf("extension_loaded('%s')", self::EXTENSION_NAME);
        $wrong = "extension_loaded('scanme_qr')";
        $files = [
            'src/QRCode.php',
            'src/NativeEncoder.php',
            'src/Composer/Plugin.php',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents(dirname(__DIR__) . '/' . $file);

            $this->assertStringContainsString(
                $expected,
                $source,
                sprintf('%s must reference the same extension name as the other encoder selection paths', $file)
            );
            $this->assertStringNotContainsString(
                $wrong,
                $source,
                sprintf('%s must not reference the misspelled extension name', $file)
            );
        }
    }

    /**
     * R1-1 guard: FFI library path resolution must be a single source of truth.
     * Both QRCode::createDefaultEncoder() and NativeEncoder's no-extension
     * fallback must route through FfiEncoder::resolveLibraryPath() rather than
     * duplicating the candidate-path logic, so the two can never silently
     * diverge. Pinned by source-string contract.
     */
    public function testFfiLibraryPathResolutionIsCentralized(): void
    {
        $callSites = [
            'src/QRCode.php',
            'src/NativeEncoder.php',
        ];

        foreach ($callSites as $file) {
            $source = (string) file_get_contents(dirname(__DIR__) . '/' . $file);

            $this->assertStringContainsString(
                'FfiEncoder::resolveLibraryPath()',
                $source,
                sprintf('%s must resolve the FFI library path via FfiEncoder::resolveLibraryPath()', $file)
            );
            $this->assertStringNotContainsString(
                "'/clib/build/libscanme_qr.so'",
                $source,
                sprintf('%s must not duplicate the local-build path literal (use FfiEncoder::resolveLibraryPath())', $file)
            );
        }
    }

    public function testResolveLibraryPathReturnsUsableOrNull(): void
    {
        $path = FfiEncoder::resolveLibraryPath();

        $this->assertTrue(
            $path === null || FfiEncoder::isAvailable($path),
            'FfiEncoder::resolveLibraryPath() must return null or a path that isAvailable() confirms'
        );
    }

    // NOTE: this is a future-guard, NOT the #39 regression test. It passes both
    // before and after the fix (the misspelled 'scanme_qr' also evaluated to
    // false). The actual regression guard for #39 is
    // testAllEncoderSelectionPathsUseTheSameExtensionName above.
    public function testDefaultEncoderIsNotNativeEncoderWhenExtensionIsMissing(): void
    {
        if (extension_loaded(self::EXTENSION_NAME)) {
            $this->markTestSkipped('scanmeqr extension loaded; NativeEncoder is the expected default');
        }

        $method = new ReflectionMethod(QRCode::class, 'createDefaultEncoder');
        $encoder = $method->invoke(new QRCode('https://example.com'));

        $this->assertInstanceOf(EncoderInterface::class, $encoder);
        $this->assertNotInstanceOf(NativeEncoder::class, $encoder);
    }
}
