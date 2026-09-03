<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\DataBarExpandedStacked\Backend\PhpBackend;
use CrazyGoat\ScanMePHP\Generator\DataBarExpandedStacked\DataBarExpandedStackedOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What holds for every stacked symbol, rather than for the sampled ones.
 *
 * The fixture says our foldings match somebody else's at two pairs per row.
 * These are the claims that have to hold at every width: that a row never ends
 * up holding a single character, that a separator does what a separator is for,
 * and that folding a symbol into one row gives back the symbol it was folded
 * from.
 */
class DataBarExpandedStackedTest extends TestCase
{
    public function testTheSymbologyIsRegisteredAndDescribesItself(): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::DataBarExpandedStacked->value);
        $capabilities = $generator->getCapabilities();

        $this->assertSame('GS1 DataBar Expanded Stacked', $capabilities->title);
        $this->assertTrue($capabilities->providesText);
        $this->assertSame([], $capabilities->errorCorrectionLevels);
        $this->assertSame(DataBarExpandedStackedOptions::class, $capabilities->optionsClass);
    }

    #[DataProvider('aliasProvider')]
    public function testEveryAliasResolves(string $alias): void
    {
        $this->assertSame(
            Symbology::DataBarExpandedStacked->value,
            Defaults::registry()->getGenerator($alias)->getCapabilities()->name
        );
    }

    /** @return \Generator<string, array{string}> */
    public static function aliasProvider(): \Generator
    {
        foreach (['gs1-databar-expanded-stacked', 'rss-expanded-stacked', 'rss-exp-stack'] as $alias) {
            yield $alias => [$alias];
        }
    }

    /**
     * The widths this implementation stands behind, and the ones it refuses.
     *
     * An odd number of pairs per row is refused rather than drawn, because no
     * layout we could construct for it reads back — see the options class.
     */
    #[DataProvider('columnProvider')]
    public function testTheColumnCountIsCheckedAgainstWhatWeCanDraw(int $columns, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/even number of character pairs/');
        }

        $options = new DataBarExpandedStackedOptions(columns: $columns);

        $this->assertSame($columns, $options->columns);
    }

    /** @return \Generator<string, array{int, bool}> */
    public static function columnProvider(): \Generator
    {
        yield 'the default' => [2, true];
        yield 'four pairs' => [4, true];
        yield 'the widest' => [10, true];
        yield 'one pair, which does not read back' => [1, false];
        yield 'three pairs, which does not read back' => [3, false];
        yield 'eleven pairs' => [11, false];
        yield 'none' => [0, false];
        yield 'negative' => [-2, false];
    }

    /** The width is the row's capacity, whether or not the last row fills it. */
    #[DataProvider('columnWidthProvider')]
    public function testTheWidthIsTheRowCapacity(int $columns): void
    {
        $symbol = $this->generate(
            '(01)09501101020917(10)LOT0001',
            new DataBarExpandedStackedOptions(columns: $columns)
        );

        $this->assertSame(
            PhpBackend::GUARD_MODULES
                + PhpBackend::CHARACTER_MODULES * 2 * $columns
                + PhpBackend::FINDER_MODULES * $columns,
            $symbol->getWidth(),
            "width at {$columns} pairs per row"
        );
        $this->assertSame(0, $symbol->getQuietZone()->left);
        $this->assertSame(0, $symbol->getQuietZone()->right);
    }

    /** @return \Generator<string, array{int}> */
    public static function columnWidthProvider(): \Generator
    {
        foreach ([2, 4, 6, 8, 10] as $columns) {
            yield "{$columns} pairs per row" => [$columns];
        }
    }

    /**
     * A row never ends up holding a single character.
     *
     * The rule costs a character of padding when it bites, and the character
     * count feeds the checksum — so this is not cosmetic, and a symbol that
     * broke it would be a symbol whose check character was computed for a
     * different length.
     */
    public function testNoRowIsLeftHoldingASingleCharacter(): void
    {
        $offenders = [];

        for ($length = 1; $length <= 30; $length++) {
            foreach (['1', 'A', 'a'] as $filler) {
                $data = '(90)' . str_repeat($filler, $length);
                foreach ([2, 4] as $columns) {
                    $symbol = $this->generate($data, new DataBarExpandedStackedOptions(columns: $columns));
                    $characters = (int) $symbol->getMetadataValue('characters');
                    $remainder = $characters % (2 * $columns);

                    if ($remainder === 1) {
                        $offenders[] = "{$data} at {$columns}";
                    }
                }
            }
        }

        $this->assertSame([], \array_slice($offenders, 0, 5), 'a row holds one character');
    }

    /**
     * A separator is never dark where the row it sits against is dark.
     *
     * That is the whole job: break the vertical run so a scan line crossing
     * the boundary cannot read two rows as one. A separator that failed it
     * would still draw, and still scan, as something else.
     */
    public function testASeparatorNeverContinuesABar(): void
    {
        $offenders = [];

        foreach ($this->sampleSymbols() as $data => $symbol) {
            $rows = $symbol->rows();
            $heights = $symbol->getRowHeights();

            foreach ($rows as $index => $row) {
                if ($heights[$index] !== 1) {
                    continue;
                }

                foreach ([$index - 1, $index + 1] as $neighbour) {
                    if (($heights[$neighbour] ?? 0) !== PhpBackend::ROW_HEIGHT) {
                        continue;
                    }

                    for ($column = 0; $column < \strlen($row); $column++) {
                        if ($row[$column] === '1' && $rows[$neighbour][$column] === '1') {
                            $offenders[] = "{$data} row {$index} column {$column}";
                            break 2;
                        }
                    }
                }
            }
        }

        $this->assertSame([], \array_slice($offenders, 0, 5), 'a separator continues a bar');
    }

    /** Four modules at each end of every separator line stay light. */
    public function testEverySeparatorKeepsItsEndsLight(): void
    {
        $offenders = [];

        foreach ($this->sampleSymbols() as $data => $symbol) {
            $heights = $symbol->getRowHeights();

            foreach ($symbol->rows() as $index => $row) {
                if ($heights[$index] !== 1) {
                    continue;
                }

                if (substr($row, 0, 4) !== '0000' || substr($row, -4) !== '0000') {
                    $offenders[] = "{$data} row {$index}";
                }
            }
        }

        $this->assertSame([], \array_slice($offenders, 0, 5), 'a separator reaches its symbol edge');
    }

    /**
     * A symbol that fills exactly one row is the linear symbol.
     *
     * Folding is a layout, not an encoding: the same payload at a width it does
     * not need has to produce the same bars the unstacked generator does, or
     * one of the two is drawing something else.
     */
    public function testASingleFullRowIsTheLinearSymbol(): void
    {
        $data = '(90)1';
        $stacked = $this->generate($data, new DataBarExpandedStackedOptions(columns: 2));
        $linear = Defaults::registry()->getGenerator(Symbology::DataBarExpanded->value)->generate($data);

        $this->assertSame(1, (int) $stacked->getMetadataValue('rows'));
        $this->assertSame(4, (int) $stacked->getMetadataValue('characters'));
        $this->assertSame($linear->getWidth(), $stacked->getWidth());
        $this->assertSame($linear->toModuleString(), $stacked->toModuleString());
    }

    public function testTheTextIsTheElementStringsAsGs1WritesThem(): void
    {
        $this->assertSame(
            '(01)09501101020917(10)LOT0001',
            $this->generate('(01)09501101020917(10)LOT0001')->getText()
        );
    }

    #[DataProvider('badPayloadProvider')]
    public function testTheFacadeSaysWhyItCannotEncode(string $data): void
    {
        $this->expectException(UnsupportedDataException::class);

        (new Scanme(Defaults::registry()))->render($data, Symbology::DataBarExpandedStacked, 'svg');
    }

    /** @return \Generator<string, array{string}> */
    public static function badPayloadProvider(): \Generator
    {
        yield 'not a GS1 payload' => ['plain text'];
        yield 'empty' => [''];
        yield 'a character outside the GS1 set' => ['(90)a{b'];
        yield 'a GTIN with a wrong check digit' => ['(01)09501101020918'];
        yield 'more data than a symbol holds' => ['(90)' . str_repeat('a', 30) . '(91)' . str_repeat('b', 30)];
    }

    /** @return \Generator<string, Symbol> */
    private function sampleSymbols(): \Generator
    {
        $payloads = [
            '(01)09501101020917',
            '(01)09501101020917(10)LOT0001',
            '(90)111111(91)11111',
            '(90)abcdefghijklmnopqrstuvwxyz',
            '(90)GEBU1SG1T8IO532URE3V(21)zjqs09d0igjzy6x',
        ];

        foreach ($payloads as $data) {
            foreach ([2, 4, 10] as $columns) {
                yield "{$data} at {$columns}" => $this->generate(
                    $data,
                    new DataBarExpandedStackedOptions(columns: $columns)
                );
            }
        }
    }

    private function generate(string $data, ?DataBarExpandedStackedOptions $options = null): Symbol
    {
        return Defaults::registry()
            ->getGenerator(Symbology::DataBarExpandedStacked->value)
            ->generate($data, $options);
    }
}
