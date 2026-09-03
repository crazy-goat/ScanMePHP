<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoding\MaxiCode\HighLevelEncoder;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MaxiCode against an encoder we did not write.
 *
 * This is a stronger comparison than Aztec's or PDF417's, because MaxiCode has
 * nothing to choose. There is one size, one error correction scheme and no
 * mask, so a payload in the plain mode determines every module — nothing here
 * is pinned from the fixture to keep the comparison honest, because there is
 * nothing that could be.
 *
 * What it covers is therefore the whole encoder: the code set search, the
 * numeric compaction, three Reed-Solomon blocks over GF(64) with the secondary
 * message interleaved, and the placement of all 144 codewords into a hexagonal
 * lattice.
 *
 * The fixture comes from tools/maxicode_reference.py, which reads the module
 * positions out of the oracle's SVG rather than sampling a raster — the one
 * symbology here where the two are not the same thing.
 */
class MaxiCodeReferenceTest extends TestCase
{
    /**
     * Payloads where the two encoders write different bits.
     *
     * One, and it is a tie: "Order 12345, shipped 2026-01-15" comes to
     * thirty-five codewords either way. The difference is where the space
     * before a run of digits goes. Set B carries a space and so does set A, so
     * writing it in B and latching after costs exactly what latching first and
     * writing it in A does — and this encoder defers the latch, on the rule
     * that only a character the open set cannot write may change the set.
     *
     * The oracle defers it in one place and not the other: on
     * ":;<=>?@[]^_`{|}~" it writes the colon in A before latching, and here it
     * latches before the space. Neither is wrong and there is no rule that
     * produces both, so this encoder follows the one it can state.
     *
     * testEveryListedDivergenceIsReal keeps the list from going stale.
     *
     * @var list<string>
     */
    private const ENCODERS_DISAGREE = [
        '4f726465722031323334352c207368697070656420323032362d30312d3135',
    ];

    /** @return \Generator<string, array{string, int, string}> */
    public static function referenceProvider(): \Generator
    {
        $handle = fopen(__DIR__ . '/fixtures/maxicode_reference.csv', 'r');
        if ($handle === false) {
            return;
        }

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            [$payloadHex, $mode, $modules] = $row;
            $payload = hex2bin($payloadHex);
            self::assertIsString($payload);

            yield $payloadHex => [$payload, (int) $mode, $modules];
        }

        fclose($handle);
    }

    #[DataProvider('referenceProvider')]
    public function testTheModulesMatchAnIndependentEncoder(string $payload, int $mode, string $modules): void
    {
        if (\in_array(bin2hex($payload), self::ENCODERS_DISAGREE, true)) {
            $this->markTestSkipped('the two encoders write different bits for this payload; see ENCODERS_DISAGREE');
        }

        $symbol = Scanme::create()->generate($payload, Symbology::MaxiCode);

        $this->assertSame($mode, $symbol->getMetadata()['mode'], 'mode for ' . bin2hex($payload));
        $this->assertSame($modules, $symbol->toModuleString(), 'modules for ' . bin2hex($payload));
    }

    /**
     * The claim that survives a tie: our encoding is never the longer one.
     *
     * The fixture stores modules rather than a codeword count, so the oracle's
     * count is read back out of the symbol — the data codewords are exactly the
     * ones our own placement says they are, which is sound here because the
     * placement is what the module comparison above is checking.
     */
    #[DataProvider('referenceProvider')]
    public function testWeNeverNeedMoreCodewordsThanTheOracle(string $payload, int $mode, string $modules): void
    {
        $ours = \count((new HighLevelEncoder())->encode($payload)['codewords']);

        $this->assertLessThanOrEqual(
            $this->oracleCodewords($modules),
            $ours,
            sprintf('%s: the search found a longer encoding than the oracle did', bin2hex($payload))
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

            [$payload, , $modules] = $rows[$hex];
            $this->assertNotSame(
                $modules,
                Scanme::create()->generate($payload, Symbology::MaxiCode)->toModuleString(),
                "{$hex} now agrees with the oracle; remove it from ENCODERS_DISAGREE"
            );
        }
    }

    /**
     * How many data codewords the oracle's symbol carries before its padding.
     *
     * Trailing pads are stripped, which is what makes the comparison sharp:
     * both encoders always fill the symbol, so comparing the padded length
     * would compare nothing at all.
     */
    private function oracleCodewords(string $modules): int
    {
        $data = MaxiCodePlacementTest::dataCodewords($modules);

        // 33 pads code sets A and B, 28 pads E, and C and D have no pad at all
        // — a stream ending in one latches to A first. That latch is left in
        // place because 58 is also a colon, which makes the bound weaker by one
        // codeword in that case and never wrong.
        while ($data !== [] && \in_array(end($data), [33, 28], true)) {
            array_pop($data);
        }

        return \count($data);
    }
}
