<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Encoding\Pdf417\Pdf417Encoder;
use CrazyGoat\ScanMePHP\Encoding\Pdf417\Specs;
use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Options;
use CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Symbols;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\TestCase;

/**
 * PDF417 as a caller meets it.
 */
class Pdf417Test extends TestCase
{
    private Scanme $scanme;

    protected function setUp(): void
    {
        $this->scanme = new Scanme(Defaults::registry());
    }

    public function testItIsRegisteredUnderItsNameAndAliases(): void
    {
        foreach (['pdf417', 'pdf-417'] as $name) {
            $symbol = $this->scanme->generate('BOARDING-4471', $name);

            $this->assertSame('pdf417', $symbol->getMetadata()['symbology']);
        }
    }

    public function testItReportsNineErrorCorrectionLevels(): void
    {
        $capabilities = Defaults::registry()
            ->getGenerator(Symbology::Pdf417->value)
            ->getCapabilities();

        $this->assertSame(Dimension::Matrix, $capabilities->dimension);
        $this->assertSame(Pdf417Options::class, $capabilities->optionsClass);
        $this->assertFalse($capabilities->providesText);
        $this->assertSame(['0', '1', '2', '3', '4', '5', '6', '7', '8'], $capabilities->errorCorrectionLevels);
    }

    public function testTheSymbolCarriesItsShapeAndLevel(): void
    {
        $symbol = $this->scanme->generate(
            'BOARDING-4471',
            Symbology::Pdf417->value,
            new Pdf417Options(errorCorrectionLevel: 3, columns: 4),
        );
        $metadata = $symbol->getMetadata();

        $this->assertSame(4, $metadata['columns']);
        $this->assertSame(3, $metadata['errorCorrectionLevel']);
        $this->assertSame($symbol->getHeight(), $metadata['rows']);
        $this->assertSame(Specs::width(4), $symbol->getWidth());
    }

    /**
     * The width follows from the column count and nothing else.
     *
     * Every row spends the width of four data columns on structure — the start
     * pattern, both row indicators and the stop pattern — however much data it
     * carries, which is the cost of rows being independently readable.
     */
    public function testTheWidthIsTheColumnsPlusTheFixedOverhead(): void
    {
        foreach ([1, 2, 6, 15, 30] as $columns) {
            $symbol = $this->scanme->generate(
                'X',
                Symbology::Pdf417->value,
                new Pdf417Options(columns: $columns),
            );

            $this->assertSame(17 * ($columns + 4) + 1, $symbol->getWidth());
        }
    }

    /**
     * PDF417 is the first matrix symbology here whose rows are not one module
     * tall, and the height lives where a linear symbology's bar height does.
     */
    public function testRowsAreThreeModulesTallByDefaultAndTheCallerCanSayOtherwise(): void
    {
        $symbol = $this->scanme->generate('BOARDING-4471', Symbology::Pdf417->value);

        $this->assertSame(Dimension::Matrix, $symbol->getDimension());
        $this->assertSame(
            array_fill(0, $symbol->getHeight(), Pdf417Options::DEFAULT_ROW_HEIGHT),
            $symbol->getRowHeights(),
        );
        $this->assertSame($symbol->getHeight() * 3, $symbol->getModuleHeight());

        $tall = $this->scanme->generate(
            'BOARDING-4471',
            Symbology::Pdf417->value,
            new Pdf417Options(rowHeight: 5),
        );

        $this->assertSame($tall->getHeight() * 5, $tall->getModuleHeight());
    }

    public function testTheQuietZoneIsTwoModulesAllRound(): void
    {
        $quietZone = $this->scanme
            ->generate('BOARDING-4471', Symbology::Pdf417->value)
            ->getQuietZone();

        $this->assertSame(Pdf417Symbols::QUIET_ZONE, $quietZone->top);
        $this->assertSame(Pdf417Symbols::QUIET_ZONE, $quietZone->left);
    }

    public function testAHigherLevelCostsMoreRowsAtTheSameWidth(): void
    {
        // Six columns is wide enough for level 8's 512 check codewords to fit
        // inside ninety rows; at four columns they would not, which is itself
        // the reason the level and the shape have to be chosen together.
        $rows = [];
        foreach ([0, 4, 8] as $level) {
            $symbol = $this->scanme->generate(
                'BOARDING-4471',
                Symbology::Pdf417->value,
                new Pdf417Options(errorCorrectionLevel: $level, columns: 6),
            );
            $rows[$level] = $symbol->getHeight();
        }

        $this->assertLessThan($rows[4], $rows[0]);
        $this->assertLessThan($rows[8], $rows[4]);
    }

    public function testTheLevelDefaultsToWhatTheStandardRecommends(): void
    {
        $symbol = $this->scanme->generate('BOARDING-4471', Symbology::Pdf417->value);

        // Thirteen characters is well under forty data codewords, so level 2.
        $this->assertSame(2, $symbol->getMetadata()['errorCorrectionLevel']);
        $this->assertSame(2, Specs::recommendedLevel(40));
        $this->assertSame(3, Specs::recommendedLevel(41));
        $this->assertSame(5, Specs::recommendedLevel(1000));
    }

    public function testARowFloorRaisesTheSymbolButNeverShrinksIt(): void
    {
        $short = $this->scanme->generate(
            'BOARDING-4471',
            Symbology::Pdf417->value,
            new Pdf417Options(columns: 6),
        );
        $floored = $this->scanme->generate(
            'BOARDING-4471',
            Symbology::Pdf417->value,
            new Pdf417Options(columns: 6, rows: 20),
        );

        $this->assertSame(20, $floored->getHeight());
        $this->assertGreaterThan($short->getHeight(), $floored->getHeight());

        // A floor below what the data needs changes nothing.
        $ignored = $this->scanme->generate(
            str_repeat('X', 300),
            Symbology::Pdf417->value,
            new Pdf417Options(columns: 6, rows: 3),
        );

        $this->assertGreaterThan(3, $ignored->getHeight());
    }

    public function testTheSpareCellsBecomePadCodewords(): void
    {
        $symbol = $this->scanme->generate(
            'X',
            Symbology::Pdf417->value,
            new Pdf417Options(errorCorrectionLevel: 0, columns: 10),
        );
        $metadata = $symbol->getMetadata();

        // Ten columns and at least three rows is thirty cells for a payload
        // that needs three, so the rest are pads. That they exist at all is
        // why the shape has to be a request rather than a derived fact.
        $this->assertGreaterThan(0, $metadata['padCodewords']);
        $this->assertSame(
            $symbol->getHeight() * 10 - 2,
            $metadata['dataCodewords'] + $metadata['padCodewords'],
        );
    }

    public function testAnEmptyPayloadIsRefused(): void
    {
        $this->expectException(UnsupportedDataException::class);

        $this->scanme->generate('', Symbology::Pdf417->value);
    }

    public function testAPayloadTooWideForTheChosenShapeIsRefused(): void
    {
        // One data column at ninety rows is ninety codewords, and level 8
        // spends 512 of them on error correction alone.
        $this->expectException(DataTooLargeException::class);

        (new Pdf417Encoder())->encode(str_repeat('X', 200), 8, 1);
    }

    public function testTheCapacityCheckRefusesWhatCannotFitUnderAnyShape(): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::Pdf417->value);

        $this->assertTrue($generator->canEncode('BOARDING-4471'));
        $this->assertFalse($generator->canEncode(''));
        $this->assertFalse($generator->canEncode(str_repeat('X', 20000)));
    }

    /** @return \Generator<string, array{Pdf417Options|callable}> */
    public static function badOptionProvider(): \Generator
    {
        yield 'level below zero' => [static fn (): \CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Options => new Pdf417Options(errorCorrectionLevel: -1)];
        yield 'level above eight' => [static fn (): \CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Options => new Pdf417Options(errorCorrectionLevel: 9)];
        yield 'no columns' => [static fn (): \CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Options => new Pdf417Options(columns: 0)];
        yield 'too many columns' => [static fn (): \CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Options => new Pdf417Options(columns: 31)];
        yield 'fewer than three rows' => [static fn (): \CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Options => new Pdf417Options(rows: 2)];
        yield 'more than ninety rows' => [static fn (): \CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Options => new Pdf417Options(rows: 91)];
        yield 'a row no modules tall' => [static fn (): \CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Options => new Pdf417Options(rowHeight: 0)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badOptionProvider')]
    public function testTheOptionsRefuseWhatTheStandardHasNoRoomFor(callable $construct): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $construct();
    }

    public function testTheRowIndicatorsSayTheSameThingOnBothSidesOfEveryThreeRows(): void
    {
        // The point of rotating them by a cluster: a scanner that only ever
        // reads one edge still learns the row count, the column count and the
        // error correction level within any three consecutive rows.
        $rows = 12;
        $columns = 5;
        $level = 4;

        $seen = [];
        for ($row = 0; $row < 3; $row++) {
            $seen[] = Specs::leftIndicator($row, $rows, $columns, $level) % 30;
            $seen[] = Specs::rightIndicator($row, $rows, $columns, $level) % 30;
        }

        $this->assertContains(intdiv($rows - 1, 3), $seen, 'the row count');
        $this->assertContains($columns - 1, $seen, 'the column count');
        $this->assertContains($level * 3 + ($rows - 1) % 3, $seen, 'the level');
    }
}
