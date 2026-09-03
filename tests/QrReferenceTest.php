<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * QR against Nayuki's qrcodegen, module for module.
 *
 * 443 URLs at all four error correction levels. The mask comes out of the
 * fixture rather than being asserted, for the reason tools/qr_reference.py
 * sets out: it is the one step where conforming encoders legitimately
 * disagree. Held fixed, the comparison covers everything the encoder actually
 * decides — the version, the mode and character count, the codewords, the
 * error correction, the block interleaving and the placement.
 *
 * Two things this deliberately does not answer, both covered elsewhere rather
 * than left silent: whether our automatic mask choice is sane
 * (QrMaskOptionTest, plus a decoder round trip at all eight), and whether the
 * other three backends agree with this one (QrBackendAgreementTest, which is
 * what carries this oracle through to the C++ core).
 */
final class QrReferenceTest extends TestCase
{
    /** @return \Generator<string, array{string, string, int, int, int, string}> */
    public static function csvFixtureProvider(): \Generator
    {
        $handle = fopen(__DIR__ . '/fixtures/qr_reference.csv', 'r');
        self::assertIsResource($handle);

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (\count($row) < 6) {
                continue;
            }

            yield sprintf('%s ECL=%s', substr((string) $row[0], 0, 60), $row[1]) => [
                (string) $row[0],
                (string) $row[1],
                (int) $row[2],
                (int) $row[3],
                (int) $row[4],
                (string) $row[5],
            ];
        }

        fclose($handle);
    }

    #[DataProvider('csvFixtureProvider')]
    public function testTheModulesMatchAnIndependentEncoder(
        string $url,
        string $ecl,
        int $version,
        int $size,
        int $mask,
        string $expectedBits
    ): void {
        $encoder = new Encoder();
        $level = $this->level($ecl);

        $this->assertSame(
            $version,
            $encoder->getMinimumVersion($url, $level),
            'The two encoders disagree about the smallest symbol that fits'
        );

        $matrix = $encoder->encodeAtMask($url, $level, $version, $mask);

        $this->assertSame($size, $matrix->getSize());
        $this->assertSame($expectedBits, $matrix->toModuleString());
    }

    /**
     * The symbol we actually ship is the same one, at our own mask.
     *
     * A mask changes which modules are dark but not which version, so this is
     * what says the fixture is checking the symbol callers get rather than a
     * shape only the test can produce.
     */
    #[DataProvider('csvFixtureProvider')]
    public function testTheSymbolWeShipHasTheSameVersion(
        string $url,
        string $ecl,
        int $version,
        int $size,
        int $mask,
        string $expectedBits
    ): void {
        $matrix = (new Encoder())->encode($url, $this->level($ecl));

        $this->assertSame($version, $matrix->getVersion());
        $this->assertSame($size, $matrix->getSize());
    }

    private function level(string $ecl): ErrorCorrectionLevel
    {
        return match ($ecl) {
            'L' => ErrorCorrectionLevel::Low,
            'M' => ErrorCorrectionLevel::Medium,
            'Q' => ErrorCorrectionLevel::Quartile,
            'H' => ErrorCorrectionLevel::High,
            default => throw new \InvalidArgumentException('Unknown error correction level: ' . $ecl),
        };
    }
}
