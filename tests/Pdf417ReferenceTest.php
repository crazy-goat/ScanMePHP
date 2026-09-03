<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Encoding\Pdf417\HighLevelEncoder;
use CrazyGoat\ScanMePHP\Encoding\Pdf417\Specs;
use CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Options;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PDF417 against an encoder we did not write.
 *
 * The fixture comes from zxing-cpp (tools/pdf417_reference.py) with the error
 * correction level and the column count pinned in the request, which is what
 * makes this a module-for-module comparison rather than a recording. A PDF417
 * symbol's shape is not implied by its contents — any grid with enough cells
 * holds the codewords and the leftovers become pad codewords — so the shape has
 * to be asked for on both sides before anything downstream of it means
 * anything. Everything downstream is then compared: the choice of compaction
 * mode and submode, the base-900 conversions, the pad codewords, Reed–Solomon
 * over GF(929), the row indicators and all three pattern clusters.
 *
 * The row count is *not* pinned, because it is not a preference — once the
 * columns and the level are fixed, the fewest rows that hold the data is
 * arithmetic, and the fixture records what this writer chose so the arithmetic
 * can be checked against it.
 */
class Pdf417ReferenceTest extends TestCase
{
    /**
     * Payloads where the two encoders write different codewords.
     *
     * Every entry is a tie rather than a defect, and PDF417 produces them
     * readily: seven characters — full stop, comma, hyphen, dollar, slash,
     * colon and asterisk — sit in both the Mixed and the Punctuation submode,
     * and reaching them from Alpha costs one slot either way. So "N.Y., NY
     * 10001" has several encodings of identical length and neither encoder is
     * choosing wrongly.
     *
     * Across 148 payloads swept while this was written, this encoder was never
     * longer and never shorter than zxing's: half came out identical and half
     * the same length by another route. testWeNeverNeedMoreDataCodewordsThanTheOracle
     * asserts that on every row, and testEveryListedDivergenceIsReal keeps this
     * list from going stale.
     */
    private const ENCODERS_DISAGREE = [
        '4d697865642031323320616e64205445585420616e64206c6f77657220343536',
        '68747470733a2f2f6578616d706c652e636f6d2f70726f64756374732f313233343f7265663d7172',
        '5348495020544f3a20313233204d61696e2053742e2c2041707420342c20537072696e676669656c6420494c203632373034',
    ];

    /** @return \Generator<string, array{string, string, int, int, string}> */
    public static function referenceProvider(): \Generator
    {
        $handle = fopen(__DIR__ . '/fixtures/pdf417_reference.csv', 'r');
        if ($handle === false) {
            return;
        }

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            [$hex, $level, $columns, $rows, $modules] = $row;

            yield sprintf('%s at level %s in %s columns', $hex, $level, $columns) => [
                $hex,
                $level,
                (int) $columns,
                (int) $rows,
                $modules,
            ];
        }

        fclose($handle);
    }

    #[DataProvider('referenceProvider')]
    public function testTheModulesMatchAnIndependentEncoder(
        string $hex,
        string $level,
        int $columns,
        int $rows,
        string $expected,
    ): void {
        if (\in_array($hex, self::ENCODERS_DISAGREE, true)) {
            $this->markTestSkipped('a tie: same length, different route — see ENCODERS_DISAGREE');
        }

        $symbol = $this->encode($hex, $level, $columns);

        $this->assertSame($rows, $symbol->getHeight(), 'row count');
        $this->assertSame($expected, $symbol->toModuleString());
    }

    /**
     * The comparison that holds even where the streams differ.
     *
     * A tie means the same number of data codewords by a different route, so
     * this passes on ties and fails on a genuinely worse encoding — which is
     * the property worth defending, since a codeword saved is sometimes a row
     * saved.
     */
    #[DataProvider('referenceProvider')]
    public function testWeNeverNeedMoreDataCodewordsThanTheOracle(
        string $hex,
        string $level,
        int $columns,
        int $rows,
        string $modules,
    ): void {
        $payload = hex2bin($hex);
        self::assertIsString($payload);

        $ours = \count((new HighLevelEncoder())->encode($payload));
        $theirs = \count($this->oracleCodewords($modules, $rows, $columns));

        $this->assertLessThanOrEqual($theirs, $ours, sprintf(
            '%s: %d data codewords here against %d there',
            $hex,
            $ours,
            $theirs,
        ));
    }

    /** @return \Generator<string, array{string}> */
    public static function divergenceProvider(): \Generator
    {
        foreach (self::ENCODERS_DISAGREE as $hex) {
            yield $hex => [$hex];
        }
    }

    /**
     * An entry that has started agreeing has to be removed from the list.
     *
     * Without this, a fixed encoder would leave a skipped test behind it and
     * the skip would look like a known limitation forever.
     */
    #[DataProvider('divergenceProvider')]
    public function testEveryListedDivergenceIsReal(string $hex): void
    {
        foreach (self::referenceProvider() as [$rowHex, $level, $columns, $rows, $expected]) {
            if ($rowHex !== $hex) {
                continue;
            }

            $this->assertNotSame(
                $expected,
                $this->encode($rowHex, $level, $columns)->toModuleString(),
                sprintf('%s now agrees with the oracle; remove it from ENCODERS_DISAGREE', $hex),
            );

            return;
        }

        $this->fail(sprintf('%s is listed as a divergence but is not in the fixture', $hex));
    }

    private function encode(string $hex, string $level, int $columns): \CrazyGoat\ScanMePHP\Symbol
    {
        $payload = hex2bin($hex);
        self::assertIsString($payload);

        return (new Scanme(Defaults::registry()))->generate(
            $payload,
            Symbology::Pdf417->value,
            new Pdf417Options(
                errorCorrectionLevel: $level === '' ? null : (int) $level,
                columns: $columns,
            ),
        );
    }

    /**
     * The oracle's own compaction, read back out of its symbol.
     *
     * Reading it rather than recording it separately means it cannot drift
     * from the modules beside it in the fixture, and reading the whole region
     * rather than just the length descriptor is what makes the comparison
     * mean something: the descriptor counts the pad codewords too, so a
     * payload that happens to leave a lot of slack would compare as generous
     * no matter how badly it had been compacted. Trailing pads are dropped —
     * codeword 900 is a latch to text compaction, which at the end of the data
     * has nothing left to apply and is only ever there to fill the grid.
     *
     * @return list<int>
     */
    private function oracleCodewords(string $modules, int $rows, int $columns): array
    {
        $width = \intdiv(\strlen($modules), $rows);
        $codewords = [];

        for ($row = 0; $row < $rows; $row++) {
            for ($column = 0; $column < $columns; $column++) {
                $start = $row * $width + (2 + $column) * Specs::CODEWORD_MODULES;
                $pattern = 0;
                for ($bit = 0; $bit < Specs::CODEWORD_MODULES; $bit++) {
                    $pattern = $pattern << 1 | ($modules[$start + $bit] === '1' ? 1 : 0);
                }
                $codewords[] = Pdf417CodewordPatternsTest::valueOf(Specs::cluster($row), $pattern);
            }
        }

        $region = \array_slice($codewords, 1, $codewords[0] - 1);
        while ($region !== [] && end($region) === HighLevelEncoder::LATCH_TEXT) {
            array_pop($region);
        }

        return array_values($region);
    }
}
