<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Encoding\Mode;
use CrazyGoat\ScanMePHP\Encoding\Rmqr\Layout;
use CrazyGoat\ScanMePHP\Encoding\Rmqr\RmqrEncoder;
use CrazyGoat\ScanMePHP\Encoding\Rmqr\Specs;
use CrazyGoat\ScanMePHP\Encoding\Segmentation;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;
use CrazyGoat\ScanMePHP\Exception\InvalidDataException;
use CrazyGoat\ScanMePHP\Format;
use CrazyGoat\ScanMePHP\Generator\Rmqr\RmqrOptions;
use CrazyGoat\ScanMePHP\Generator\Rmqr\Version;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What rMQR has to be true of itself, as opposed to what it has to agree with
 * zint about.
 *
 * This file carries more weight here than its Micro QR counterpart does, and
 * the reason is worth stating: Micro QR has both an independent encoder and an
 * independent reader, so a mistake has to survive two opinions. rMQR has only
 * the encoder — zxing-cpp 3.1.1 lists the format and cannot decode one, ours
 * or zint's — so the second opinion has to come from the tables being
 * internally coherent. The capacity the size table promises is the number of
 * modules the geometry leaves free; the block counts divide the check
 * codewords they are supposed to divide; the format information is a code with
 * the distance a code like it should have. None of those is a restatement of
 * the implementation, and each of them is derived here rather than looked up.
 */
class RmqrTest extends TestCase
{
    /**
     * The codeword table is the geometry, to within a byte.
     *
     * Every module that is not a function pattern and not one of the
     * thirty-six format modules holds a codeword bit, so the free-module count
     * has to be the codeword total times eight plus whatever will not fill a
     * final byte. The two numbers come from opposite directions — the table
     * from ISO/IEC 23941, the geometry from the finder, the sub-finder, four
     * timing patterns and up to four alignment columns — and a mistake in
     * either alone breaks the identity. That they agree on all thirty-two
     * shapes is why R7x139 holds sixty-eight codewords and not sixty-seven.
     */
    #[DataProvider('sizeProvider')]
    public function testTheCodewordTableIsTheGeometry(int $index): void
    {
        $free = (new Layout($index))->capacity();
        $codewords = Specs::totalCodewords($index) * 8;

        $this->assertGreaterThanOrEqual($codewords, $free, $this->shape($index) . ' has too few modules');
        $this->assertLessThan(
            $codewords + 8,
            $free,
            $this->shape($index) . ' has a whole spare codeword, so the table is short',
        );
    }

    /** Data plus error correction is the total, at both levels, everywhere. */
    #[DataProvider('cellProvider')]
    public function testTheDataAndTheCheckCodewordsAreTheWholeSymbol(
        int $index,
        ErrorCorrectionLevel $level,
    ): void {
        $this->assertSame(
            Specs::totalCodewords($index),
            Specs::dataCodewords($index, $level) + Specs::errorCorrectionCodewords($index, $level),
            $this->shape($index) . '-' . $level->name,
        );
    }

    /**
     * Every block gets the same number of check codewords, and a real share of
     * the data.
     *
     * This is the invariant a wrong block count breaks first. Reed–Solomon
     * blocks in a QR-family symbol are equal in check codewords and differ by
     * at most one data codeword, so a count that does not divide the check
     * total cannot be the count the standard means — and a count that leaves a
     * block with no data at all is worse than wrong, it is an encoder that
     * silently drops payload.
     */
    #[DataProvider('cellProvider')]
    public function testEveryBlockIsAWholeBlock(int $index, ErrorCorrectionLevel $level): void
    {
        $blocks = Specs::blocks($index, $level);
        $where = $this->shape($index) . '-' . $level->name;

        $this->assertSame(
            0,
            Specs::errorCorrectionCodewords($index, $level) % $blocks,
            "{$where}: {$blocks} blocks do not share the check codewords evenly",
        );
        $this->assertGreaterThanOrEqual(
            1,
            intdiv(Specs::dataCodewords($index, $level), $blocks),
            "{$where}: a block would carry no data",
        );
    }

    /** Thirty-two shapes, six heights, six widths, and 27 only at two heights. */
    public function testThereAreThirtyTwoShapesAndNotThirtySix(): void
    {
        $this->assertCount(32, Specs::indexes());

        $heights = [];
        $widths = [];
        foreach (Specs::indexes() as $index) {
            $heights[Specs::height($index)] = true;
            $widths[Specs::width($index)] = true;
        }

        ksort($heights);
        ksort($widths);
        $this->assertSame([7, 9, 11, 13, 15, 17], array_keys($heights));
        $this->assertSame([27, 43, 59, 77, 99, 139], array_keys($widths));

        $narrow = array_filter(
            Specs::indexes(),
            static fn (int $index): bool => Specs::width($index) === 27,
        );
        $this->assertSame(
            [11, 13],
            array_values(array_unique(array_map(Specs::height(...), $narrow))),
            'width 27 exists at two heights and no others',
        );
    }

    /** M and H everywhere, and nothing else anywhere. */
    public function testEverySizeOffersTheSameTwoLevels(): void
    {
        $this->assertSame(
            [ErrorCorrectionLevel::Medium, ErrorCorrectionLevel::High],
            Specs::levels(),
        );
        $this->assertFalse(Specs::supports(ErrorCorrectionLevel::Low));
        $this->assertFalse(Specs::supports(ErrorCorrectionLevel::Quartile));
    }

    /**
     * The one mask is the one QR numbers 4, written out from QR's own formula.
     *
     * rMQR has no mask number in its format information, so a wrong mask is
     * not a symbol that scans as a different mask — it is a symbol that does
     * not scan. Restating `(r div 2 + c div 3) mod 2` here would prove
     * nothing; QR's own definition is a different sentence with the same
     * meaning, so this is a real comparison.
     */
    public function testTheMaskIsQrsFourth(): void
    {
        for ($row = 0; $row < 20; $row++) {
            for ($column = 0; $column < 20; $column++) {
                $this->assertSame(
                    (int) floor($row / 2) + (int) floor($column / 3) === 2 * intdiv(
                        (int) floor($row / 2) + (int) floor($column / 3),
                        2,
                    ),
                    Layout::mask($row, $column),
                    "mask at {$row},{$column}",
                );
            }
        }
    }

    /**
     * The format information is a code, and it is a good one.
     *
     * Sixty-four values in eighteen bits with a minimum Hamming distance of
     * eight is what BCH(18,6) buys: a reader can be wrong about three modules
     * of the format and still recover it, and cannot mistake one size for
     * another without seeing four. Asserting the distance rather than the
     * generator polynomial is the point — a wrong generator that still
     * produced distinct values would pass a uniqueness test and fail this one.
     */
    public function testTheFormatInformationIsEightBitsApart(): void
    {
        $values = [];
        foreach (Specs::indexes() as $index) {
            foreach (Specs::levels() as $level) {
                $values[] = Layout::format($index, $level);
            }
        }

        $this->assertCount(64, $values);
        $this->assertCount(64, array_unique($values), 'two symbols would read as one');

        $closest = \PHP_INT_MAX;
        foreach ($values as $i => $a) {
            foreach ($values as $j => $b) {
                if ($i < $j) {
                    $closest = min($closest, substr_count(decbin($a ^ $b), '1'));
                }
            }
        }

        $this->assertSame(8, $closest, 'the format code is weaker than BCH(18,6)');
    }

    /**
     * The two copies of the format information are masked differently.
     *
     * Using one constant for both is the mistake that draws a symbol whose
     * first copy is right, which is the kind of thing that survives a fixture
     * of a few sizes. Here it fails immediately: the two copies of a given
     * symbol's format never carry the same bits.
     */
    public function testTheTwoFormatCopiesAreNotTheSameBits(): void
    {
        foreach ([0, 10, 21, 31] as $index) {
            foreach (Specs::levels() as $level) {
                $modules = $this->modules($index, $level);
                $height = Specs::height($index);
                $width = Specs::width($index);

                $first = '';
                foreach ([8, 9, 10] as $column) {
                    for ($row = 1; $row <= 5; $row++) {
                        $first .= $modules[$row * $width + $column];
                    }
                }

                $second = '';
                foreach ([$width - 8, $width - 7, $width - 6] as $column) {
                    for ($row = $height - 6; $row <= $height - 2; $row++) {
                        $second .= $modules[$row * $width + $column];
                    }
                }

                $this->assertNotSame(
                    $first,
                    $second,
                    $this->shape($index) . '-' . $level->name . ' masks both copies alike',
                );
            }
        }
    }

    /**
     * Timing patterns run the whole way along all four edges.
     *
     * QR puts its two timing patterns inside the symbol; rMQR puts four around
     * the outside, which is what lets a scanner count modules across a
     * hundred-and-thirty-nine-module row it cannot see the middle of. The
     * finder and sub-finder corners are excluded because they are patterns in
     * their own right.
     */
    #[DataProvider('sizeProvider')]
    public function testTheEdgesAreTimingPatterns(int $index): void
    {
        $modules = $this->modules($index, ErrorCorrectionLevel::Medium);
        $height = Specs::height($index);
        $width = Specs::width($index);

        for ($column = 8; $column < $width - 5; $column++) {
            // The alignment patterns break the top and bottom rows: their
            // three-by-three rings are dark right across, which is what makes
            // them findable from the edge in the first place.
            if ($this->nearAlignment($column, $width)) {
                continue;
            }

            $this->assertSame(
                $column % 2 === 0 ? '1' : '0',
                $modules[$column],
                $this->shape($index) . " top timing at {$column}",
            );
            $this->assertSame(
                $column % 2 === 0 ? '1' : '0',
                $modules[($height - 1) * $width + $column],
                $this->shape($index) . " bottom timing at {$column}",
            );
        }

        for ($row = 8; $row < $height - 5; $row++) {
            $this->assertSame(
                $row % 2 === 0 ? '1' : '0',
                $modules[$row * $width],
                $this->shape($index) . " left timing at {$row}",
            );
        }
    }

    /**
     * The sub-finder is a five-module ring in the far corner.
     *
     * Two finders on a diagonal, not QR's three: the full seven-module one
     * top-left and this one bottom-right. Between them they are the whole of
     * the orientation story, and a symbol seven modules tall has no other
     * asymmetry to offer.
     */
    #[DataProvider('sizeProvider')]
    public function testTheOppositeCornerCarriesASubFinder(int $index): void
    {
        $modules = $this->modules($index, ErrorCorrectionLevel::High);
        $height = Specs::height($index);
        $width = Specs::width($index);

        for ($row = $height - 5; $row < $height; $row++) {
            for ($column = $width - 5; $column < $width; $column++) {
                $ring = max(abs($row - ($height - 3)), abs($column - ($width - 3)));
                $this->assertSame(
                    $ring === 1 ? '0' : '1',
                    $modules[$row * $width + $column],
                    $this->shape($index) . " sub-finder at {$row},{$column}",
                );
            }
        }
    }

    /** A split of the payload still spells the payload. */
    #[DataProvider('splittableProvider')]
    public function testASplitSpellsThePayloadBack(string $data): void
    {
        foreach (Specs::indexes() as $index) {
            $segments = Segmentation::optimal($data, $this->header($index));
            if ($segments === []) {
                continue;
            }

            $spelled = '';
            foreach ($segments as [, $chunk]) {
                $spelled .= $chunk;
            }

            $this->assertSame($data, $spelled, $this->shape($index));
        }
    }

    /**
     * Splitting never costs more than not splitting.
     *
     * The shortest path is free to cut anywhere, so it can only tie or beat
     * the single segment a naive encoder would emit. Anything else is a bug in
     * the step costs rather than a different opinion.
     */
    #[DataProvider('splittableProvider')]
    public function testSplittingNeverCostsMoreThanOneSegment(string $data): void
    {
        foreach ([0, 15, 31] as $index) {
            $single = Specs::MODE_BITS + Specs::countBits($index, Mode::Byte) + 8 * \strlen($data);

            $this->assertLessThanOrEqual(
                $single,
                Segmentation::bits($data, $this->header($index)),
                $this->shape($index) . " split of {$data}",
            );
        }
    }

    /** What canEncode() allows is what generate() produces, and no more. */
    #[DataProvider('cellProvider')]
    public function testWhatCanEncodeAllowsIsWhatGenerateProduces(
        int $index,
        ErrorCorrectionLevel $level,
    ): void {
        $generator = Defaults::registry()->getGenerator(Symbology::Rmqr->value);
        $options = new RmqrOptions($level, Version::from($index));
        $longest = str_repeat('a', $this->longestByte($index, $level));

        $this->assertTrue($generator->canEncode($longest, $options), $this->shape($index));
        $this->assertFalse($generator->canEncode($longest . 'a', $options), $this->shape($index));

        $symbol = $generator->generate($longest, $options);
        $this->assertSame(Specs::width($index), $symbol->getWidth());
        $this->assertSame(Specs::height($index), $symbol->getHeight());
    }

    /** The symbol says which shape and level it was built at. */
    public function testTheSymbolReportsWhatItWasBuiltAt(): void
    {
        $symbol = Defaults::registry()
            ->getGenerator(Symbology::Rmqr->value)
            ->generate('LOT4471', new RmqrOptions(ErrorCorrectionLevel::High, Version::R13x99));

        $this->assertSame('R13x99', $symbol->getMetadata()['version']);
        $this->assertSame('High', $symbol->getMetadata()['errorCorrection']);
        $this->assertSame(Symbology::Rmqr->value, $symbol->getMetadata()['symbology']);
        // One segment here, because a second header costs eleven bits at this
        // shape and the digits only save seven. `SN-000123` has six digits
        // rather than four and splits even at the widest shape, which is the
        // same rule read the other way.
        $this->assertSame(['Alphanumeric'], $symbol->getMetadata()['modes']);
        $this->assertSame(
            ['Alphanumeric', 'Numeric'],
            Defaults::registry()
                ->getGenerator(Symbology::Rmqr->value)
                ->generate('SN-000123', new RmqrOptions(ErrorCorrectionLevel::Medium, Version::R7x139))
                ->getMetadata()['modes'],
        );
    }

    /** An open level takes the stronger one when it fits. */
    public function testAnOpenLevelTakesTheStrongerThatFits(): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::Rmqr->value);

        $short = $generator->generate('42', new RmqrOptions(version: Version::R7x43));
        $this->assertSame('High', $short->getMetadata()['errorCorrection']);

        // Twelve digits fit R7x43 at M and not at H, so the level has to drop
        // rather than the size grow: the shape was asked for.
        $long = $generator->generate('123456789012', new RmqrOptions(version: Version::R7x43));
        $this->assertSame('Medium', $long->getMetadata()['errorCorrection']);
    }

    /** A pinned level is honoured rather than quietly weakened. */
    public function testAPinnedLevelIsNotQuietlyWeakened(): void
    {
        $symbol = Defaults::registry()
            ->getGenerator(Symbology::Rmqr->value)
            ->generate('123456789012', new RmqrOptions(ErrorCorrectionLevel::High));

        $this->assertSame('High', $symbol->getMetadata()['errorCorrection']);
    }

    /** @return iterable<string, array{string, class-string<\Throwable>}> */
    public static function badPayloadProvider(): iterable
    {
        yield 'empty' => ['', InvalidDataException::class];
        yield 'too long for the largest shape' => [str_repeat('a', 200), DataTooLargeException::class];
    }

    #[DataProvider('badPayloadProvider')]
    public function testARefusalSaysWhatIsWrong(string $data, string $expected): void
    {
        $this->expectException($expected);

        Defaults::registry()->getGenerator(Symbology::Rmqr->value)->generate($data);
    }

    /**
     * A shape that is pinned is a shape that is kept.
     *
     * Growing the symbol would be the friendly thing to do and the wrong one:
     * a caller who named a shape named it because that is the space the label
     * has, and a bigger symbol does not fit the space either.
     */
    public function testAPinnedShapeIsNotQuietlyGrown(): void
    {
        $this->expectException(DataTooLargeException::class);

        Defaults::registry()
            ->getGenerator(Symbology::Rmqr->value)
            ->generate(str_repeat('a', 6), new RmqrOptions(version: Version::R7x43));
    }

    /** @return iterable<string, array{ErrorCorrectionLevel}> */
    public static function badLevelProvider(): iterable
    {
        yield 'low' => [ErrorCorrectionLevel::Low];
        yield 'quartile' => [ErrorCorrectionLevel::Quartile];
    }

    #[DataProvider('badLevelProvider')]
    public function testTheTwoLevelsRmqrDoesNotHaveAreRefusedAtTheOptions(
        ErrorCorrectionLevel $level,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('the levels are M and H');

        new RmqrOptions($level);
    }

    /** Pinning only the shape, or only the level, is always allowed. */
    public function testPinningOneOfTheTwoIsAlwaysFine(): void
    {
        foreach (Version::cases() as $version) {
            $options = new RmqrOptions(version: $version);
            $this->assertSame($version, $options->version);
            $this->assertNull($options->errorCorrection);
        }

        foreach (Specs::levels() as $level) {
            $options = new RmqrOptions($level);
            $this->assertSame($level, $options->errorCorrection);
            $this->assertNull($options->version);
        }
    }

    /** The symbology is reachable by its name and by both aliases. */
    public function testItIsReachableByNameAndAlias(): void
    {
        $scanme = Scanme::create();

        foreach ([Symbology::Rmqr->value, 'rectangular-micro-qr', 'r-mqr'] as $name) {
            $svg = $scanme->render('LOT4471', $name, Format::Svg->value);
            $this->assertStringContainsString('<svg', $svg, $name);
        }
    }

    /** The order the encoder searches is smallest area first. */
    public function testTheSearchOrderIsSmallestAreaFirst(): void
    {
        $order = RmqrEncoder::order();

        $this->assertCount(32, $order);
        $this->assertSame(count(array_unique($order)), \count($order));

        $previous = 0;
        foreach ($order as $index) {
            $area = Specs::height($index) * Specs::width($index);
            $this->assertGreaterThanOrEqual($previous, $area, 'the order is not by area');
            $previous = $area;
        }
    }

    /** @return iterable<string, array{int}> */
    public static function sizeProvider(): iterable
    {
        foreach (Specs::indexes() as $index) {
            yield sprintf('R%dx%d', Specs::height($index), Specs::width($index)) => [$index];
        }
    }

    /** @return iterable<string, array{int, ErrorCorrectionLevel}> */
    public static function cellProvider(): iterable
    {
        foreach (Specs::indexes() as $index) {
            foreach (Specs::levels() as $level) {
                $name = sprintf('R%dx%d-%s', Specs::height($index), Specs::width($index), $level->name);
                yield $name => [$index, $level];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function splittableProvider(): iterable
    {
        foreach (['LOT4471', 'SN-000123', 'R47K 1%', 'A1B', '2026-09-03', 'ABC123DEF'] as $data) {
            yield $data => [$data];
        }
    }

    /** @return callable(Mode): ?int */
    private function header(int $index): callable
    {
        return static fn (Mode $mode): ?int => Specs::supportsMode($mode)
            ? Specs::MODE_BITS + Specs::countBits($index, $mode)
            : null;
    }

    private function longestByte(int $index, ErrorCorrectionLevel $level): int
    {
        $header = Specs::MODE_BITS + Specs::countBits($index, Mode::Byte);

        return intdiv(Specs::dataBits($index, $level) - $header, 8);
    }

    private function modules(int $index, ErrorCorrectionLevel $level): string
    {
        return Defaults::registry()
            ->getGenerator(Symbology::Rmqr->value)
            ->generate('1', new RmqrOptions($level, Version::from($index)))
            ->toModuleString();
    }

    private function nearAlignment(int $column, int $width): bool
    {
        foreach (Specs::alignment($width) as $centre) {
            if (abs($column - $centre) <= 1) {
                return true;
            }
        }

        return false;
    }

    private function shape(int $index): string
    {
        return sprintf('R%dx%d', Specs::height($index), Specs::width($index));
    }
}
