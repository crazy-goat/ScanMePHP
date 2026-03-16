<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\FastEncoder;
use CrazyGoat\ScanMePHP\FfiEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QrReferenceTest extends TestCase
{
    private static function eclFromString(string $ecl): ErrorCorrectionLevel
    {
        return match ($ecl) {
            'L' => ErrorCorrectionLevel::Low,
            'M' => ErrorCorrectionLevel::Medium,
            'Q' => ErrorCorrectionLevel::Quartile,
            'H' => ErrorCorrectionLevel::High,
        };
    }

    private static function matrixToBits(array $data, int $size): string
    {
        $bits = '';
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $bits .= $data[$y][$x] ? '1' : '0';
            }
        }
        return $bits;
    }

    public static function csvFixtureProvider(): \Generator
    {
        $csv = __DIR__ . '/fixtures/qr_reference.csv';
        if (!file_exists($csv)) {
            return;
        }

        $handle = fopen($csv, 'r');
        fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 5) {
                continue;
            }
            $label = sprintf('%s ECL=%s', substr($row[0], 0, 60), $row[1]);
            yield $label => [$row[0], $row[1], (int)$row[2], (int)$row[3], $row[4]];
        }
        fclose($handle);
    }

    #[DataProvider('csvFixtureProvider')]
    public function testFastEncoderMatchesReference(string $url, string $ecl, int $version, int $size, string $expectedBits): void
    {
        $encoder = new FastEncoder();
        $matrix = $encoder->encode($url, self::eclFromString($ecl));

        $this->assertSame($size, $matrix->getSize(), 'Size mismatch');
        $bits = self::matrixToBits($matrix->getData(), $size);
        $this->assertSame($expectedBits, $bits);
    }

    #[DataProvider('csvFixtureProvider')]
    public function testFfiEncoderMatchesReference(string $url, string $ecl, int $version, int $size, string $expectedBits): void
    {
        $libPath = dirname(__DIR__) . '/libscanme_qr.so';
        if (!file_exists($libPath)) {
            $this->markTestSkipped('libscanme_qr.so not found');
        }

        $encoder = new FfiEncoder($libPath);
        $matrix = $encoder->encode($url, self::eclFromString($ecl));

        $this->assertSame($size, $matrix->getSize(), 'Size mismatch');
        $bits = self::matrixToBits($matrix->getData(), $size);
        $this->assertSame($expectedBits, $bits);
    }
}
