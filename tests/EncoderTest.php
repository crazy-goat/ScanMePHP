<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Exception\InvalidDataException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Emptiness check in Encoder::encode() (#75).
 *
 * `empty()` is true for the string "0" in PHP, so the one-character payload "0"
 * was rejected as empty data. The check must accept every non-empty payload.
 *
 * The encoded matrices themselves are pinned by the "0", "00" and "0"-repeated rows
 * in tests/fixtures/qr_reference.csv, which tests/QrReferenceTest.php compares
 * against Encoder, FastEncoder and FfiEncoder — and clib/tests/test_csv_fixtures.cpp
 * compares the same rows against the C++ core. That is where the module bits for
 * these payloads are asserted; comparing two PHP encoders against each other here
 * would prove nothing, because Encoder delegates to FastEncoder for versions <= 27.
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
        // "0" is a one-byte payload, so v1 at ECL L is enough for all three cases.
        $matrix = (new Encoder())->encode($data, ErrorCorrectionLevel::Low);

        $this->assertSame(1, $matrix->getVersion());
        $this->assertSame(21, $matrix->getSize());
    }

    public function testEncodeStillRejectsEmptyString(): void
    {
        $this->expectException(InvalidDataException::class);
        $this->expectExceptionMessage('Data cannot be empty');

        (new Encoder())->encode('', ErrorCorrectionLevel::Low);
    }
}
