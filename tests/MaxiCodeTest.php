<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoding\MaxiCode\CodeSets;
use CrazyGoat\ScanMePHP\Encoding\MaxiCode\HighLevelEncoder;
use CrazyGoat\ScanMePHP\Encoding\MaxiCode\MaxiCodeEncoder;
use CrazyGoat\ScanMePHP\Encoding\MaxiCode\Mode;
use CrazyGoat\ScanMePHP\Encoding\MaxiCode\Specs;
use CrazyGoat\ScanMePHP\Encoding\MaxiCode\StructuredCarrierMessage;
use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\MaxiCode\MaxiCodeOptions;
use CrazyGoat\ScanMePHP\Generator\MaxiCode\MaxiCodeSymbols;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\RegionRole;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MaxiCode's own arithmetic, and the parts of it no oracle can reach.
 *
 * The module-for-module comparison lives in MaxiCodeReferenceTest and covers
 * the plain mode. What is here is everything that comparison cannot see: the
 * code set tables as a whole, the structured carrier message's bit packing, the
 * capacity that the two structured modes give up, and the option bag's refusals.
 */
class MaxiCodeTest extends TestCase
{
    private Scanme $scanme;

    protected function setUp(): void
    {
        $this->scanme = Scanme::create();
    }

    public function testItIsRegisteredUnderItsNameAndAliases(): void
    {
        foreach (['maxicode', 'maxi-code', 'ups-code'] as $name) {
            $symbol = $this->scanme->generate('HELLO', $name);
            $this->assertSame(Symbology::MaxiCode->value, $symbol->getMetadata()['symbology'], $name);
        }
    }

    /**
     * One size, always, which is the thing about MaxiCode most worth pinning:
     * no version, no layer count, no column count, and therefore no search.
     */
    public function testEverySymbolIsTheSameSize(): void
    {
        foreach (['A', 'HELLO WORLD', str_repeat('A', 93), "\x00\xFF"] as $payload) {
            $symbol = $this->scanme->generate($payload, Symbology::MaxiCode);

            $this->assertSame(Specs::COLUMNS, $symbol->getWidth());
            $this->assertSame(Specs::ROWS, $symbol->getHeight());
            $this->assertSame(ModuleShape::Hexagon, $symbol->getModuleShape());
        }
    }

    /**
     * The odd rows are one hexagon shorter, and Symbol stores a rectangle, so
     * the last column of every odd row is a module that does not exist. It has
     * to be light in every symbol, whatever the payload.
     */
    public function testTheColumnOddRowsDoNotHaveIsAlwaysLight(): void
    {
        foreach (['A', str_repeat('Z', 93), "\xC0\xE0\x01"] as $payload) {
            $rows = $this->scanme->generate($payload, Symbology::MaxiCode)->rows();

            for ($row = 1; $row < Specs::ROWS; $row += 2) {
                $this->assertSame(
                    '0',
                    $rows[$row][Specs::COLUMNS - 1],
                    sprintf('row %d has a dark module in a column it does not have', $row)
                );
            }
        }
    }

    public function testTheBullseyeIsReportedAsAFinderRegion(): void
    {
        $symbol = $this->scanme->generate('HELLO', Symbology::MaxiCode);
        $regions = $symbol->getFinderRegions();

        $this->assertCount(1, $regions, 'one bullseye, not three corner patterns');
        $region = $regions[0];

        $this->assertSame(
            Specs::BULLSEYE_COLUMN,
            $region->x + intdiv($region->width - 1, 2),
            'the region has to be centred on the bullseye module'
        );
        $this->assertSame(Specs::BULLSEYE_ROW, $region->y + intdiv($region->height - 1, 2));
        $this->assertSame(1, $region->width % 2, 'an even width would put the centre between two modules');
        $this->assertSame(1, $region->height % 2);

        // Unlike QR's corner patterns, this one is not a styling hint: the grid
        // is blank where the rings go, so a renderer that ignores it draws a
        // symbol with a hole in the middle.
        $this->assertSame(RegionRole::RendererDrawn, $region->role);
    }

    /** The area the rings cover carries no modules, so it is blank in the grid. */
    public function testTheBullseyeRegionHoldsNoModules(): void
    {
        $symbol = $this->scanme->generate(str_repeat('W', 93), Symbology::MaxiCode);
        $region = $symbol->getFinderRegions()[0];
        $rows = $symbol->rows();

        $dark = 0;
        for ($row = $region->y + 2; $row < $region->y + $region->height - 2; $row++) {
            for ($column = $region->x + 1; $column < $region->x + $region->width - 1; $column++) {
                $dark += $rows[$row][$column] === '1' ? 1 : 0;
            }
        }

        $this->assertSame(0, $dark, 'the rings are drawn by the renderer, not written as modules');
    }

    public function testThePlainModeHoldsNinetyThreeCodewordsAndAStructuredOneEightyFour(): void
    {
        $this->assertSame(93, Mode::Standard->capacity());
        $this->assertSame(93, Mode::ReaderProgramming->capacity());
        $this->assertSame(84, Mode::NumericPostcode->capacity());
        $this->assertSame(84, Mode::AlphanumericPostcode->capacity());

        $this->assertSame(Specs::DATA_CODEWORDS, Mode::Standard->capacity());
        $this->assertSame(
            Specs::DATA_CODEWORDS - (Specs::PRIMARY_CODEWORDS - 1),
            Mode::NumericPostcode->capacity(),
            'the structured modes give up exactly the primary message\'s data codewords'
        );
    }

    public function testAPayloadPastTheCapacityIsRefused(): void
    {
        $this->expectException(DataTooLargeException::class);
        $this->expectExceptionMessage('holds 93 codewords');

        (new MaxiCodeEncoder())->encode(str_repeat('A', 94));
    }

    /**
     * The two structured modes refuse nine codewords sooner, because that is
     * what their routing block costs. Ninety characters is a payload that fits
     * one way and not the other.
     */
    public function testAStructuredModeRefusesSoonerThanThePlainOne(): void
    {
        $ninety = str_repeat('A', 90);
        $options = new MaxiCodeOptions(Mode::NumericPostcode, '12345', 826, 1);

        $this->scanme->generate($ninety, Symbology::MaxiCode);

        $this->expectException(DataTooLargeException::class);
        $this->expectExceptionMessage('holds 84 codewords');

        (new MaxiCodeEncoder())->encode($ninety, Mode::NumericPostcode, $options->primaryMessage());
    }

    /** The facade reports the same refusal in its own words. */
    public function testTheFacadeSaysWhyItCannotEncode(): void
    {
        $this->expectException(UnsupportedDataException::class);
        $this->expectExceptionMessage('up to 93 codewords');

        $this->scanme->generate(str_repeat('A', 94), Symbology::MaxiCode);
    }

    public function testCanEncodeAgreesWithWhatGenerateDoes(): void
    {
        $generator = $this->scanme->getRegistry()->getGenerator(Symbology::MaxiCode);
        $structured = new MaxiCodeOptions(Mode::NumericPostcode, '12345', 826, 1);

        $this->assertTrue($generator->canEncode(str_repeat('A', 93)));
        $this->assertFalse($generator->canEncode(str_repeat('A', 94)));
        $this->assertTrue($generator->canEncode(str_repeat('A', 84), $structured));
        $this->assertFalse($generator->canEncode(str_repeat('A', 85), $structured));
        $this->assertFalse($generator->canEncode(''));
    }

    /**
     * Nine digits and only nine compact, which is what makes the move
     * all-or-nothing: eight digits cost eight codewords, nine cost six.
     */
    #[DataProvider('numericProvider')]
    public function testNumericCompactionTakesNineDigitsAtATime(string $digits, int $codewords): void
    {
        $this->assertCount($codewords, (new HighLevelEncoder())->encode($digits)['codewords']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function numericProvider(): iterable
    {
        yield 'eight digits, no compaction' => ['12345678', 8];
        yield 'nine digits, one group' => ['123456789', 6];
        yield 'ten digits' => ['1234567890', 7];
        yield 'seventeen digits' => [str_repeat('7', 17), 14];
        yield 'eighteen digits, two groups' => [str_repeat('7', 18), 12];
        yield 'ninety digits, ten groups' => [str_repeat('1', 90), 60];
    }

    /**
     * The nine digits are one number written base 64, most significant codeword
     * first, so leading zeros are part of the value rather than lost.
     */
    public function testTheNumericGroupIsOneNumberInFiveCodewords(): void
    {
        $encoder = new HighLevelEncoder();

        $this->assertSame(
            [CodeSets::NUMERIC_LATCH, 0, 0, 0, 0, 1],
            $encoder->encode('000000001')['codewords']
        );
        $this->assertSame(
            [CodeSets::NUMERIC_LATCH, 6, 39, 54, 47, 7],
            $encoder->encode('111111111')['codewords']
        );
        $this->assertSame(
            [CodeSets::NUMERIC_LATCH, 59, 38, 44, 39, 63],
            $encoder->encode('999999999')['codewords'],
            '999999999 is the largest nine-digit number and still under 2^30'
        );
    }

    /**
     * Between them the five sets carry every byte, which is what lets MaxiCode
     * hold binary without a binary mode — and the property that would break
     * silently if a table lost an entry.
     */
    public function testEveryByteBelongsToSomeCodeSet(): void
    {
        for ($byte = 0; $byte < 256; $byte++) {
            $this->assertNotSame(
                [],
                CodeSets::sets(\chr($byte)),
                sprintf('no code set carries 0x%02X', $byte)
            );
        }
    }

    /**
     * C, D and E have no shift into A or B. That asymmetry is the reason a
     * single capital letter costs four codewords from set C and two from set B,
     * and an encoder that assumes shifts are symmetric emits a symbol that
     * reads back as something else.
     */
    public function testOnlyTheSetsThatHaveAShiftReportOne(): void
    {
        $this->assertSame(CodeSets::SHIFT_B, CodeSets::shift(CodeSets::A, CodeSets::B));
        $this->assertSame(CodeSets::SHIFT_A, CodeSets::shift(CodeSets::B, CodeSets::A));

        foreach ([CodeSets::C, CodeSets::D, CodeSets::E] as $set) {
            $this->assertNull(CodeSets::shift($set, CodeSets::A), 'no shift into A');
            $this->assertNull(CodeSets::shift($set, CodeSets::B), 'no shift into B');
            $this->assertNull(CodeSets::shift($set, $set), 'no shift into itself');
        }

        foreach (range(0, CodeSets::COUNT - 1) as $from) {
            foreach ([CodeSets::C, CodeSets::D, CodeSets::E] as $to) {
                if ($from === $to) {
                    continue;
                }
                $this->assertSame([CodeSets::SHIFT[$to], CodeSets::SHIFT[$to]], CodeSets::latch($from, $to));
            }
        }
    }

    /**
     * Only three of the five sets have a pad codeword. In C and D the value
     * that pads elsewhere is a printable character, so a stream ending in one
     * has to latch to A before it can be filled — and that latch takes one of
     * the slots being filled.
     */
    public function testPaddingFromASetWithNoPadCostsALatch(): void
    {
        $this->assertSame(33, CodeSets::pad(CodeSets::A));
        $this->assertSame(33, CodeSets::pad(CodeSets::B));
        $this->assertNull(CodeSets::pad(CodeSets::C));
        $this->assertNull(CodeSets::pad(CodeSets::D));
        $this->assertSame(28, CodeSets::pad(CodeSets::E));

        $this->assertSame(0xDC, CodeSets::character(CodeSets::C, 33), 'in set C, 33 is a printable character');
    }

    /**
     * The structured carrier message's first two bits ride in the mode
     * codeword's spare high bits. Treating that codeword as six bits of mode
     * loses the postcode's two least significant bits with no other symptom,
     * which is why it gets a test of its own.
     */
    public function testThePostcodeStartsInsideTheModeCodeword(): void
    {
        $zero = StructuredCarrierMessage::primary(Mode::NumericPostcode, '0', 0, 0);
        $this->assertSame(Mode::NumericPostcode->value, $zero[0]);

        $three = StructuredCarrierMessage::primary(Mode::NumericPostcode, '3', 0, 0);
        $this->assertSame(
            Mode::NumericPostcode->value | 3 << 4,
            $three[0],
            'a postcode of 3 sets both of the mode codeword\'s spare bits'
        );
        $this->assertSame(\array_slice($zero, 1), \array_slice($three, 1), 'and changes nothing else');

        $four = StructuredCarrierMessage::primary(Mode::NumericPostcode, '4', 0, 0);
        $this->assertSame(Mode::NumericPostcode->value, $four[0]);
        $this->assertSame(1, $four[1], 'the third bit of the postcode is the first data codeword\'s');
    }

    public function testTheCarrierMessageIsTenCodewords(): void
    {
        foreach ([Mode::NumericPostcode, Mode::AlphanumericPostcode] as $mode) {
            $primary = StructuredCarrierMessage::primary($mode, $mode === Mode::NumericPostcode ? '12345' : 'AB1CD', 826, 1);

            $this->assertCount(Specs::PRIMARY_CODEWORDS, $primary);
            foreach ($primary as $codeword) {
                $this->assertGreaterThanOrEqual(0, $codeword);
                $this->assertLessThan(Specs::CODEWORD_VALUES, $codeword);
            }
        }
    }

    #[DataProvider('badOptionProvider')]
    public function testAnImpossibleOptionBagIsRefused(callable $build, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($message);

        $build();
    }

    /** @return iterable<string, array{callable, string}> */
    public static function badOptionProvider(): iterable
    {
        yield 'a postcode in the plain mode' => [
            static fn (): MaxiCodeOptions => new MaxiCodeOptions(Mode::Standard, '12345'),
            '/no room for a postcode/',
        ];
        yield 'a structured mode with no postcode' => [
            static fn (): MaxiCodeOptions => new MaxiCodeOptions(Mode::NumericPostcode, '', 826, 1),
            '/needs a postcode/',
        ];
        yield 'letters in a numeric postcode' => [
            static fn (): MaxiCodeOptions => new MaxiCodeOptions(Mode::NumericPostcode, 'AB123', 826, 1),
            '/digits only/',
        ];
        yield 'ten digits of postcode' => [
            static fn (): MaxiCodeOptions => new MaxiCodeOptions(Mode::NumericPostcode, '1234567890', 826, 1),
            '/at most 9 digits/',
        ];
        yield 'seven characters of postcode' => [
            static fn (): MaxiCodeOptions => new MaxiCodeOptions(Mode::AlphanumericPostcode, 'ABCDEFG', 826, 1),
            '/at most 6 characters/',
        ];
        yield 'a postcode character no code set A holds' => [
            static fn (): MaxiCodeOptions => new MaxiCodeOptions(Mode::AlphanumericPostcode, 'ab1', 826, 1),
            '/only code set A characters/',
        ];
        yield 'a four-digit country code' => [
            static fn (): MaxiCodeOptions => new MaxiCodeOptions(Mode::NumericPostcode, '123', 1000, 1),
            '/country code is a three-digit number/',
        ];
        yield 'a negative service class' => [
            static fn (): MaxiCodeOptions => new MaxiCodeOptions(Mode::NumericPostcode, '123', 826, -1),
            '/service class code is a three-digit number/',
        ];
    }

    public function testACarrierMessageWithoutAStructuredModeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('has no structured carrier message');

        StructuredCarrierMessage::primary(Mode::Standard, '123', 826, 1);
    }

    public function testTheEncoderRefusesAModeAndCarrierMessageThatDisagree(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A structured mode needs a carrier message');

        (new MaxiCodeEncoder())->encode('HELLO', Mode::NumericPostcode);
    }

    public function testTheMetadataSaysWhatWasEncoded(): void
    {
        $metadata = $this->scanme->generate('HELLO', Symbology::MaxiCode)->getMetadata();

        $this->assertSame(Mode::Standard->value, $metadata['mode']);
        $this->assertSame(5, $metadata['dataCodewords']);
        $this->assertSame(88, $metadata['padCodewords']);
        $this->assertSame(
            Specs::DATA_CODEWORDS,
            $metadata['dataCodewords'] + $metadata['padCodewords'],
            'the symbol is always full'
        );
    }

    public function testTheQuietZoneIsTheOneModuleTheStandardRequires(): void
    {
        $quietZone = $this->scanme->generate('HELLO', Symbology::MaxiCode)->getQuietZone();

        $this->assertSame(MaxiCodeSymbols::QUIET_ZONE, $quietZone->left);
        $this->assertSame(MaxiCodeSymbols::QUIET_ZONE, $quietZone->right);
        $this->assertSame(MaxiCodeSymbols::QUIET_ZONE, $quietZone->top);
        $this->assertSame(MaxiCodeSymbols::QUIET_ZONE, $quietZone->bottom);
    }
}
