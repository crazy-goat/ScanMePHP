<?php
declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\FfiEncoder;
use CrazyGoat\ScanMePHP\Matrix;
use PHPUnit\Framework\TestCase;

class FfiEncoderTest extends TestCase
{
    private static string $libraryPath;

    public static function setUpBeforeClass(): void
    {
        self::$libraryPath = dirname(__DIR__) . '/clib/build/libscanme_qr.so';
    }

    protected function setUp(): void
    {
        if (!FfiEncoder::isAvailable(self::$libraryPath)) {
            $this->markTestSkipped(
                'ext-ffi not available or libscanme_qr.so not built. ' .
                'Run: cmake -S clib -B clib/build && cmake --build clib/build'
            );
        }
    }

    public function testEncodeReturnsMatrix(): void
    {
        $encoder = new FfiEncoder(self::$libraryPath);
        $matrix = $encoder->encode('https://example.com', ErrorCorrectionLevel::Medium);

        $this->assertInstanceOf(Matrix::class, $matrix);
        $this->assertGreaterThan(0, $matrix->getSize());
        $this->assertGreaterThanOrEqual(1, $matrix->getVersion());
        $this->assertLessThanOrEqual(40, $matrix->getVersion());
    }

    public function testMatrixSizeMatchesVersion(): void
    {
        $encoder = new FfiEncoder(self::$libraryPath);
        $matrix = $encoder->encode('https://example.com', ErrorCorrectionLevel::Medium);

        $expectedSize = 17 + 4 * $matrix->getVersion();
        $this->assertSame($expectedSize, $matrix->getSize());
    }

    public function testModulesAreBooleans(): void
    {
        $encoder = new FfiEncoder(self::$libraryPath);
        $matrix = $encoder->encode('https://example.com', ErrorCorrectionLevel::Medium);
        $data = $matrix->getData();

        foreach ($data as $row) {
            foreach ($row as $module) {
                $this->assertIsBool($module);
            }
        }
    }

    public function testEncodeMatchesPhpEncoder(): void
    {
        $phpEncoder = new Encoder();
        $ffiEncoder = new FfiEncoder(self::$libraryPath);

        $testCases = [
            ['https://example.com', ErrorCorrectionLevel::Medium],
            ['https://example.com', ErrorCorrectionLevel::Low],
            ['https://example.com', ErrorCorrectionLevel::High],
            ['https://scanmephp.example.com/very/long/url/path?query=value&other=123', ErrorCorrectionLevel::Medium],
        ];

        foreach ($testCases as [$data, $ecl]) {
            $phpMatrix = $phpEncoder->encode($data, $ecl);
            $ffiMatrix = $ffiEncoder->encode($data, $ecl);

            $this->assertSame(
                $phpMatrix->getSize(),
                $ffiMatrix->getSize(),
                "Size mismatch for '$data' ECL={$ecl->name}"
            );
            $this->assertSame(
                $phpMatrix->getData(),
                $ffiMatrix->getData(),
                "Matrix data mismatch for '$data' ECL={$ecl->name}"
            );
        }
    }

    public function testEncodeEmptyThrows(): void
    {
        $encoder = new FfiEncoder(self::$libraryPath);
        $this->expectException(\Exception::class);
        $encoder->encode('', ErrorCorrectionLevel::Medium);
    }

    public function testLibraryNotFoundThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');
        new FfiEncoder('/nonexistent/libscanme_qr.so');
    }

    public function testAllErrorCorrectionLevels(): void
    {
        $encoder = new FfiEncoder(self::$libraryPath);
        $data = 'https://example.com';

        foreach (ErrorCorrectionLevel::cases() as $ecl) {
            $matrix = $encoder->encode($data, $ecl);
            $this->assertInstanceOf(Matrix::class, $matrix);
            $this->assertGreaterThan(0, $matrix->getSize());
        }
    }

    public function testMaxVersionV40(): void
    {
        $encoder = new FfiEncoder(self::$libraryPath);
        $data = str_repeat('A', 2953);
        $matrix = $encoder->encode($data, ErrorCorrectionLevel::Low);

        $this->assertSame(40, $matrix->getVersion());
        $this->assertSame(177, $matrix->getSize());
        $this->assertCount(177, $matrix->getData());
        $this->assertCount(177, $matrix->getData()[0]);
    }

    public function testDeterministic(): void
    {
        $encoder = new FfiEncoder(self::$libraryPath);
        $data = 'https://example.com';
        $ecl = ErrorCorrectionLevel::Medium;

        $m1 = $encoder->encode($data, $ecl);
        $m2 = $encoder->encode($data, $ecl);

        $this->assertSame($m1->getData(), $m2->getData());
    }

    public function testLibraryVersion(): void
    {
        $encoder = new FfiEncoder(self::$libraryPath);
        $version = $encoder->getLibraryVersion();

        $this->assertIsString($version);
        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
    }
}
