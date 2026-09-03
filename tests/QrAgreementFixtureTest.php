<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The frozen file the C++ tests compare against is still the encoder's output.
 *
 * QrBackendAgreementTest can hold the PHP backends against the verified
 * encoder in memory. The C++ core is tested from C++, where it cannot call
 * that encoder, so it compares against tests/fixtures/qr_agreement.csv — our
 * own output, frozen, and deliberately not a reference fixture.
 *
 * A frozen copy of something is only useful while it is still a copy. Without
 * this, an encoder change would leave the C++ suite comparing against last
 * month's symbols and reporting a pass, which is a worse failure than a red
 * build: the two halves of the library would have diverged and nothing would
 * say so.
 */
final class QrAgreementFixtureTest extends TestCase
{
    /** @return \Generator<string, array{string, string, int, int, string}> */
    public static function agreementProvider(): \Generator
    {
        $handle = fopen(__DIR__ . '/fixtures/qr_agreement.csv', 'r');
        self::assertIsResource($handle);

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (\count($row) < 5) {
                continue;
            }

            yield sprintf('%s ECL=%s', substr((string) $row[0], 0, 60), $row[1]) => [
                (string) $row[0],
                (string) $row[1],
                (int) $row[2],
                (int) $row[3],
                (string) $row[4],
            ];
        }

        fclose($handle);
    }

    #[DataProvider('agreementProvider')]
    public function testTheFrozenFileIsStillWhatTheEncoderProduces(
        string $url,
        string $ecl,
        int $version,
        int $size,
        string $bits
    ): void {
        $matrix = (new Encoder())->encode($url, match ($ecl) {
            'L' => ErrorCorrectionLevel::Low,
            'M' => ErrorCorrectionLevel::Medium,
            'Q' => ErrorCorrectionLevel::Quartile,
            'H' => ErrorCorrectionLevel::High,
            default => throw new \InvalidArgumentException('Unknown error correction level: ' . $ecl),
        });

        $this->assertSame($version, $matrix->getVersion());
        $this->assertSame($size, $matrix->getSize());
        $this->assertSame(
            $bits,
            $matrix->toModuleString(),
            'Regenerate with: php tools/qr_agreement_fixture.php'
        );
    }

    public function testItCoversTheSamePayloadsAsTheReferenceFixture(): void
    {
        $this->assertSame(
            iterator_count(QrReferenceTest::csvFixtureProvider()),
            iterator_count(self::agreementProvider()),
            'A payload verified against qrcodegen but not carried to the C++ core is a gap'
        );
    }
}
