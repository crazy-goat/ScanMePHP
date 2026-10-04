<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Exception\InvalidDataException;
use CrazyGoat\ScanMePHP\FastEncoder;
use CrazyGoat\ScanMePHP\Matrix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Emptiness check in Encoder::encode() (#75).
 *
 * `empty()` is true for the string "0" in PHP, so the one-character payload "0"
 * was rejected as empty data while FastEncoder encoded it. The encoder backends
 * must agree on which payloads are empty, so they are compared here.
 */
class EncoderTest extends TestCase
{
    public static function zeroPayloadProvider(): \Generator
    {
        yield 'single "0"' => ['0'];
        yield 'two zeros' => ['00'];
        yield '"0" repeated 10 times' => [str_repeat('0', 10)];
    }

    #[DataProvider('zeroPayloadProvider')]
    public function testEncodeAcceptsZeroOnlyPayload(string $data): void
    {
        $matrix = (new Encoder())->encode($data, ErrorCorrectionLevel::Low);

        $this->assertInstanceOf(Matrix::class, $matrix);
        $this->assertGreaterThanOrEqual(1, $matrix->getVersion());
        $this->assertCount($matrix->getSize(), $matrix->getData());
    }

    #[DataProvider('zeroPayloadProvider')]
    public function testEncodeOfZeroOnlyPayloadMatchesFastEncoder(string $data): void
    {
        $matrix = (new Encoder())->encode($data, ErrorCorrectionLevel::Low);
        $fastMatrix = (new FastEncoder())->encode($data, ErrorCorrectionLevel::Low);

        $this->assertSame(
            $fastMatrix->getSize(),
            $matrix->getSize(),
            sprintf('Size mismatch for payload %s', var_export($data, true))
        );
        $this->assertSame(
            $fastMatrix->getData(),
            $matrix->getData(),
            sprintf('Matrix mismatch for payload %s', var_export($data, true))
        );
    }

    public function testEncodeStillRejectsEmptyString(): void
    {
        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('Data cannot be empty');

        (new Encoder())->encode('', ErrorCorrectionLevel::Low);
    }
}
