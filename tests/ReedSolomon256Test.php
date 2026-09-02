<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoding\ReedSolomon256;
use PHPUnit\Framework\TestCase;

/**
 * The GF(2^8) Reed–Solomon encoder shared by the byte-codeword symbologies.
 *
 * Two parameters distinguish the standards, and getting either wrong still
 * yields plausible codewords, so the configurations are anchored on published
 * vectors rather than on this implementation agreeing with itself.
 */
class ReedSolomon256Test extends TestCase
{
    /**
     * ISO/IEC 16022, the standard's own worked example: "123456" in a 10×10
     * symbol encodes to data codewords 142, 164, 186 and five error
     * correction codewords 114, 25, 5, 88, 102.
     */
    public function testDataMatrixMatchesThePublishedEcc200Vector(): void
    {
        $this->assertSame(
            [114, 25, 5, 88, 102],
            ReedSolomon256::forDataMatrix()->encode([142, 164, 186], 5)
        );
    }

    /**
     * The same input under the three neighbouring configurations, to show the
     * vector above actually discriminates between them — a test that passed
     * for any of these would be worthless.
     */
    public function testTheVectorDistinguishesPolynomialAndGeneratorBase(): void
    {
        $data = [142, 164, 186];
        $correct = [114, 25, 5, 88, 102];

        $variants = [
            'Data Matrix polynomial, QR generator base' => new ReedSolomon256(
                ReedSolomon256::DATA_MATRIX_PRIMITIVE,
                ReedSolomon256::QR_GENERATOR_BASE
            ),
            'QR polynomial, Data Matrix generator base' => new ReedSolomon256(
                ReedSolomon256::QR_PRIMITIVE,
                ReedSolomon256::DATA_MATRIX_GENERATOR_BASE
            ),
            'QR on both counts' => ReedSolomon256::forQr(),
        ];

        foreach ($variants as $label => $variant) {
            $ecc = $variant->encode($data, 5);

            $this->assertNotSame($correct, $ecc, $label);
            $this->assertCount(5, $ecc, $label);
            foreach ($ecc as $codeword) {
                $this->assertGreaterThanOrEqual(0, $codeword, $label);
                $this->assertLessThanOrEqual(255, $codeword, $label);
            }
        }
    }

    /**
     * Both field constants must be primitive polynomials: α has to generate
     * every non-zero element of GF(256) exactly once and return to 1 after 255
     * steps. A non-primitive polynomial would produce a shorter cycle and
     * silently break the code's distance guarantee.
     *
     * The arithmetic is redone here rather than read out of the encoder, so
     * this checks the constants themselves.
     */
    public function testBothFieldConstantsArePrimitive(): void
    {
        foreach ([
            'QR' => ReedSolomon256::QR_PRIMITIVE,
            'Data Matrix' => ReedSolomon256::DATA_MATRIX_PRIMITIVE,
        ] as $label => $primitive) {
            $seen = [];
            $x = 1;
            for ($step = 0; $step < 255; $step++) {
                $this->assertArrayNotHasKey($x, $seen, "$label: alpha^$step repeats an earlier element");
                $seen[$x] = true;
                $x <<= 1;
                if (($x & 0x100) !== 0) {
                    $x ^= $primitive;
                }
            }

            $this->assertCount(255, $seen, "$label: alpha must reach every non-zero element");
            $this->assertSame(1, $x, "$label: alpha must have order exactly 255");
        }
    }

    /** @return iterable<string, array{ReedSolomon256}> */
    public static function fieldProvider(): iterable
    {
        yield 'qr' => [ReedSolomon256::forQr()];
        yield 'data matrix' => [ReedSolomon256::forDataMatrix()];
    }

    /** @dataProvider fieldProvider */
    public function testEccIsTheRequestedLengthAndWithinTheField(ReedSolomon256 $field): void
    {
        mt_srand(20260902);

        foreach ([1, 2, 5, 10, 24, 30, 62, 68] as $eccCount) {
            $data = [];
            for ($i = 0; $i < 40; $i++) {
                $data[] = mt_rand(0, 255);
            }

            $ecc = $field->encode($data, $eccCount);
            $this->assertCount($eccCount, $ecc);
            foreach ($ecc as $codeword) {
                $this->assertGreaterThanOrEqual(0, $codeword);
                $this->assertLessThanOrEqual(255, $codeword);
            }
        }
    }

    /** @dataProvider fieldProvider */
    public function testAllZeroDataHasAllZeroEcc(ReedSolomon256 $field): void
    {
        // The remainder of the zero polynomial is zero in any field, so this
        // catches a table built with a stray offset.
        $this->assertSame(array_fill(0, 10, 0), $field->encode(array_fill(0, 20, 0), 10));
    }

    /** @dataProvider fieldProvider */
    public function testEncodingIsDeterministicAndTablesAreReusable(ReedSolomon256 $field): void
    {
        $data = [1, 2, 3, 250, 251, 0, 255];

        $first = $field->encode($data, 12);
        // The second call hits the cached factor table, which must not have
        // been mutated by the first.
        $this->assertSame($first, $field->encode($data, 12));
        $this->assertNotSame($first, $field->encode($data, 13), 'a different ECC count is a different code');
    }

    /** @dataProvider fieldProvider */
    public function testASingleChangedDataByteChangesTheEcc(ReedSolomon256 $field): void
    {
        $data = array_fill(0, 16, 7);
        $reference = $field->encode($data, 10);

        for ($position = 0; $position < 16; $position++) {
            $altered = $data;
            $altered[$position] ^= 0xff;

            $this->assertNotSame($reference, $field->encode($altered, 10), "position $position");
        }
    }

    public function testTheTwoStandardsDisagreeOnEveryNonTrivialInput(): void
    {
        $qr = ReedSolomon256::forQr();
        $dataMatrix = ReedSolomon256::forDataMatrix();

        // Different field and different generator base, so a symbology wired to
        // the wrong one cannot go unnoticed.
        foreach ([[1], [142, 164, 186], [255, 0, 128, 64]] as $data) {
            $this->assertNotSame(
                $qr->encode($data, 5),
                $dataMatrix->encode($data, 5),
                implode(',', $data)
            );
        }
    }
}
