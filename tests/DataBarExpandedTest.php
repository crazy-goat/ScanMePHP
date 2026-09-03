<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\DataBar\Patterns;
use CrazyGoat\ScanMePHP\Generator\DataBarExpanded\Backend\PhpBackend;
use CrazyGoat\ScanMePHP\Generator\DataBarExpanded\Encodation\Encodation;
use CrazyGoat\ScanMePHP\Generator\DataBarExpanded\Encodation\GeneralField;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What holds for every DataBar Expanded symbol, rather than for the sampled
 * ones.
 *
 * The reference fixture says our symbols match somebody else's for three
 * hundred payloads. These are the claims that have to hold for all of them: the
 * enumeration behind a data character is a bijection onto twelve bits' worth of
 * legal widths, the tables the layout and the checksum read are the shape the
 * code assumes, and the two encodation rules most likely to rot quietly are
 * pinned to the bits they produce.
 */
class DataBarExpandedTest extends TestCase
{
    public function testTheSymbologyIsRegisteredAndDescribesItself(): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::DataBarExpanded->value);
        $capabilities = $generator->getCapabilities();

        $this->assertSame('GS1 DataBar Expanded', $capabilities->title);
        $this->assertTrue($capabilities->providesText);
        $this->assertSame([], $capabilities->errorCorrectionLevels);
        // Nothing to choose: the width follows from the data and the standard
        // fixes everything else.
        $this->assertNull($capabilities->optionsClass);
    }

    #[DataProvider('aliasProvider')]
    public function testEveryAliasResolves(string $alias): void
    {
        $this->assertSame(
            Symbology::DataBarExpanded->value,
            Defaults::registry()->getGenerator($alias)->getCapabilities()->name
        );
    }

    /** @return \Generator<string, array{string}> */
    public static function aliasProvider(): \Generator
    {
        foreach (['gs1-databar-expanded', 'rss-expanded', 'rss-exp'] as $alias) {
            yield $alias => [$alias];
        }
    }

    /**
     * The width is the character count and nothing else, and there is no quiet
     * zone.
     */
    public function testEveryWidthIsTheLengthFormula(): void
    {
        $widths = [];

        foreach (['(90)1', '(01)09501101020917', '(01)09501101020917(10)LOT0001', '(90)' . str_repeat('A', 26)] as $data) {
            $symbol = $this->generate($data);
            $characters = (int) $symbol->getMetadataValue('characters');

            $this->assertSame(
                PhpBackend::GUARD_MODULES
                    + PhpBackend::CHARACTER_MODULES * $characters
                    + PhpBackend::FINDER_MODULES * intdiv($characters + 1, 2),
                $symbol->getWidth(),
                "width for {$data}"
            );

            $this->assertSame(0, $symbol->getQuietZone()->left);
            $this->assertSame(0, $symbol->getQuietZone()->right);
            $this->assertSame(PhpBackend::BAR_HEIGHT, $symbol->getModuleHeight());

            $widths[] = $symbol->getWidth();
        }

        $this->assertSame($widths, array_unique($widths), 'two of these payloads drew the same width');
    }

    public function testTheSymbolOpensAndClosesWithASingleLightModule(): void
    {
        $modules = $this->generate('(01)09501101020917(10)LOT0001')->toModuleString();

        // Guard patterns either side: a light module then a dark one, and the
        // mirror of that at the end. They are why the symbology asks for no
        // quiet zone.
        $this->assertSame('01', substr($modules, 0, 2), 'the left guard is not a space then a bar');
        $this->assertSame('10', substr($modules, -2), 'the right guard is not a bar then a space');
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

        (new Scanme(Defaults::registry()))->render($data, Symbology::DataBarExpanded, 'svg');
    }

    /** @return \Generator<string, array{string}> */
    public static function badPayloadProvider(): \Generator
    {
        yield 'not a GS1 payload' => ['plain text'];
        yield 'empty' => [''];
        yield 'an identifier that does not exist' => ['(89)ABC'];
        yield 'the wrong length for its identifier' => ['(01)123'];
        yield 'a character outside the GS1 set' => ['(90)a{b'];
        yield 'a GTIN with a wrong check digit' => ['(01)09501101020918'];
        yield 'more data than a symbol holds' => ['(90)' . str_repeat('a', 30) . '(91)' . str_repeat('b', 30)];
    }

    /**
     * Every twelve-bit value is a different set of widths, and every one of
     * them is a legal character.
     *
     * This is what the symbology rests on: a value is not looked up, it is
     * counted to, so the enumeration has to hit each combination exactly once.
     * A double count means two payloads print the same bars, which no fixture
     * of three hundred symbols would notice.
     */
    public function testTheCharacterEnumerationIsABijection(): void
    {
        $seen = [];
        $wrongWidth = [];
        $oddSumNotEven = [];

        for ($value = 0; $value < Patterns::EXPANDED_VALUES; $value++) {
            $widths = Patterns::character($value, Patterns::EXPANDED, false);
            $seen[implode(',', $widths)] = true;

            if (\count($widths) !== 8 || array_sum($widths) !== PhpBackend::CHARACTER_MODULES) {
                $wrongWidth[] = $value;
            }

            if (($widths[0] + $widths[2] + $widths[4] + $widths[6]) % 2 !== 0) {
                $oddSumNotEven[] = $value;
            }
        }

        $this->assertSame([], \array_slice($wrongWidth, 0, 5), 'a character is not seventeen modules of eight elements');
        // The parity is what tells a reader which way round a character was
        // drawn: exactly one of the two directions has an even odd-element sum,
        // so it is the whole basis of reading a mirrored character back.
        $this->assertSame([], \array_slice($oddSumNotEven, 0, 5), 'a character has an odd sum of odd elements');
        $this->assertCount(Patterns::EXPANDED_VALUES, $seen, 'two values share their widths');
    }

    /**
     * The rule that applies to a character's odd elements and not to its even
     * ones.
     *
     * Having it the wrong way round still produces a plausible symbol, which is
     * why it is asserted rather than trusted: it shifts every value past the
     * point the bucket sizes change, so the symbol scans and says something
     * else.
     */
    public function testEveryCharacterHasANarrowOddElementAndTheEvenOnesNeedNot(): void
    {
        $withoutANarrowOdd = [];
        $evensAlwaysNarrow = true;

        for ($value = 0; $value < Patterns::EXPANDED_VALUES; $value++) {
            $widths = Patterns::character($value, Patterns::EXPANDED, false);
            $odd = [$widths[0], $widths[2], $widths[4], $widths[6]];
            $even = [$widths[1], $widths[3], $widths[5], $widths[7]];

            if (!\in_array(1, $odd, true)) {
                $withoutANarrowOdd[] = $value;
            }

            if (!\in_array(1, $even, true)) {
                $evensAlwaysNarrow = false;
            }
        }

        $this->assertSame([], \array_slice($withoutANarrowOdd, 0, 5), 'a character has no narrow odd element');
        $this->assertFalse($evensAlwaysNarrow, 'no character has even elements that are all wide');
    }

    /** Six finder patterns, five elements each, fifteen modules each. */
    public function testTheFinderTableIsTheShapeAScannerLooksFor(): void
    {
        $this->assertCount(6, Patterns::EXPANDED_FINDERS);

        foreach (Patterns::EXPANDED_FINDERS as $index => $finder) {
            $this->assertCount(5, $finder, "finder {$index} is not five elements");
            $this->assertSame(
                PhpBackend::FINDER_MODULES,
                array_sum($finder),
                "finder {$index} is not fifteen modules"
            );
            // The two single modules at the end are what a scanner sweeping at
            // an angle recognises; without them a finder is just more data.
            $this->assertSame([1, 1], \array_slice($finder, -2), "finder {$index} does not end narrow");
        }
    }

    /**
     * One finder per pair, and the first pair always carries the first pattern.
     *
     * The sequence is how a scanner that read one pair out of a stack knows
     * which pair it read, so a row of the wrong length would leave a pair
     * unlabelled.
     */
    public function testEveryFinderSequenceLabelsEveryPair(): void
    {
        foreach (Patterns::EXPANDED_FINDER_SEQUENCES as $pairs => $sequence) {
            $this->assertCount($pairs, $sequence, "the sequence for {$pairs} pairs is the wrong length");
            $this->assertSame(0, $sequence[0], "the sequence for {$pairs} pairs does not open with the first finder");

            foreach ($sequence as $index) {
                $this->assertArrayHasKey($index, Patterns::EXPANDED_FINDERS);
            }
        }
    }

    /**
     * One weight per data character, and the first character always weighs from
     * the powers of three from zero.
     */
    public function testEveryWeightingSequenceCoversItsCharacters(): void
    {
        foreach (Patterns::EXPANDED_WEIGHTS as $characters => $sequence) {
            $this->assertCount($characters, $sequence, "the weights for {$characters} characters are the wrong length");
            $this->assertSame(0, $sequence[0], "the weights for {$characters} characters do not open at zero");
            $this->assertSame($sequence, array_unique($sequence), "the weights for {$characters} characters repeat");
            $this->assertLessThanOrEqual(22, max($sequence));
        }
    }

    /**
     * The check character is a value, not a residue.
     *
     * 211 x (characters - 3) plus the residue reaches 4008, which is why an
     * Expanded character needs the whole twelve-bit space even though the data
     * characters themselves stop at 4095. Getting the base wrong gives a symbol
     * that scans everywhere except through a checksum test.
     */
    public function testTheCheckCharacterIsTheResiduePlusItsLengthBase(): void
    {
        foreach (['(90)1', '(01)09501101020917', '(01)09501101020917(21)SERIAL(11)991201'] as $data) {
            $symbol = $this->generate($data);
            $characters = (int) $symbol->getMetadataValue('characters');
            $checksum = (int) $symbol->getMetadataValue('checksum');
            $check = (int) $symbol->getMetadataValue('checkCharacter');

            $this->assertLessThan(Patterns::EXPANDED_MODULUS, $checksum, "the residue for {$data} is out of range");
            $this->assertSame(
                Patterns::EXPANDED_MODULUS * ($characters - 1 - 3) + $checksum,
                $check,
                "the check character for {$data}"
            );
            $this->assertLessThan(Patterns::EXPANDED_VALUES, $check);
        }
    }

    /**
     * An FNC1 written outside numeric mode puts the field back into it.
     *
     * The bits are asserted rather than described because this is the rule
     * whose absence is invisible: the symbol still scans, and the digits after
     * the separator come out as something else. Here the two AI digits that
     * follow are a numeric pair with no latch between them, which they could
     * only be if the FNC1 changed the mode.
     */
    public function testAnFnc1LeavesTheGeneralFieldInNumericMode(): void
    {
        $field = GeneralField::encode('10A' . GeneralField::FNC1 . '21B');

        $this->assertSame(
            '0010011'          // the pair "10", seven bits, as 11 x 1 + 0 + 8
            . '0000'           // latch to alphanumeric for the letter
            . '100000'         // 'A'
            . '01111'          // FNC1, and back to numeric with it
            . '0011111'        // the pair "21", with no latch in front of it
            . '0000'           // latch to alphanumeric again
            . '100001',        // 'B'
            $field->bits
        );
        $this->assertNull($field->finalDigit);
    }

    /**
     * A final lone digit is written in four bits when that saves a character.
     *
     * Thirty-two digits of numeric data need fifteen pairs and one leftover;
     * seven bits for the leftover would spill into a sixth symbol character and
     * four bits do not.
     */
    public function testAFinalLoneDigitIsWrittenShortWhenItSavesACharacter(): void
    {
        $short = $this->generate('(90)' . str_repeat('1', 13));
        $long = $this->generate('(90)' . str_repeat('1', 12));

        $this->assertSame(5, (int) $short->getMetadataValue('characters') - 1);
        $this->assertSame(5, (int) $long->getMetadataValue('characters') - 1);
        $this->assertSame(
            GeneralField::encode('90' . str_repeat('1', 12))->finalDigit,
            null,
            'an even count of digits has no leftover'
        );
        $this->assertSame(1, GeneralField::encode('90' . str_repeat('1', 13))->finalDigit);
    }

    /** The character set, and the one byte in it the parenthesised form cannot carry. */
    public function testTheGeneralFieldKnowsWhatItCannotSay(): void
    {
        $this->assertTrue(GeneralField::accepts('90' . GeneralField::FNC1 . 'aZ9!_ '));
        $this->assertFalse(GeneralField::accepts('90{'));
        $this->assertFalse(GeneralField::accepts("90\x00"));
    }

    /** Twenty-two characters, and the twenty-third is refused with a reason. */
    public function testASymbolStopsAtTwentyTwoCharacters(): void
    {
        $symbol = $this->generate('(90)GEBU1SG1T8IO532URE3V(21)zjqs09d0igjzy6x');

        $this->assertSame(22, (int) $symbol->getMetadataValue('characters'));
        $this->assertSame(Encodation::MAXIMUM_CHARACTERS, 21);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/holds 21 data characters/');
        Encodation::values(ElementString::parse('(90)' . str_repeat('a', 30) . '(91)' . str_repeat('b', 6)));
    }

    private function generate(string $data): Symbol
    {
        return Defaults::registry()->getGenerator(Symbology::DataBarExpanded->value)->generate($data);
    }
}
