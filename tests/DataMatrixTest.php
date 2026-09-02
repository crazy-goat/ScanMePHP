<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Encoding\ReedSolomon256;
use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Format;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\AsciiEncodation;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\DataMatrixGenerator;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\DataMatrixOptions;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\Placement;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\Specs;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\SymbolSpec;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\TestCase;

/**
 * Data Matrix ECC200.
 *
 * The size table and the module placement are transcribed from ISO/IEC 16022,
 * so neither is trusted: the table is checked against the geometry it implies,
 * and the placement against the one property that makes it a code at all —
 * that it is a bijection between modules and codeword bits. Encodation and
 * error correction are anchored on the standard's own worked example.
 */
class DataMatrixTest extends TestCase
{
    private DataMatrixGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new DataMatrixGenerator();
    }

    // ------------------------------------------------------------ size table

    /** @return iterable<string, array{SymbolSpec}> */
    public static function specProvider(): iterable
    {
        foreach (Specs::all() as $spec) {
            yield $spec->name() => [$spec];
        }
    }

    /**
     * The data regions must tile the symbol exactly, and their area must
     * account for every codeword bit. Four modules may be left over — the
     * sizes that finish with a fixed 2×2 corner — but nothing else.
     *
     * @dataProvider specProvider
     */
    public function testEachSizeIsGeometricallyConsistent(SymbolSpec $spec): void
    {
        $this->assertSame(
            $spec->rows,
            $spec->regionsDown() * ($spec->regionRows + 2),
            'data regions plus their finders must fill the height exactly'
        );
        $this->assertSame(
            $spec->cols,
            $spec->regionsAcross() * ($spec->regionCols + 2),
            'and the width'
        );

        $slack = $spec->mappingRows() * $spec->mappingCols() - $spec->totalWords() * 8;
        $this->assertContains($slack, [0, 4], 'usable modules versus codeword bits');

        $this->assertSame(0, $spec->eccWords % $spec->blocks, 'error codewords split evenly across blocks');
        $this->assertLessThanOrEqual(68, $spec->eccPerBlock(), 'ECC200 never exceeds 68 per block');
        $this->assertSame($spec->dataWords, array_sum($spec->blockSizes()));
    }

    public function testTheTableCoversTheStandardsSizes(): void
    {
        $squares = array_filter(Specs::all(), static fn (SymbolSpec $s): bool => $s->isSquare());
        $rectangles = array_filter(Specs::all(), static fn (SymbolSpec $s): bool => !$s->isSquare());

        $this->assertCount(24, $squares);
        $this->assertCount(6, $rectangles);
        $this->assertSame('10x10', Specs::all()[0]->name());
        $this->assertSame(1558, Specs::maxDataWords());

        // 144×144 is the one size whose blocks are unequal; striding produces
        // eight of 156 and two of 155 without a special case.
        $largest = Specs::byName('144x144');
        $this->assertNotNull($largest);
        $this->assertSame(
            [156, 156, 156, 156, 156, 156, 156, 156, 155, 155],
            $largest->blockSizes()
        );
    }

    // ------------------------------------------------------------- placement

    /**
     * The placement must be a bijection: every codeword bit lands on exactly
     * one module, and every module holds either a bit or one of the fixed
     * corner modules. A layout that merely looks plausible but drops or
     * duplicates a bit would produce a symbol no scanner can read.
     *
     * @dataProvider specProvider
     */
    public function testPlacementIsABijectionBetweenModulesAndCodewordBits(SymbolSpec $spec): void
    {
        $map = Placement::map($spec->mappingRows(), $spec->mappingCols());

        $seen = [];
        $fixed = 0;
        foreach ($map as $row) {
            foreach ($row as $entry) {
                if ($entry < 0) {
                    $this->assertContains($entry, [Placement::FIXED_DARK, Placement::FIXED_LIGHT]);
                    $fixed++;

                    continue;
                }
                $this->assertArrayNotHasKey($entry, $seen, "bit $entry placed twice");
                $seen[$entry] = true;
            }
        }

        $bits = $spec->totalWords() * 8;
        $this->assertCount($bits, $seen, 'every codeword bit must be placed');
        $this->assertSame($bits - 1, max(array_keys($seen)), 'and no bit beyond the last codeword');
        $this->assertSame(
            $spec->mappingRows() * $spec->mappingCols() - $bits,
            $fixed,
            'fixed modules must account for exactly the leftover area'
        );
    }

    /**
     * The fourth corner case fires only for 8×18 and 16×36. Static analysis
     * reports its condition as unreachable, which is wrong: removing the branch
     * leaves those two symbols with a codeword that is never placed.
     *
     * Corner 4 puts all eight bits of one codeword at these modules, so they
     * must all belong to the same codeword — which is what distinguishes it
     * from the diagonal sweep having covered them.
     */
    public function testTheFourthCornerCaseIsRequiredByTwoRectangularSizes(): void
    {
        foreach (['8x18', '16x36'] as $name) {
            $spec = Specs::byName($name);
            $this->assertNotNull($spec);

            $rows = $spec->mappingRows();
            $cols = $spec->mappingCols();
            $map = Placement::map($rows, $cols);

            $modules = [
                [$rows - 1, 0], [$rows - 1, $cols - 1],
                [0, $cols - 3], [0, $cols - 2], [0, $cols - 1],
                [1, $cols - 3], [1, $cols - 2], [1, $cols - 1],
            ];

            $codewords = [];
            $bits = [];
            foreach ($modules as [$row, $col]) {
                $entry = $map[$row][$col];
                $this->assertGreaterThanOrEqual(0, $entry, "$name: ($row, $col) must hold data");
                $codewords[] = intdiv($entry, 8);
                $bits[] = $entry % 8;
            }

            $this->assertCount(1, array_unique($codewords), "$name: corner 4 places one codeword");
            $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7], $bits, "$name: in bit order");
        }
    }

    /** @dataProvider specProvider */
    public function testPlacementIsCachedPerSizeAndStable(SymbolSpec $spec): void
    {
        $first = Placement::map($spec->mappingRows(), $spec->mappingCols());

        $this->assertSame($first, Placement::map($spec->mappingRows(), $spec->mappingCols()));
    }

    // ------------------------------------------------------------ encodation

    /**
     * ISO/IEC 16022's own worked example: "123456" encodes to data codewords
     * 142, 164, 186.
     */
    public function testAsciiEncodationMatchesThePublishedExample(): void
    {
        $this->assertSame([142, 164, 186], AsciiEncodation::encode('123456'));
    }

    /** @return iterable<string, array{string, list<int>}> */
    public static function encodationProvider(): iterable
    {
        yield 'digit pairs cost one codeword' => ['123456', [142, 164, 186]];
        yield 'a letter is its byte plus one' => ['A', [66]];
        yield 'nul is codeword one' => ["\0", [1]];
        yield 'del is codeword 128' => ["\x7f", [128]];
        yield 'a lone digit is plain ascii' => ['7', [56]];
        yield 'an odd digit run leaves a single' => ['123', [142, 52]];
        yield 'digits split by a letter do not pair' => ['1A2', [50, 66, 51]];
        yield 'high bytes take an upper shift' => ["\xe9", [235, 106]];
        yield 'the top byte' => ["\xff", [235, 128]];
    }

    /** @dataProvider encodationProvider */
    public function testAsciiEncodation(string $data, array $expected): void
    {
        $this->assertSame($expected, AsciiEncodation::encode($data));
    }

    /**
     * Only the first pad is the plain pad codeword; the rest are randomised by
     * position, so padding cannot produce a large uniform block of modules.
     */
    public function testPaddingIsRandomisedAfterTheFirstCodeword(): void
    {
        $padded = AsciiEncodation::pad([142, 164, 186], 8);

        $this->assertCount(8, $padded);
        $this->assertSame([142, 164, 186], \array_slice($padded, 0, 3));
        $this->assertSame(129, $padded[3], 'the first pad is the plain one');

        $pads = \array_slice($padded, 4);
        $this->assertCount(4, $pads);
        $this->assertSame($pads, array_unique($pads), 'randomised pads must not repeat');
        foreach ($pads as $pad) {
            $this->assertGreaterThanOrEqual(1, $pad);
            $this->assertLessThanOrEqual(254, $pad);
        }

        // Positions are one-based over the whole codeword stream.
        $this->assertSame(AsciiEncodation::randomisedPad(5), $padded[4]);
        $this->assertSame(AsciiEncodation::randomisedPad(8), $padded[7]);
    }

    public function testPaddingIsSkippedWhenTheDataFillsTheSymbol(): void
    {
        $exact = [142, 164, 186];

        $this->assertSame($exact, AsciiEncodation::pad($exact, 3));
    }

    public function testPaddingRejectsOverflow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('needs 3 codewords but the symbol holds 2');

        AsciiEncodation::pad([1, 2, 3], 2);
    }

    // ------------------------------------------------------------- end to end

    /**
     * Read the symbol back: strip the finder pattern off each data region,
     * invert the placement map, and recover the codewords. This checks the
     * assembly step — region offsets, clock tracks, the solid L — against the
     * placement independently of it.
     *
     * @return list<int>
     */
    private function recoverCodewords(Symbol $symbol, SymbolSpec $spec): array
    {
        $rows = $symbol->rows();
        $blockRows = $spec->regionRows + 2;
        $blockCols = $spec->regionCols + 2;

        // Peel the finders away to get the mapping matrix back.
        $mapping = [];
        for ($row = 0; $row < $spec->rows; $row++) {
            $inBlockRow = $row % $blockRows;
            if ($inBlockRow === 0 || $inBlockRow === $blockRows - 1) {
                continue;
            }
            $line = '';
            for ($col = 0; $col < $spec->cols; $col++) {
                $inBlockCol = $col % $blockCols;
                if ($inBlockCol === 0 || $inBlockCol === $blockCols - 1) {
                    continue;
                }
                $line .= $rows[$row][$col];
            }
            $mapping[] = $line;
        }

        $codewords = array_fill(0, $spec->totalWords(), 0);
        foreach (Placement::map($spec->mappingRows(), $spec->mappingCols()) as $y => $mapRow) {
            foreach ($mapRow as $x => $entry) {
                if ($entry < 0) {
                    continue;
                }
                if ($mapping[$y][$x] === '1') {
                    $codewords[intdiv($entry, 8)] |= 1 << (7 - $entry % 8);
                }
            }
        }

        return $codewords;
    }

    /**
     * The full chain on the standard's example: "123456" in a 10×10 symbol
     * must hold data codewords 142, 164, 186 followed by the five error
     * correction codewords 114, 25, 5, 88, 102.
     */
    public function testTheStandardsExampleRoundTripsThroughTheWholeChain(): void
    {
        $spec = Specs::byName('10x10');
        $this->assertNotNull($spec);

        $symbol = $this->generator->generate('123456');
        $this->assertSame('10x10', $symbol->getMetadataValue('size'));

        $this->assertSame(
            [142, 164, 186, 114, 25, 5, 88, 102],
            $this->recoverCodewords($symbol, $spec)
        );
    }

    /** @return iterable<string, array{string}> */
    public static function payloadProvider(): iterable
    {
        yield 'digits' => ['123456'];
        yield 'a url' => ['https://example.com'];
        yield 'mixed' => ['ABC-123/456'];
        yield 'high bytes' => ["\xe9\xff\x80"];
        yield 'one character' => ['A'];
        yield 'multi region' => [str_repeat('AB12', 20)];
        yield 'interleaved blocks' => [str_repeat('AB12', 60)];
    }

    /**
     * Whatever the size or block count, the recovered codewords must be the
     * encoded data, its padding, and error correction that verifies.
     *
     * @dataProvider payloadProvider
     */
    public function testEveryPayloadRoundTripsWithVerifiableErrorCorrection(string $data): void
    {
        $symbol = $this->generator->generate($data);
        $spec = Specs::byName((string) $symbol->getMetadataValue('size'));
        $this->assertNotNull($spec);

        $recovered = $this->recoverCodewords($symbol, $spec);
        $expected = AsciiEncodation::pad(AsciiEncodation::encode($data), $spec->dataWords);

        $this->assertSame($expected, \array_slice($recovered, 0, $spec->dataWords), 'data codewords');

        // Recompute the error correction per block and check the interleaving.
        $reedSolomon = ReedSolomon256::forDataMatrix();
        $ecc = \array_slice($recovered, $spec->dataWords);
        $this->assertCount($spec->eccWords, $ecc);

        for ($block = 0; $block < $spec->blocks; $block++) {
            $blockData = [];
            for ($position = $block; $position < $spec->dataWords; $position += $spec->blocks) {
                $blockData[] = $expected[$position];
            }

            $blockEcc = $reedSolomon->encode($blockData, $spec->eccPerBlock());
            foreach ($blockEcc as $index => $codeword) {
                $this->assertSame(
                    $codeword,
                    $ecc[$block + $index * $spec->blocks],
                    "block $block, error codeword $index"
                );
            }
        }
    }

    /** @dataProvider payloadProvider */
    public function testTheFinderPatternFramesEveryDataRegion(string $data): void
    {
        $symbol = $this->generator->generate($data);
        $spec = Specs::byName((string) $symbol->getMetadataValue('size'));
        $this->assertNotNull($spec);

        $rows = $symbol->rows();
        $blockRows = $spec->regionRows + 2;
        $blockCols = $spec->regionCols + 2;

        for ($row = 0; $row < $spec->rows; $row++) {
            $inBlockRow = $row % $blockRows;
            for ($col = 0; $col < $spec->cols; $col++) {
                $inBlockCol = $col % $blockCols;
                $module = $rows[$row][$col];

                if ($inBlockRow === $blockRows - 1 || $inBlockCol === 0) {
                    $this->assertSame('1', $module, "solid L at ($row, $col)");
                } elseif ($inBlockRow === 0) {
                    $this->assertSame(
                        $inBlockCol % 2 === 0 ? '1' : '0',
                        $module,
                        "top clock track at ($row, $col)"
                    );
                } elseif ($inBlockCol === $blockCols - 1) {
                    $this->assertSame(
                        $inBlockRow % 2 === 1 ? '1' : '0',
                        $module,
                        "right clock track at ($row, $col)"
                    );
                }
            }
        }
    }

    public function testSymbolShapeMatchesTheDeclaredCapabilities(): void
    {
        $capabilities = $this->generator->getCapabilities();
        $symbol = $this->generator->generate('123456');

        $this->assertSame(Dimension::Matrix, $symbol->getDimension());
        $this->assertSame(ModuleShape::Square, $symbol->getModuleShape());
        $this->assertSame($capabilities->moduleShape, $symbol->getModuleShape());
        $this->assertSame('data-matrix', $symbol->getMetadataValue('symbology'));
        $this->assertNull($symbol->getText(), 'Data Matrix has no human-readable convention');
        $this->assertFalse($capabilities->providesText);
        $this->assertFalse($capabilities->hasErrorCorrection(), 'ECC200 fixes the recovery data per size');

        // One module of quiet zone, per ISO/IEC 16022 section 5.3.
        $this->assertSame(1, $symbol->getQuietZone()->left);
        $this->assertSame(1, $symbol->getQuietZone()->top);
        $this->assertTrue($symbol->hasUniformRows());
    }

    // ---------------------------------------------------------------- options

    public function testRectangularSizesAreOptedInto(): void
    {
        $square = $this->generator->generate('12345');
        $rectangular = $this->generator->generate('12345', new DataMatrixOptions(rectangular: true));

        // '12345' is three codewords: the pairs 12 and 34, then a lone 5.
        $this->assertSame([142, 164, 54], AsciiEncodation::encode('12345'));
        $this->assertSame('10x10', $square->getMetadataValue('size'));
        $this->assertSame('8x18', $rectangular->getMetadataValue('size'));
        $this->assertSame(18, $rectangular->getWidth());
        $this->assertSame(8, $rectangular->getHeight());
    }

    public function testAForcedSizeIsHonoured(): void
    {
        $symbol = $this->generator->generate('123456', new DataMatrixOptions(size: '26x26'));

        $this->assertSame('26x26', $symbol->getMetadataValue('size'));
        $this->assertSame(26, $symbol->getWidth());
    }

    public function testAForcedSizeTooSmallIsRejected(): void
    {
        $this->expectException(DataTooLargeException::class);
        $this->expectExceptionMessage('but 10x10 holds 3');

        $this->generator->generate('this will never fit', new DataMatrixOptions(size: '10x10'));
    }

    public function testAnUnknownForcedSizeIsRejectedAtConstruction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown Data Matrix size "11x11"');

        new DataMatrixOptions(size: '11x11');
    }

    public function testCapacityIsReportedRatherThanOverflowed(): void
    {
        $this->assertTrue($this->generator->canEncode('123456'));
        $this->assertFalse($this->generator->canEncode(''));

        // 1558 codewords is the ceiling; letters cost one codeword each.
        $this->assertTrue($this->generator->canEncode(str_repeat('A', 1558)));
        $this->assertFalse($this->generator->canEncode(str_repeat('A', 1559)));

        // Rectangular symbols top out at 49 codewords.
        $options = new DataMatrixOptions(rectangular: true);
        $this->assertTrue($this->generator->canEncode(str_repeat('A', 49), $options));
        $this->assertFalse($this->generator->canEncode(str_repeat('A', 50), $options));

        $this->expectException(UnsupportedDataException::class);
        Scanme::create()->render(str_repeat('A', 1559), Symbology::DataMatrix, Format::Svg);
    }

    public function testEncodingIsStable(): void
    {
        $first = $this->generator->generate('https://example.com')->toModuleString();

        $this->assertSame($first, $this->generator->generate('https://example.com')->toModuleString());
        $this->assertSame(
            $first,
            (new DataMatrixGenerator())->generate('https://example.com')->toModuleString()
        );
    }

    public function testItRendersInEveryCompatibleFormat(): void
    {
        $scanme = Scanme::create();

        foreach ($scanme->getRegistry()->rendererFormats() as $format) {
            $this->assertTrue($scanme->supports(Symbology::DataMatrix, $format), $format);
            $this->assertNotSame('', $scanme->render('123456', Symbology::DataMatrix, $format), $format);
        }
    }
}
