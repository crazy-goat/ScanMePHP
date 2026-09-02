<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\Code93\Charset;
use CrazyGoat\ScanMePHP\Generator\Code93\Code93Generator;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Code 93 beyond its module patterns, which Code93ReferenceTest pins bit for
 * bit against an independent encoder over all 128 ASCII bytes.
 *
 * What is left is what the bars alone cannot show: that the pattern table
 * satisfies the three-bars-three-spaces invariant that lets characters butt
 * together with no gap, that the two check characters use different weight
 * cycles and are computed in the right order, and that the four shift
 * characters are genuinely separate symbols from the four data characters of
 * the same name — which is the one substantive difference from Code 39.
 */
class Code93Test extends TestCase
{
    private const BARS_PER_CHARACTER = 3;

    /**
     * The invariant that makes Code 93 dense: every character starts on a bar
     * and ends on a space, so nothing needs to separate them. A pattern that
     * ended on a bar would merge with its neighbour and be unreadable, and no
     * reference fixture would show that as anything but the wrong character.
     */
    public function testEveryPatternOpensOnABarAndClosesOnASpace(): void
    {
        $patterns = [];

        foreach ($this->everySymbolValue() as $value) {
            $pattern = Charset::pattern($value);
            $name = Charset::characterName($value);

            self::assertSame(9, \strlen($pattern), "pattern width for {$name}");
            self::assertSame('1', $pattern[0], "{$name} must open on a bar");
            self::assertSame('0', $pattern[8], "{$name} must close on a space");
            self::assertSame(
                self::BARS_PER_CHARACTER,
                \count($this->runLengths($pattern)) - self::BARS_PER_CHARACTER,
                "{$name} must be three bars and three spaces"
            );

            $patterns[$pattern] = $name;
        }

        self::assertArrayNotHasKey(
            Charset::GUARD,
            $patterns,
            'a character has the guard pattern, which would end the symbol early'
        );
        self::assertCount(
            Charset::CHECK_MODULUS,
            $patterns,
            'two symbol values share a pattern, which makes one of them unreadable'
        );
    }

    /**
     * The difference from Code 39, stated as a test.
     *
     * Both symbologies reach full ASCII by shifting, and their escape tables
     * agree on all but four bytes. Code 39 has to spell a shift with a data
     * character, so '$' as data must itself be escaped as '/D' and 'A$B' has
     * two readings. Here the shift has bars of its own, '$' is just '$', and
     * there is only one reading.
     */
    public function testTheShiftsAreSeparateSymbolsFromTheDataCharactersTheyAreNamedFor(): void
    {
        foreach (str_split(Charset::SHIFTS) as $index => $character) {
            $shiftValue = 43 + $index;
            $dataValue = strpos(Charset::CHARACTERS, $character);

            self::assertIsInt($dataValue, "{$character} is also a data character");
            self::assertNotSame(
                Charset::pattern($shiftValue),
                Charset::pattern($dataValue),
                "the shift ({$character}) and the data character {$character} share bars"
            );
            self::assertSame("({$character})", Charset::characterName($shiftValue));
            self::assertSame($character, Charset::characterName($dataValue));
        }

        // And so the four bytes Code 39 Extended escapes are single characters
        // here. Eleven bytes, eleven characters, plus the check pair.
        $symbol = Scanme::create()->generate('A$B/C+D%E', Symbology::Code93);
        self::assertSame(11, $symbol->getMetadataValue('characters'));
        self::assertSame(Charset::width(11), $symbol->getWidth());
    }

    /** @return iterable<string, array{string, int, string, string}> */
    public static function checkCharacterProvider(): iterable
    {
        // Payload, characters drawn (check pair included), C, K.
        yield 'single letter' => ['A', 3, 'A', 'U'];
        yield 'two letters' => ['AB', 4, 'V', '-'];
        yield 'zero' => ['0', 3, '0', '0'];
        yield 'digits' => ['ABC123', 8, 'W', '9'];
        yield 'a word' => ['HELLO', 7, 'Z', 'Q'];
        // A shifted byte contributes two symbol values, so the weights land
        // differently than the payload length would suggest.
        yield 'lowercase' => ['a', 4, '8', 'P'];
        // Where the C weight cycle wraps: twenty data characters, so the
        // twenty-first weight is 1 again.
        yield 'wraps the c cycle' => ['ABCDEFGHIJKLMNOPQRST', 22, '(+)', 'F'];
    }

    #[DataProvider('checkCharacterProvider')]
    public function testBothCheckCharactersAreMandatoryAndWeighted(
        string $data,
        int $characters,
        string $checkC,
        string $checkK
    ): void {
        $symbol = Scanme::create()->generate($data, Symbology::Code93);

        self::assertSame($characters, $symbol->getMetadataValue('characters'));
        self::assertSame($checkC, $symbol->getMetadataValue('checkC'));
        self::assertSame($checkK, $symbol->getMetadataValue('checkK'));
        self::assertSame(Charset::width($characters), $symbol->getWidth());
    }

    /**
     * K covers C. Computing both over the payload alone would leave the pair
     * unable to see an error in C itself, and would produce a symbol every
     * scanner refuses — which is what makes the order worth pinning.
     */
    public function testKIsComputedOverTheDataWithCAlreadyAppended(): void
    {
        $values = Charset::symbolValues('SCANME');
        $data = \array_slice($values, 0, -2);
        [$c, $k] = \array_slice($values, -2);

        self::assertSame($c, Charset::checkValue($data, Charset::CHECK_C_WEIGHTS));
        self::assertSame($k, Charset::checkValue([...$data, $c], Charset::CHECK_K_WEIGHTS));
        self::assertNotSame(
            $k,
            Charset::checkValue($data, Charset::CHECK_K_WEIGHTS),
            'K was computed without C, which no scanner would accept'
        );
    }

    /**
     * The weights start over at 20 and at 15, and the two cycles being
     * different lengths is what stops a single error from satisfying both.
     */
    public function testTheWeightCyclesStartOverAtTwentyAndFifteen(): void
    {
        self::assertSame(20, Charset::CHECK_C_WEIGHTS);
        self::assertSame(15, Charset::CHECK_K_WEIGHTS);

        // A run of the same character, one past the cycle. The weight of the
        // leftmost is 1 rather than 21, so the sum is smaller than a running
        // index would give — and a scanner would reject the running index.
        $twenty = array_fill(0, 20, 1);
        $twentyOne = array_fill(0, 21, 1);

        self::assertSame(array_sum(range(1, 20)) % 47, Charset::checkValue($twenty, 20));
        self::assertSame((array_sum(range(1, 20)) + 1) % 47, Charset::checkValue($twentyOne, 20));
    }

    /** Unlike Code 39's unweighted sum, the cycle sees a transposition. */
    public function testTheCheckPairCatchesATransposition(): void
    {
        $scanme = Scanme::create();
        $ab = $scanme->generate('AB', Symbology::Code93);
        $ba = $scanme->generate('BA', Symbology::Code93);

        self::assertNotSame($ab->getMetadataValue('checkC'), $ba->getMetadataValue('checkC'));
    }

    /** @return iterable<string, array{string, int}> */
    public static function widthProvider(): iterable
    {
        // Payload, characters drawn including the mandatory check pair.
        yield 'one character' => ['A', 3];
        yield 'six characters' => ['ABC123', 8];
        yield 'the whole data set' => [Charset::CHARACTERS, 45];
        yield 'five lowercase bytes' => ['abcde', 12];
        yield 'a control byte' => ["\x00", 4];
    }

    #[DataProvider('widthProvider')]
    public function testTheWidthIsNineModulesPerCharacterPlusTheGuards(
        string $data,
        int $characters
    ): void {
        $symbol = Scanme::create()->generate($data, Symbology::Code93);

        // Two nine-module guards, nine modules per character with no gaps
        // between them, and the terminator bar.
        $expected = 9 + $characters * 9 + 9 + 1;

        self::assertSame($expected, $symbol->getWidth());
        self::assertSame($expected, Charset::width($characters));
        self::assertSame(1, $symbol->getHeight(), 'Code 93 has no descender row');
    }

    /**
     * Density is the reason this symbology exists, so it is asserted rather
     * than claimed in a comment.
     *
     * Nine modules per character against Code 39's thirteen, but two mandatory
     * check characters against none — so the advantage grows with the payload
     * rather than being a flat ratio, and a short symbol saves much less than
     * the per-character figure suggests.
     */
    public function testItIsNarrowerThanCode39AndMoreSoTheLongerThePayloadIs(): void
    {
        $scanme = Scanme::create();

        $short = 'SCANME-2026';
        $long = str_repeat('SCANME-', 8) . 'END';

        self::assertSame(136, $scanme->generate($short, Symbology::Code93)->getWidth());
        self::assertSame(168, $scanme->generate($short, Symbology::Code39)->getWidth());

        self::assertSame(568, $scanme->generate($long, Symbology::Code93)->getWidth());
        self::assertSame(792, $scanme->generate($long, Symbology::Code39)->getWidth());

        // 81% of the width at eleven characters, 72% at fifty-nine, tending to
        // the 9-against-13 per-character cost as the guards and the check pair
        // stop mattering.
        self::assertSame(9, Charset::width(2) - Charset::width(1));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function encodabilityProvider(): iterable
    {
        yield 'empty' => ['', false];
        yield 'uppercase' => ['ABC', true];
        yield 'digits' => ['123', true];
        yield 'lowercase' => ['abc', true];
        yield 'the data set' => [Charset::CHARACTERS, true];
        yield 'asterisk' => ['A*B', true];
        yield 'underscore' => ['A_B', true];
        yield 'newline' => ["A\nB", true];
        yield 'nul' => ["\x00", true];
        yield 'delete' => ["\x7f", true];
        yield 'high byte' => ["\xff", false];
        yield 'utf-8' => ['zażółć', false];
    }

    #[DataProvider('encodabilityProvider')]
    public function testItAcceptsAllOfAsciiAndNothingAbove(string $data, bool $encodable): void
    {
        $scanme = Scanme::create();

        self::assertSame(
            $encodable,
            $scanme->getRegistry()->getGenerator(Symbology::Code93->value)->canEncode($data)
        );

        try {
            $scanme->generate($data, Symbology::Code93);
            self::assertTrue($encodable, 'encoded a payload canEncode() rejects');
        } catch (UnsupportedDataException) {
            self::assertFalse($encodable, 'refused a payload canEncode() accepts');
        }
    }

    public function testTheBackendRefusesAByteAboveAsciiOnItsOwnTerms(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ASCII only');

        (new Code93Generator())->generate("caf\xe9");
    }

    public function testAnEmptyPayloadIsRefusedByBothLayers(): void
    {
        self::assertFalse(
            Scanme::create()->getRegistry()->getGenerator(Symbology::Code93->value)->canEncode('')
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('empty');

        (new Code93Generator())->generate('');
    }

    public function testASymbolValueOutsideTheSetHasNoName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a Code 93 symbol value');

        Charset::characterName(Charset::CHECK_MODULUS);
    }

    public function testTheHumanReadableLineIsThePayloadAlone(): void
    {
        $symbol = Scanme::create()->generate('PART-99', Symbology::Code93);

        // The check characters are verified and discarded by the scanner, so
        // unlike Code 39's optional one they reach neither the printed line nor
        // the reading side.
        self::assertSame('PART-99', $symbol->getText());
    }

    public function testItIsRegisteredAndDescribesItself(): void
    {
        $capabilities = Scanme::create()
            ->getRegistry()
            ->getGenerator(Symbology::Code93->value)
            ->getCapabilities();

        self::assertSame('Code 93', $capabilities->title);
        self::assertSame(['code93', 'code-93', 'c93'], $capabilities->allNames());
        self::assertTrue($capabilities->providesText);
        self::assertFalse($capabilities->hasErrorCorrection());
        // Nothing to choose: the check characters are mandatory, full ASCII is
        // part of the symbology, and every character is nine fixed modules.
        self::assertNull($capabilities->optionsClass);
    }

    public function testEveryOutputFormatAcceptsCode93(): void
    {
        $scanme = Scanme::create();

        foreach ($scanme->getRegistry()->rendererFormats() as $format) {
            self::assertTrue($scanme->supports(Symbology::Code93, $format), "code93 in {$format}");
            self::assertNotSame('', $scanme->render('SCANME-93', Symbology::Code93, $format));
        }
    }

    public function testItRunsOnThePurePhpBackend(): void
    {
        $backend = (new Code93Generator())->getActiveBackend();

        self::assertNotNull($backend);
        self::assertSame('php', $backend->getName());
    }

    /** @return list<int> Every symbol value, data characters and shifts */
    private function everySymbolValue(): array
    {
        return range(0, Charset::CHECK_MODULUS - 1);
    }

    /** @return list<int> */
    private function runLengths(string $modules): array
    {
        preg_match_all('/(.)\1*/', $modules, $matches);

        return array_map(strlen(...), $matches[0]);
    }
}
