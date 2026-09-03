<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GS1 QR against Nayuki's qrcodegen, module for module.
 *
 * The mask comes out of the fixture rather than being asserted, for the reason
 * tools/gs1_qr_reference.py sets out: it is the one step where conforming
 * encoders legitimately disagree. Held fixed, the comparison still covers the
 * version, the FNC1 indicator, the codewords, the error correction, the
 * interleaving and the placement. Gs1QrTest declares that boundary.
 *
 * Holding it fixed is done with the same QrOptions a caller has, which is what
 * makes reproducing another system's symbols an ordinary thing to ask for
 * rather than something only this test can reach.
 */
final class Gs1QrReferenceTest extends TestCase
{
    /** @return \Generator<string, array{string, string, int, int, int, string}> */
    public static function referenceProvider(): \Generator
    {
        $handle = fopen(__DIR__ . '/fixtures/gs1_qr_reference.csv', 'r');
        self::assertIsResource($handle);

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (\count($row) < 6) {
                continue;
            }

            yield sprintf('%s ECL=%s', $row[0], $row[1]) => [
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

    #[DataProvider('referenceProvider')]
    public function testTheModulesMatchAnIndependentEncoder(
        string $elements,
        string $ecl,
        int $version,
        int $size,
        int $mask,
        string $expected
    ): void {
        $encoder = new Encoder();
        $payload = ElementString::parse($elements)->payload();
        $level = $this->level($ecl);

        $this->assertSame(
            $version,
            $encoder->getMinimumGs1Version($payload, $level),
            'The two encoders disagree about the smallest symbol that fits'
        );

        // Through the public API rather than the encoder, now that the mask is
        // an option: what the fixture pins is then the symbol a caller gets.
        $symbol = Defaults::registry()
            ->getGenerator(Symbology::Gs1Qr->value)
            ->generate($elements, new QrOptions($level, version: $version, mask: $mask));

        $this->assertSame($size, $symbol->getWidth());
        $this->assertSame($expected, $symbol->toModuleString());
    }

    /**
     * The symbol we actually ship is the same one, at our own mask.
     *
     * A mask changes which modules are dark but not which version, so this is
     * what says the fixture is checking the symbol callers get rather than a
     * shape only the test can produce.
     */
    #[DataProvider('referenceProvider')]
    public function testTheSymbolWeShipHasTheSameVersion(
        string $elements,
        string $ecl,
        int $version,
        int $size,
        int $mask,
        string $expected
    ): void {
        $matrix = (new Encoder())->encodeGs1(
            ElementString::parse($elements)->payload(),
            $this->level($ecl)
        );

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
