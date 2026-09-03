<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Generator\DataBarExpandedStacked\Backend\PhpBackend;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GS1 DataBar Expanded Stacked, module for module, against an encoder we did
 * not write.
 *
 * The characters are the linear symbology's and are checked there. What this
 * fixture is for is the folding: which characters land in which row, which way
 * round each row is drawn, and what goes between them. Every one of those was
 * measured rather than read, and every one of them draws a plausible symbol
 * when wrong — a mirrored row that should not be reads as different data, and a
 * separator that reproduces a finder reads as a row that is not there.
 *
 * Two character pairs per row is the only width this fixture covers, because it
 * is the only one the reference encoder draws. The wider foldings are checked
 * by reading them back in {@see DecoderRoundTripTest} instead.
 */
class DataBarExpandedStackedReferenceTest extends TestCase
{
    /** @return \Generator<string, array{string, string}> */
    public static function referenceProvider(): \Generator
    {
        $csv = __DIR__ . '/fixtures/databar_expanded_stacked_reference.csv';
        $handle = fopen($csv, 'r');
        if ($handle === false) {
            return;
        }

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            [$data, $rows] = $row;
            yield $data => [$data, $rows];
        }

        fclose($handle);
    }

    #[DataProvider('referenceProvider')]
    public function testTheModulesMatchAnIndependentEncoder(string $data, string $expected): void
    {
        $symbol = Defaults::registry()->getGenerator(Symbology::DataBarExpandedStacked->value)->generate($data);

        $this->assertSame($expected, implode('|', $symbol->rows()), "modules for {$data}");
    }

    /**
     * A data row is 34 modules tall and a separator row is one.
     *
     * The heights are the whole reason a stacked symbol is not just a taller
     * bitmap: they are what a renderer scales, and a separator drawn 34 modules
     * tall is a symbol with three extra rows of nothing in it.
     */
    #[DataProvider('referenceProvider')]
    public function testTheRowHeightsAlternateWithTheSeparators(string $data, string $expected): void
    {
        $symbol = Defaults::registry()->getGenerator(Symbology::DataBarExpandedStacked->value)->generate($data);
        $rows = (int) $symbol->getMetadataValue('rows');

        $heights = [];
        for ($row = 0; $row < $rows; $row++) {
            if ($row > 0) {
                $heights = [...$heights, 1, 1, 1];
            }

            $heights[] = PhpBackend::ROW_HEIGHT;
        }

        $this->assertSame($heights, $symbol->getRowHeights(), "row heights for {$data}");
        $this->assertSame(\count($heights), substr_count($expected, '|') + 1, "module rows for {$data}");
    }

    /** Every row count the fixture can reach, so every folding is drawn once. */
    public function testTheFixtureFoldsIntoEveryRowCount(): void
    {
        $counts = [];
        foreach (self::referenceProvider() as [, $rows]) {
            $counts[(substr_count($rows, '|') + 4) / 4] = true;
        }

        ksort($counts);

        $this->assertSame(range(1, 6), array_keys($counts), 'a row count is never drawn');
    }

    /**
     * Every shape a last row can take: four characters, three ending on a
     * finder, and two.
     *
     * The three differ in more than length — the two-character row is the one
     * drawn forwards in a mirrored position — so a fixture that only ever ends
     * on a full row has checked one of the three.
     */
    public function testTheFixtureEndsOnEveryShapeOfLastRow(): void
    {
        $widths = [];
        foreach (self::referenceProvider() as [$data, $rows]) {
            $last = substr($rows, strrpos($rows, '|') === false ? 0 : strrpos($rows, '|') + 1);
            $widths[strrpos($last, '1') === false ? 0 : strrpos($last, '1')] = true;
        }

        // A four-character row runs to the symbol's width, a three-character
        // row stops at 84 and a two-character one at 52 or 53 depending on
        // which way round it was drawn.
        $this->assertArrayHasKey(101, $widths, 'no symbol ends on a full row');
        $this->assertArrayHasKey(83, $widths, 'no symbol ends on a three-character row');
        $this->assertTrue(
            isset($widths[52]) || isset($widths[51]),
            'no symbol ends on a two-character row'
        );
    }
}
