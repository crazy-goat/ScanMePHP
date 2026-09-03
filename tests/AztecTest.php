<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Encoding\Aztec\AztecEncoder;
use CrazyGoat\ScanMePHP\Encoding\Aztec\CharacterModes;
use CrazyGoat\ScanMePHP\Encoding\Aztec\HighLevelEncoder;
use CrazyGoat\ScanMePHP\Encoding\Aztec\ReedSolomonGf2m;
use CrazyGoat\ScanMePHP\Encoding\Aztec\Specs;
use CrazyGoat\ScanMePHP\Encoding\ReedSolomon256;
use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\Aztec\AztecOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AztecTest extends TestCase
{
    public function testItIsRegisteredUnderItsNameAndAliases(): void
    {
        $registry = Defaults::registry();

        foreach ([Symbology::Aztec->value, 'aztec-code', 'azteccode'] as $name) {
            $this->assertSame(
                'Aztec Code',
                $registry->getGenerator($name)->getCapabilities()->title,
                $name
            );
        }
    }

    public function testItReportsItselfAsAMatrixWithNoErrorCorrectionLevels(): void
    {
        $capabilities = Defaults::registry()->getGenerator(Symbology::Aztec->value)->getCapabilities();

        $this->assertSame(Dimension::Matrix, $capabilities->dimension);
        $this->assertSame([], $capabilities->errorCorrectionLevels, 'Aztec takes a percentage, not a level');
        $this->assertSame(AztecOptions::class, $capabilities->optionsClass);
        $this->assertFalse($capabilities->providesText);
    }

    public function testTheSymbolReportsItsLayersAndWordCounts(): void
    {
        $symbol = Scanme::create()->generate('HELLO', Symbology::Aztec);
        $metadata = $symbol->getMetadata();

        $this->assertSame(Symbology::Aztec->value, $metadata['symbology']);
        $this->assertSame(1, $metadata['layers']);
        $this->assertTrue($metadata['compact']);
        $this->assertSame(17, $metadata['totalWords']);
        $this->assertSame(5, $metadata['dataWords']);
        $this->assertSame(15, $symbol->getWidth());
    }

    /**
     * The bullseye is one region in the middle, where QR reports three in the
     * corners. A renderer that styles finders has to cope with both.
     */
    public function testTheBullseyeIsReportedAsASingleCentralRegion(): void
    {
        foreach ([['HELLO', 9], [str_repeat('A', 200), 13]] as [$data, $finder]) {
            $symbol = Scanme::create()->generate($data, Symbology::Aztec);
            $regions = $symbol->getFinderRegions();

            $this->assertCount(1, $regions);
            $this->assertSame($finder, $regions[0]->width);
            $this->assertSame($finder, $regions[0]->height);
            $this->assertSame(intdiv($symbol->getWidth() - $finder, 2), $regions[0]->x);
            $this->assertSame($regions[0]->x, $regions[0]->y, 'the bullseye is centred');
        }
    }

    /**
     * ISO/IEC 24778 requires no quiet zone, which is a real part of why Aztec
     * gets chosen. Declaring one anyway would make every symbol bigger than it
     * needs to be for no gain.
     */
    public function testItAsksForNoQuietZone(): void
    {
        $quietZone = Scanme::create()->generate('HELLO', Symbology::Aztec)->getQuietZone();

        $this->assertSame(0, $quietZone->top);
        $this->assertSame(0, $quietZone->right);
        $this->assertSame(0, $quietZone->bottom);
        $this->assertSame(0, $quietZone->left);
    }

    public function testAskingForMoreErrorCorrectionCostsALargerSymbol(): void
    {
        $create = static fn (int $percent): int => Scanme::create()
            ->generate(str_repeat('A', 80), Symbology::Aztec, new AztecOptions(errorCorrectionPercent: $percent))
            ->getWidth();

        $small = $create(10);
        $large = $create(80);

        $this->assertGreaterThan($small, $large, 'more recovery data has to go somewhere');
    }

    /**
     * The percentage is a floor, not a target. The smallest symbol that holds
     * five characters has room to spare and all of it becomes error correction,
     * so asking for a little and asking for a lot give the same symbol.
     */
    public function testASmallPayloadOvershootsWhateverPercentageIsAskedFor(): void
    {
        $five = Scanme::create()->generate('HELLO', Symbology::Aztec, new AztecOptions(errorCorrectionPercent: 5));
        $forty = Scanme::create()->generate('HELLO', Symbology::Aztec, new AztecOptions(errorCorrectionPercent: 40));

        $this->assertSame($five->toModuleString(), $forty->toModuleString());
        $this->assertSame(12, $five->getMetadata()['totalWords'] - $five->getMetadata()['dataWords']);
    }

    public function testASizeCanBePinned(): void
    {
        $symbol = Scanme::create()->generate('HELLO', Symbology::Aztec, new AztecOptions(size: 45));

        $this->assertSame(45, $symbol->getWidth());
        $this->assertSame(7, $symbol->getMetadata()['layers']);
        $this->assertFalse($symbol->getMetadata()['compact']);
    }

    public function testAPinnedSizeTooSmallForTheDataIsRefused(): void
    {
        $this->expectException(UnsupportedDataException::class);

        Scanme::create()->generate(str_repeat('A', 100), Symbology::Aztec, new AztecOptions(size: 15));
    }

    /**
     * The facade turns a pinned size away through canEncode, but canEncode only
     * knows the bit count before stuffing and stuffing can only add bits. So a
     * payload can pass the gate and still not fit, and the encoder has to say
     * so itself rather than write a symbol that is quietly wrong.
     */
    public function testTheEncoderReportsAShortfallTheGateCannotSee(): void
    {
        $this->expectException(DataTooLargeException::class);
        $this->expectExceptionMessageMatches('/compact Aztec symbol of 1 layers/');

        (new AztecEncoder())->encode(str_repeat('A', 100), 33, 15);
    }

    /** @return \Generator<string, array{int}> */
    public static function badSizeProvider(): \Generator
    {
        yield 'below the smallest' => [11];
        yield 'between compact sizes' => [17];
        yield 'the gap between compact and full' => [29];
        yield 'above the largest' => [155];
        yield 'a full size that does not exist' => [33];
    }

    #[DataProvider('badSizeProvider')]
    public function testASizeNoAztecSymbolHasIsRefused(int $size): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not \d+ modules across/');

        new AztecOptions(size: $size);
    }

    /** @return \Generator<string, array{int}> */
    public static function badPercentProvider(): \Generator
    {
        yield 'negative' => [-1];
        yield 'no room for data' => [91];
        yield 'nonsense' => [1000];
    }

    #[DataProvider('badPercentProvider')]
    public function testAnImpossibleErrorCorrectionPercentageIsRefused(int $percent): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/error correction must be between/');

        new AztecOptions(errorCorrectionPercent: $percent);
    }

    public function testAnEmptyPayloadCannotBeEncoded(): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::Aztec->value);

        $this->assertFalse($generator->canEncode(''));
        $this->assertTrue($generator->canEncode('HELLO'));
    }

    public function testCanEncodeRefusesAPinnedSizeTheDataCannotFit(): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::Aztec->value);

        $this->assertFalse($generator->canEncode(str_repeat('A', 100), new AztecOptions(size: 15)));
        $this->assertTrue($generator->canEncode(str_repeat('A', 100), new AztecOptions(size: 31)));
    }

    /**
     * Every printable ASCII character has a place in one of the five modes, so
     * nothing printable ever needs a binary shift. That is worth pinning: a
     * gap in one of the tables would show up as a symbol that is larger than it
     * should be and otherwise perfectly correct.
     */
    public function testEveryPrintableCharacterHasAModeOfItsOwn(): void
    {
        for ($byte = 0x20; $byte <= 0x7e; $byte++) {
            $this->assertNotSame(
                [],
                CharacterModes::modesFor($byte),
                sprintf('0x%02x (%s) needs a binary shift', $byte, \chr($byte))
            );
        }
    }

    /**
     * And the bytes that genuinely have none. One control character, a run of
     * thirteen more, and everything above ASCII: 142 in total, which is the
     * number the binary shift exists for.
     */
    public function testExactlyTheBytesWithNoModeNeedABinaryShift(): void
    {
        $needing = [];
        for ($byte = 0; $byte <= 0xff; $byte++) {
            if (CharacterModes::modesFor($byte) === []) {
                $needing[] = $byte;
            }
        }

        $this->assertSame(
            array_merge([0x00], range(0x0e, 0x1a), range(0x80, 0xff)),
            $needing
        );
    }

    /**
     * Getting from Lower to Upper costs nine bits, not the ten a reader would
     * guess. Lower has no latch to Upper at all — the cheapest way across is
     * Lower to Digit for five and Digit to Upper for four — and an encoder that
     * assumed two five-bit latches would pass every round trip while producing
     * symbols one bit wider than they need to be.
     */
    public function testTheCheapestRouteOutOfLowerCaseGoesThroughDigitMode(): void
    {
        [$bits, $codes] = CharacterModes::latchRoute(CharacterModes::LOWER, CharacterModes::UPPER);

        $this->assertSame(9, $bits);
        $this->assertSame([[5, 30], [4, 14]], $codes, 'D/L in Lower, then U/L in Digit');
    }

    /**
     * A single capital inside a lower-case word is a shift, not a latch. The
     * shift exists in Lower and it was missing from the table at first, which
     * cost four bits a word and nothing else — the symbols still scanned.
     */
    public function testOneForeignCharacterIsShiftedRatherThanLatched(): void
    {
        $encoder = new HighLevelEncoder();

        // L/L, five letters, U/S and the capital, five more letters.
        $this->assertCount(5 + 25 + 10 + 25, $encoder->encode('helloXworld'));
    }

    /**
     * From Upper there is no shift into Lower, so the same trick is unavailable
     * and one lower-case letter is cheapest as a binary shift of one byte:
     * eighteen bits, against the nineteen a latch and the route back would
     * cost. Both are legal and zxing-cpp picks the other one.
     */
    public function testOneLowerCaseLetterInsideCapitalsIsCheapestAsABinaryShift(): void
    {
        $encoder = new HighLevelEncoder();

        $this->assertCount(25 + 18 + 25, $encoder->encode('HELLOxWORLD'));
    }

    /**
     * A binary run's length field is five bits up to thirty-one bytes and
     * sixteen after that. Crossing it costs eleven bits for one extra byte,
     * and an encoder that used the short field for a longer run would write a
     * symbol that decodes to the wrong length.
     */
    public function testTheBinaryRunLengthFieldWidensAfterThirtyOneBytes(): void
    {
        $encoder = new HighLevelEncoder();
        $run = static fn (int $bytes): string => str_repeat("\x80", $bytes);

        $this->assertCount(5 + 5 + 31 * 8, $encoder->encode($run(31)));
        $this->assertCount(5 + 5 + 11 + 32 * 8, $encoder->encode($run(32)));
    }

    public function testTheTwoCharacterPunctuationCodesAreUsed(): void
    {
        $encoder = new HighLevelEncoder();

        // "END", then ". " as one Punct code reached by a shift, then "NEXT".
        $this->assertCount(15 + 10 + 20, $encoder->encode('END. NEXT'));
        // A carriage return and a line feed likewise.
        $this->assertCount(10, $encoder->encode("\r\n"));
    }

    /**
     * Aztec uses five Galois fields and only one of them is shared with a
     * symbology this library already had. That one is anchored on a published
     * vector through Data Matrix, so agreeing with it says the arithmetic here
     * is right; the other four are covered end to end by the reference fixture,
     * which reaches every codeword width.
     */
    public function testTheGeneralFieldAgreesWithTheAnchoredByteSizedOne(): void
    {
        $anchored = ReedSolomon256::forDataMatrix();
        $general = new ReedSolomonGf2m(8);

        $this->assertSame([114, 25, 5, 88, 102], $anchored->encode([142, 164, 186], 5), 'ISO/IEC 16022 Annex R');
        $this->assertSame($anchored->encode([142, 164, 186], 5), $general->encode([142, 164, 186], 5));
        $this->assertSame($anchored->encode(range(0, 20), 18), $general->encode(range(0, 20), 18));
    }

    public function testAFieldAztecDoesNotHaveIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no field of 7-bit codewords/');

        new ReedSolomonGf2m(7);
    }

    /**
     * A size names one symbol. The compact sizes stop at 27 and the full ones
     * start at 31, so nothing collides — which is the reason the option is a
     * size and not a layer count, since four layers is two different symbols.
     */
    public function testNoSizeBelongsToTwoDifferentSymbols(): void
    {
        $sizes = Specs::sizes();

        $this->assertSame($sizes, array_values(array_unique($sizes)));
        $this->assertSame([4, true], Specs::fromSize(27));
        $this->assertSame([4, false], Specs::fromSize(31));
    }

    public function testTheLargestSymbolHoldsWhatItsCapabilitiesClaim(): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::Aztec->value);

        $this->assertTrue($generator->canEncode(str_repeat('A', 3000)));
        $this->assertFalse($generator->canEncode(str_repeat("\x80", 4000)));
    }
}
