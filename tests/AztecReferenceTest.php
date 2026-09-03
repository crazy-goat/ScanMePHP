<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoding\Aztec\Specs;
use CrazyGoat\ScanMePHP\Generator\Aztec\AztecOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Aztec against an encoder we did not write.
 *
 * The fixture comes from zxing-cpp (tools/aztec_reference.py) and the symbol
 * size is pinned from it rather than compared. That is deliberate and it is the
 * same decision GS1 Data Matrix already made: which symbol to reach for is a
 * policy, not a fact about the encoding. Aztec's error correction is a floor,
 * every symbol large enough to hold the data satisfies it, and the two encoders
 * apply the recommended margin differently — ten independent boundaries
 * measured off zxing's own output fit no formula of the shape "a percentage of
 * the data plus a constant", which is what settled the question. Pinning the
 * size would test that policy; leaving it free would test nothing at all.
 *
 * What the comparison then covers is everything downstream of the size: the
 * mode-switching decisions, the bit stuffing, Reed–Solomon in four different
 * Galois fields, the mode message, and the spiral.
 */
class AztecReferenceTest extends TestCase
{
    /**
     * Payloads where the two encoders write different bits.
     *
     * Aztec's five modes overlap enough for two encodings of one payload to be
     * exactly the same length, and then neither is wrong. "HELLOxWORLD" is the
     * clean example: from Upper there is no shift into Lower, so a single
     * lower-case letter costs either a latch and the nine bits it takes to get
     * back, or an eighteen-bit binary shift for the one byte. Both come to
     * twelve data words. On "https://example.com/a" the disagreement is not a
     * tie at all — this encoder needs twenty-three data words where zxing needs
     * twenty-four.
     *
     * testEveryListedDivergenceIsRealKeepsThisHonest: an entry that starts
     * agreeing has to be removed, so the list cannot quietly grow stale.
     */
    private const ENCODERS_DISAGREE = [
        '48454c4c4f78574f524c44',
        '68747470733a2f2f6578616d706c652e636f6d2f61',
        '68747470733a2f2f6578616d706c652e636f6d2f70726f64756374732f31323334353f7265663d7172',
    ];

    /** @return \Generator<string, array{string, int, int, string, int, string}> */
    public static function referenceProvider(): \Generator
    {
        $handle = fopen(__DIR__ . '/fixtures/aztec_reference.csv', 'r');
        if ($handle === false) {
            return;
        }

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            [$payloadHex, $size, $layers, $kind, $dataWords, , $modules] = $row;
            $payload = hex2bin($payloadHex);
            self::assertIsString($payload);

            yield $payloadHex => [$payload, (int) $size, (int) $layers, $kind, (int) $dataWords, $modules];
        }

        fclose($handle);
    }

    #[DataProvider('referenceProvider')]
    public function testTheModulesMatchAnIndependentEncoder(
        string $payload,
        int $size,
        int $layers,
        string $kind,
        int $dataWords,
        string $modules
    ): void {
        if (\in_array(bin2hex($payload), self::ENCODERS_DISAGREE, true)) {
            $this->markTestSkipped('the two encoders write different bits for this payload; see ENCODERS_DISAGREE');
        }

        $symbol = Scanme::create()->generate($payload, Symbology::Aztec, new AztecOptions(size: $size));

        $this->assertSame($size, $symbol->getWidth(), 'size for ' . bin2hex($payload));
        $this->assertSame($layers, $symbol->getMetadata()['layers'], 'layers for ' . bin2hex($payload));
        $this->assertSame($kind === 'compact', $symbol->getMetadata()['compact'], 'kind for ' . bin2hex($payload));
        $this->assertSame($modules, $symbol->toModuleString(), 'modules for ' . bin2hex($payload));
    }

    /**
     * The claim that survives a tie: our encoding is never the longer one.
     *
     * This holds for every row, the three disagreements included, and it is
     * what would catch the mode-switching search going wrong on a payload where
     * the modules happen to agree today.
     */
    #[DataProvider('referenceProvider')]
    public function testWeNeverNeedMoreDataWordsThanTheOracle(
        string $payload,
        int $size,
        int $layers,
        string $kind,
        int $dataWords
    ): void {
        $symbol = Scanme::create()->generate($payload, Symbology::Aztec, new AztecOptions(size: $size));

        $this->assertLessThanOrEqual(
            $dataWords,
            $symbol->getMetadata()['dataWords'],
            sprintf('%s: the search found a longer encoding than zxing-cpp did', bin2hex($payload))
        );
    }

    public function testEveryListedDivergenceIsReal(): void
    {
        $rows = [];
        foreach (self::referenceProvider() as $hex => $row) {
            $rows[$hex] = $row;
        }

        foreach (self::ENCODERS_DISAGREE as $hex) {
            $this->assertArrayHasKey($hex, $rows, "{$hex} is listed as divergent but is not in the fixture");

            [$payload, $size, , , , $modules] = $rows[$hex];
            $symbol = Scanme::create()->generate($payload, Symbology::Aztec, new AztecOptions(size: $size));

            $this->assertNotSame(
                $modules,
                $symbol->toModuleString(),
                "{$hex} now agrees with zxing-cpp; remove it from ENCODERS_DISAGREE"
            );
        }
    }

    /**
     * The thirty-six sizes, against the same encoder.
     *
     * A full symbol's size has no closed form — the reference grid adds two
     * modules per line and how many lines there are depends on the size — so
     * Specs solves it as a fixed point. These are the sizes zxing-cpp produces,
     * swept over every layer count it will emit.
     */
    public function testEverySymbolSizeMatchesTheOracle(): void
    {
        $oracle = [
            'compact' => [1 => 15, 2 => 19, 3 => 23, 4 => 27],
            'full' => [
                4 => 31, 5 => 37, 6 => 41, 7 => 45, 8 => 49, 9 => 53, 10 => 57, 11 => 61,
                12 => 67, 13 => 71, 14 => 75, 15 => 79, 16 => 83, 17 => 87, 18 => 91, 19 => 95,
                20 => 101, 21 => 105, 22 => 109, 23 => 113, 24 => 117, 25 => 121, 26 => 125,
                27 => 131, 28 => 135, 29 => 139, 30 => 143, 31 => 147, 32 => 151,
            ],
        ];

        foreach ($oracle as $kind => $sizes) {
            foreach ($sizes as $layers => $size) {
                $this->assertSame($size, Specs::size($layers, $kind === 'compact'), "{$kind} {$layers} layers");
            }
        }

        $this->assertSame(
            array_values(array_merge($oracle['compact'], $oracle['full'])),
            Specs::sizes(),
            'Specs::sizes() must list every size and nothing else'
        );
    }
}
