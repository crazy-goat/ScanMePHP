<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\FfiEncoder;
use CrazyGoat\ScanMePHP\Matrix;
use PHPUnit\Framework\TestCase;

class FfiEncoderTest extends TestCase
{
    private static string $libraryPath;

    public static function setUpBeforeClass(): void
    {
        self::$libraryPath = FfiEncoder::localBuildPath();
    }

    protected function setUp(): void
    {
        if (!FfiEncoder::isAvailable(self::$libraryPath)) {
            $this->markTestSkipped(
                'ext-ffi not available or the native library is not built. ' .
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

    public function testEncodeMatchesFastEncoder(): void
    {
        $fastEncoder = new \CrazyGoat\ScanMePHP\FastEncoder();
        $ffiEncoder = new FfiEncoder(self::$libraryPath);

        $testCases = [
            ['https://example.com', ErrorCorrectionLevel::Medium],
            ['https://example.com', ErrorCorrectionLevel::Low],
            ['https://example.com', ErrorCorrectionLevel::High],
            ['https://example.com', ErrorCorrectionLevel::Quartile],
            ['https://scanmephp.example.com/very/long/url/path?query=value&other=123', ErrorCorrectionLevel::Medium],
            ['A', ErrorCorrectionLevel::Low],
            [str_repeat('X', 100), ErrorCorrectionLevel::Medium],
        ];

        foreach ($testCases as [$data, $ecl]) {
            $fastMatrix = $fastEncoder->encode($data, $ecl);
            $ffiMatrix = $ffiEncoder->encode($data, $ecl);

            $this->assertSame(
                $fastMatrix->getSize(),
                $ffiMatrix->getSize(),
                "Size mismatch for '$data' ECL={$ecl->name}"
            );
            $this->assertSame(
                $fastMatrix->getData(),
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

    /**
     * Regression guard: FfiEncoder used to call FFI::cdef() once per instance.
     *
     * PHP frees a cdef's type table together with its FFI object, so a process
     * that created and dropped many bindings to the same library could end up
     * reading a cdata field through a freed type and die with SIGBUS
     * (EXC_BAD_ACCESS in zend_ffi_cdata_read_field). It surfaced once the
     * facade was built per test, but any worker generating symbols in a
     * long-lived process would have hit it.
     *
     * Nothing can assert "did not crash" from inside the crashing process —
     * completing this loop is the assertion. The identity check pins the cause.
     */
    public function testTheFfiBindingIsSharedSoDroppedInstancesCannotDangle(): void
    {
        $binding = static function (FfiEncoder $encoder): object {
            $property = new \ReflectionProperty(FfiEncoder::class, 'ffi');

            return $property->getValue($encoder);
        };

        $first = new FfiEncoder(self::$libraryPath);
        $this->assertSame(
            $binding($first),
            $binding(new FfiEncoder(self::$libraryPath)),
            'two encoders for one library must share the binding'
        );

        for ($i = 0; $i < 60; $i++) {
            $encoder = new FfiEncoder(self::$libraryPath);
            $matrix = $encoder->encode('https://example.com/' . $i, ErrorCorrectionLevel::Medium);
            $this->assertSame(25, $matrix->getSize());
            unset($encoder, $matrix);
        }

        // Still usable after 60 instances have come and gone.
        $this->assertSame(
            25,
            $first->encode('https://example.com', ErrorCorrectionLevel::Medium)->getSize()
        );
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
