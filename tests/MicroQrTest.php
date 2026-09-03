<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Encoding\MicroQr\Layout;
use CrazyGoat\ScanMePHP\Encoding\MicroQr\Segments;
use CrazyGoat\ScanMePHP\Encoding\MicroQr\Specs;
use CrazyGoat\ScanMePHP\Encoding\Mode;
use CrazyGoat\ScanMePHP\Encoding\Segment;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;
use CrazyGoat\ScanMePHP\Exception\InvalidDataException;
use CrazyGoat\ScanMePHP\Format;
use CrazyGoat\ScanMePHP\Generator\MicroQr\MicroQrOptions;
use CrazyGoat\ScanMePHP\Generator\MicroQr\Version;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What Micro QR has to be true of itself, as opposed to what it has to agree
 * with zint about.
 *
 * The fixture next door says our symbols are the ones an independent encoder
 * draws, and the round trip says a real reader gets the payload back. Neither
 * says the tables are *coherent*: that the capacity Specs promises is the
 * number of modules Layout leaves free, that the four masks are the four the
 * standard names, that the format information is a code with the distance it
 * is supposed to have. Those are opinions this library holds on its own, so
 * they are asserted here, derived independently wherever deriving is possible.
 */
class MicroQrTest extends TestCase
{
    /**
     * The capacity in the version tables is the number of free modules.
     *
     * This is the one property that ties {@see Specs}' thirty-odd numbers to
     * {@see Layout}'s geometry, and it is not a restatement of either: the
     * tables come from ISO/IEC 18004 Table 9 and the geometry from the finder,
     * the separator, the two timing patterns and the format information. A
     * mistake in either alone breaks it. That the two agree on all eight cells
     * is why an M1 symbol is eleven modules across and holds twenty bits and
     * not sixteen or twenty-four.
     */
    #[DataProvider('cellProvider')]
    public function testTheCapacityIsTheGeometry(int $version, ?ErrorCorrectionLevel $level): void
    {
        $free = (new Layout($version))->capacity();

        $this->assertSame(
            $free,
            Specs::dataBits($version, $level) + Specs::errorCorrectionCodewords($version, $level) * 8,
            sprintf('M%d free modules against its codewords', $version),
        );
    }

    /** @return \Generator<string, array{int, ErrorCorrectionLevel|null}> */
    public static function cellProvider(): \Generator
    {
        yield 'M1' => [1, null];

        foreach ([2, 3, 4] as $version) {
            foreach (Specs::levels($version) as $level) {
                yield sprintf('M%d-%s', $version, $level->name) => [$version, $level];
            }
        }
    }

    /** Eight cells, not twelve: M1 has no level and Q exists only at M4. */
    public function testThereAreEightSymbolsAndNotTwelve(): void
    {
        $cells = iterator_to_array(self::cellProvider());

        $this->assertCount(8, $cells);
        $this->assertSame([], Specs::levels(1), 'M1 offers no level to choose');
        $this->assertSame(
            [ErrorCorrectionLevel::Low, ErrorCorrectionLevel::Medium, ErrorCorrectionLevel::Quartile],
            Specs::levels(4),
        );

        foreach (Specs::versions() as $version) {
            $this->assertNotContains(
                ErrorCorrectionLevel::High,
                Specs::levels($version),
                sprintf('M%d must not offer H, which Micro QR does not have', $version),
            );
        }
    }

    /** The sizes are 11, 13, 15 and 17 — four less than QR's smallest, and odd. */
    public function testTheSizesStepByTwoFromEleven(): void
    {
        foreach (Specs::versions() as $version) {
            $this->assertSame(11 + 2 * ($version - 1), Specs::size($version));
            $this->assertSame(Specs::size($version), Version::from($version)->size());
        }
    }

    /**
     * The four masks are QR's numbers 1, 4, 6 and 7.
     *
     * Written out here from QR's own definitions rather than copied from
     * {@see Layout::masks()}, because the renumbering is the trap: a symbol
     * masked with QR's pattern 2 and labelled 2 in its format information is
     * legal-looking and unreadable, and a test that compared the
     * implementation with itself would agree with it.
     */
    public function testTheMasksAreQrsFirstFourthSixthAndSeventh(): void
    {
        $qr = [
            1 => static fn (int $i, int $j): bool => $i % 2 === 0,
            4 => static fn (int $i, int $j): bool => (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0,
            6 => static fn (int $i, int $j): bool => ((($i * $j) % 2) + (($i * $j) % 3)) % 2 === 0,
            7 => static fn (int $i, int $j): bool => ((($i + $j) % 2) + (($i * $j) % 3)) % 2 === 0,
        ];

        foreach ([0 => 1, 1 => 4, 2 => 6, 3 => 7] as $micro => $full) {
            for ($row = 0; $row < 17; $row++) {
                for ($column = 0; $column < 17; $column++) {
                    $this->assertSame(
                        $qr[$full]($row, $column),
                        Layout::masks($micro, $row, $column),
                        sprintf('Micro QR mask %d is QR mask %d at (%d, %d)', $micro, $full, $row, $column),
                    );
                }
            }
        }
    }

    /** Masking is its own inverse, which is what lets the scoring try all four. */
    public function testMaskingTwiceLeavesTheSymbolAlone(): void
    {
        foreach (Specs::versions() as $version) {
            $layout = new Layout($version);
            $level = Specs::levels($version)[0] ?? null;
            $layout->place(array_fill(0, Specs::totalCodewords($version), 0b1011_0010), $level);
            $before = $layout->toBooleans();

            for ($mask = 0; $mask < Specs::MASKS; $mask++) {
                $layout->mask($mask);
                $layout->mask($mask);
                $this->assertSame($before, $layout->toBooleans(), "mask {$mask} at M{$version}");
            }
        }
    }

    /**
     * The thirty-two format values are a code that can be told apart.
     *
     * BCH(15,5) has a minimum Hamming distance of seven, which is the whole
     * reason the fifteen bits exist: a reader that gets three of them wrong
     * still knows which symbol number and mask it is looking at. Asserting the
     * distance is a claim about the polynomial and the XOR together, and it
     * holds without the test knowing either — a wrong generator would collapse
     * pairs, and a wrong XOR would not, which is why the next test exists too.
     */
    public function testTheFormatInformationIsSevenBitsApart(): void
    {
        $values = [];
        for ($number = 0; $number < 8; $number++) {
            for ($mask = 0; $mask < Specs::MASKS; $mask++) {
                $values[] = Layout::format($number, $mask);
            }
        }

        $this->assertCount(32, array_unique($values));

        $closest = 15;
        foreach ($values as $i => $first) {
            $this->assertLessThan(1 << 15, $first, 'a format value is fifteen bits');

            foreach (\array_slice($values, $i + 1) as $second) {
                $closest = min($closest, substr_count(decbin($first ^ $second), '1'));
            }
        }

        $this->assertSame(7, $closest, 'the minimum distance BCH(15,5) is supposed to have');
    }

    /**
     * The all-zero symbol number at the all-zero mask is not the all-zero
     * format.
     *
     * That is what the XOR constant is for, and Micro QR's is 0x4445 where
     * QR's is 0x5412. Reusing QR's would give symbols that look perfectly
     * well-formed and announce the wrong version to every reader, so the
     * constant is checked against the one number that pins it.
     */
    public function testTheFormatMaskIsMicroQrsAndNotQrs(): void
    {
        $this->assertSame(0b100_0100_0100_0101, Layout::format(0, 0));
        $this->assertNotSame(0b101_0100_0001_0010, Layout::format(0, 0));
    }

    /** Timing runs along the top and left edges, dark on the even index. */
    public function testTheTimingPatternsRunAwayFromTheFinder(): void
    {
        foreach (Specs::versions() as $version) {
            $symbol = $this->encode('1', new MicroQrOptions(version: $version === 1 ? Version::M1 : Version::from($version)));
            $size = Specs::size($version);
            $modules = $symbol->toModuleString();

            for ($i = 8; $i < $size; $i++) {
                $this->assertSame($i % 2 === 0, $modules[$i] === '1', "top timing at {$i}, M{$version}");
                $this->assertSame($i % 2 === 0, $modules[$i * $size] === '1', "left timing at {$i}, M{$version}");
            }
        }
    }

    /**
     * One finder, and a two-module quiet zone rather than QR's four.
     *
     * The quiet zone is half the reason to reach for Micro QR at all: an M1
     * symbol is fifteen modules across once it is drawn, against the
     * twenty-nine a version 1 QR symbol needs, and four of those fourteen
     * saved modules are margin.
     */
    public function testTheSymbolIsOneFinderAndATwoModuleMargin(): void
    {
        $symbol = $this->encode('12345');

        $this->assertCount(1, $symbol->getFinderRegions());
        $region = $symbol->getFinderRegions()[0];
        $this->assertSame([0, 0, 7, 7], [$region->x, $region->y, $region->width, $region->height]);

        $quiet = $symbol->getQuietZone();
        $this->assertSame(2, $quiet->top);
        $this->assertSame(2, $quiet->right);
        $this->assertSame(2, $quiet->bottom);
        $this->assertSame(2, $quiet->left);
    }

    /**
     * A split always spells the payload back.
     *
     * The search in {@see Segments} is free to cut the payload anywhere, and
     * the one thing it may never do is lose or reorder a character.
     */
    #[DataProvider('payloadProvider')]
    public function testASplitSpellsThePayloadBack(string $data): void
    {
        foreach (Specs::versions() as $version) {
            $segments = Segments::optimal($data, $version);
            if ($segments === []) {
                continue;
            }

            $this->assertSame(
                $data,
                implode('', array_column($segments, 1)),
                sprintf('M%d segments of %s', $version, $data),
            );

            foreach ($segments as [$mode, $payload]) {
                $this->assertTrue(
                    Specs::supportsMode($version, $mode),
                    sprintf('M%d cannot be in %s mode', $version, $mode->name),
                );
            }
        }
    }

    /**
     * Splitting never costs more than not splitting.
     *
     * The reason to segment at all is to save bits, so a search that came out
     * longer than the obvious single segment would be worse than no search.
     * This is the assertion that would catch it, and it is checked against a
     * cost computed here from the mode and count widths rather than against
     * {@see Segments::bits()}.
     */
    #[DataProvider('payloadProvider')]
    public function testSplittingNeverCostsMoreThanOneSegment(string $data): void
    {
        foreach (Specs::versions() as $version) {
            $single = $this->singleSegmentBits($data, $version);
            if ($single === null) {
                continue;
            }

            $this->assertLessThanOrEqual(
                $single,
                Segments::bits($data, $version),
                sprintf('M%d split of %s against one segment', $version, $data),
            );
        }
    }

    /** @return \Generator<string, array{string}> */
    public static function payloadProvider(): \Generator
    {
        $payloads = [
            '1', '12345', '0123456789', 'A', 'HELLO', 'LOT4471', 'SN-000123',
            'R47K 1%', 'a.co/x8Kd', '6LTx', 'A1B', '4/06/94804273B-33',
            'ABC123DEF', '1A', 'A1', '   ', '::::', 'aB3', "\x00\xff",
        ];

        foreach ($payloads as $data) {
            yield addcslashes($data, "\0..\37\177..\377") => [$data];
        }
    }

    /** A payload of digits alone is one numeric segment and nothing cleverer. */
    public function testDigitsAloneAreOneNumericSegment(): void
    {
        foreach (Specs::versions() as $version) {
            $segments = Segments::optimal('1234567', $version);

            $this->assertCount(1, $segments, "M{$version}");
            $this->assertSame(Mode::Numeric, $segments[0][0]);
        }
    }

    /**
     * A version's modes are the ones the standard gives it.
     *
     * M1 is digits and nothing else, M2 adds alphanumeric, and bytes need M3.
     * That is why `canEncode` can answer false for a three-character payload:
     * `abc` is not too long for M2, it is not expressible in it.
     */
    public function testTheSmallVersionsCannotCarryBytes(): void
    {
        $this->assertSame([Mode::Numeric], Specs::modes(1));
        $this->assertSame([Mode::Numeric, Mode::Alphanumeric], Specs::modes(2));
        $this->assertContains(Mode::Byte, Specs::modes(3));
        $this->assertContains(Mode::Byte, Specs::modes(4));

        $this->assertSame([], Segments::optimal('abc', 2), 'M2 has no byte mode');
        $this->assertSame(\PHP_INT_MAX, Segments::bits('abc', 1));
    }

    /**
     * canEncode and generate agree.
     *
     * A generator that says yes and then throws is worse than one that says no,
     * because the caller has already committed by the time it finds out.
     */
    #[DataProvider('payloadProvider')]
    public function testWhatCanEncodeAllowsIsWhatGenerateProduces(string $data): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::MicroQr->value);

        foreach ([null, Version::M1, Version::M2, Version::M3, Version::M4] as $version) {
            $options = new MicroQrOptions(version: $version);
            $allowed = $generator->canEncode($data, $options);

            try {
                $generator->generate($data, $options);
                $produced = true;
            } catch (\Exception) {
                $produced = false;
            }

            $this->assertSame(
                $allowed,
                $produced,
                sprintf(
                    '%s at %s',
                    addcslashes($data, "\0..\37\177..\377"),
                    $version === null ? 'any version' : $version->name,
                ),
            );
        }
    }

    /** The version and level a symbol reports are the ones it was built at. */
    #[DataProvider('cellProvider')]
    public function testTheSymbolReportsWhatItWasBuiltAt(int $version, ?ErrorCorrectionLevel $level): void
    {
        $symbol = $this->encode('1', new MicroQrOptions($level, Version::from($version)));
        $metadata = $symbol->getMetadata();

        $this->assertSame(Symbology::MicroQr->value, $metadata['symbology']);
        $this->assertSame("M{$version}", $metadata['version']);
        $this->assertSame($level?->name, $metadata['errorCorrection']);
        $this->assertSame(Specs::size($version), $symbol->getWidth());
        $this->assertContains($metadata['mask'], range(0, 3));
        $this->assertSame(['Numeric'], $metadata['modes']);
    }

    /**
     * Leaving the level open takes the strongest one that still fits.
     *
     * The capacity is spent either way — a symbol has the modules it has — so
     * the only question is whether the spare room goes to recovery data or to
     * padding, and recovery data is free. This is also what the encoders this
     * library is checked against do, which is why a symbol built with the
     * defaults is the one a reader expects.
     */
    public function testAnOpenLevelTakesTheStrongestThatFits(): void
    {
        // Nine digits: M2-M holds eight and M2-L holds ten, so the answer is
        // M2 at L, and going to M3 for a stronger level would be wrong.
        $this->assertSame(
            ['M2', 'Low'],
            $this->describe($this->encode('123456789')),
        );

        // Five alphanumeric characters fit M2 at either level.
        $this->assertSame(['M2', 'Medium'], $this->describe($this->encode('HELLO')));

        // Nine bytes need M3, where M is the strongest that holds seven — so
        // this is M3 at L rather than M4 at Q.
        $this->assertSame(['M3', 'Low'], $this->describe($this->encode('abcdefghi')));
    }

    /** A pinned level is honoured even when it costs a larger symbol. */
    public function testAPinnedLevelIsNotQuietlyWeakened(): void
    {
        $this->assertSame(
            ['M4', 'Quartile'],
            $this->describe($this->encode('HELLO', new MicroQrOptions(ErrorCorrectionLevel::Quartile))),
        );
    }

    /** @return array{string, string|null} */
    private function describe(\CrazyGoat\ScanMePHP\Symbol $symbol): array
    {
        return [$symbol->getMetadata()['version'], $symbol->getMetadata()['errorCorrection']];
    }

    #[DataProvider('badPayloadProvider')]
    public function testAPayloadThatDoesNotFitIsRefused(string $data, ?MicroQrOptions $options, string $expected): void
    {
        $this->expectException($expected);
        $this->encode($data, $options);
    }

    /** @return \Generator<string, array{string, MicroQrOptions|null, class-string}> */
    public static function badPayloadProvider(): \Generator
    {
        yield 'nothing at all' => ['', null, InvalidDataException::class];
        yield 'a byte where M1 takes digits' => ['a', new MicroQrOptions(version: Version::M1), InvalidDataException::class];
        yield 'a byte where M2 takes alphanumerics' => ['abc', new MicroQrOptions(version: Version::M2), InvalidDataException::class];
        yield 'a letter at M1' => ['A', new MicroQrOptions(version: Version::M1), InvalidDataException::class];
        yield 'six digits at M1' => ['123456', new MicroQrOptions(version: Version::M1), DataTooLargeException::class];
        yield 'sixteen bytes anywhere' => [str_repeat('a', 16), null, DataTooLargeException::class];
        yield 'thirty-six digits anywhere' => [str_repeat('1', 36), null, DataTooLargeException::class];
        yield 'ten bytes at M3-L' => [
            str_repeat('a', 10),
            new MicroQrOptions(ErrorCorrectionLevel::Low, Version::M3),
            DataTooLargeException::class,
        ];
    }

    /**
     * A refusal says which of the two things went wrong.
     *
     * "Too long" and "this version has no mode for these characters" are
     * different problems with different fixes, and reporting the second as the
     * first sends a caller off to shorten a payload that no length would save.
     */
    public function testARefusalSaysWhetherItIsLengthOrAlphabet(): void
    {
        try {
            $this->encode('abc', new MicroQrOptions(version: Version::M2));
            $this->fail('M2 has no byte mode');
        } catch (InvalidDataException $e) {
            $this->assertStringContainsString('M2', $e->getMessage());
        }

        try {
            $this->encode('123456', new MicroQrOptions(version: Version::M1));
            $this->fail('M1 holds five digits');
        } catch (DataTooLargeException $e) {
            $this->assertStringContainsString('bits', $e->getMessage());
            $this->assertStringContainsString('M1', $e->getMessage());
        }
    }

    #[DataProvider('badOptionProvider')]
    public function testAnImpossibleOptionIsRefusedWhenItIsWrittenDown(
        ?ErrorCorrectionLevel $level,
        ?Version $version,
        ?int $mask,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        new MicroQrOptions($level, $version, $mask);
    }

    /** @return \Generator<string, array{ErrorCorrectionLevel|null, Version|null, int|null}> */
    public static function badOptionProvider(): \Generator
    {
        yield 'level H, which Micro QR does not have' => [ErrorCorrectionLevel::High, null, null];
        yield 'a level at M1, which has none' => [ErrorCorrectionLevel::Low, Version::M1, null];
        yield 'Q at M2' => [ErrorCorrectionLevel::Quartile, Version::M2, null];
        yield 'Q at M3' => [ErrorCorrectionLevel::Quartile, Version::M3, null];
        yield 'mask 4, which is QR-shaped' => [null, null, 4];
        yield 'mask -1' => [null, null, -1];
    }

    /** Pinning only the size, or only the level, is always allowed. */
    public function testPinningOneOfTheTwoIsAlwaysFine(): void
    {
        foreach (Version::cases() as $version) {
            $this->assertInstanceOf(MicroQrOptions::class, new MicroQrOptions(version: $version));
        }

        foreach ([ErrorCorrectionLevel::Low, ErrorCorrectionLevel::Medium, ErrorCorrectionLevel::Quartile] as $level) {
            $this->assertInstanceOf(MicroQrOptions::class, new MicroQrOptions($level));
        }
    }

    /** The symbology is registered under its name and its aliases. */
    public function testItIsReachableByNameAndAlias(): void
    {
        foreach ([Symbology::MicroQr->value, 'microqr', 'micro-qrcode'] as $name) {
            $this->assertNotSame(
                '',
                Scanme::create()->render('12345', $name, Format::Svg),
                "reachable as {$name}",
            );
        }
    }

    /** The bits one segment of $data costs at $version, or null if impossible. */
    private function singleSegmentBits(string $data, int $version): ?int
    {
        $mode = match (true) {
            Segment::isNumeric($data) => Mode::Numeric,
            Segment::isAlphanumeric($data) => Mode::Alphanumeric,
            default => Mode::Byte,
        };

        if (!Specs::supportsMode($version, $mode)) {
            return null;
        }

        return Specs::modeBits($version)
            + Specs::countBits($version, $mode)
            + match ($mode) {
                Mode::Numeric => Segment::numericBits(\strlen($data)),
                Mode::Alphanumeric => Segment::alphanumericBits(\strlen($data)),
                default => Segment::byteBits(\strlen($data)),
            };
    }

    private function encode(string $data, ?MicroQrOptions $options = null): \CrazyGoat\ScanMePHP\Symbol
    {
        return Defaults::registry()
            ->getGenerator(Symbology::MicroQr->value)
            ->generate($data, $options);
    }
}
