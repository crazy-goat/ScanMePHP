<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\Codabar\CodabarGenerator;
use CrazyGoat\ScanMePHP\Generator\Codabar\CodabarOptions;
use CrazyGoat\ScanMePHP\Generator\Codabar\Delimiter;
use CrazyGoat\ScanMePHP\Generator\Codabar\Patterns;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Codabar beyond its module patterns, which CodabarReferenceTest pins against
 * an independent encoder over every data character and every delimiter pair.
 *
 * What is left is what the bars cannot show: that the table's irregular wide
 * counts are the ones the standard specifies, that the symbol ends on a bar,
 * that the delimiters are options rather than data, and that the two spellings
 * of a delimiter are the same delimiter.
 */
class CodabarTest extends TestCase
{
    private const BARS_PER_CHARACTER = 4;

    /**
     * Seven elements, four bars and three spaces, and — unlike every other
     * two-width symbology here — a wide count that varies by character.
     *
     * Digits, '-' and '$' have two wide elements; ':', '/', '.', '+' and all
     * four delimiters have three. That irregularity is the table, so it is
     * asserted rather than assumed: a row given the wrong wide count produces
     * a symbol of the wrong length, which is exactly the kind of wrong that
     * still scans.
     */
    public function testEveryPatternIsFourBarsAndThreeSpaces(): void
    {
        $patterns = [];

        foreach (str_split(Patterns::everyCharacter()) as $character) {
            $pattern = Patterns::pattern($character);
            $patterns[] = $pattern;

            self::assertSame(Patterns::ELEMENTS, \strlen($pattern), "pattern for {$character}");
            self::assertMatchesRegularExpression('/^[nw]{7}$/', $pattern, "pattern for {$character}");

            $wide = substr_count($pattern, 'w');
            $expected = str_contains('0123456789-$', $character) ? 2 : 3;

            self::assertSame($expected, $wide, "wide elements in {$character}");
        }

        self::assertCount(
            \strlen(Patterns::everyCharacter()),
            array_unique($patterns),
            'two characters share a pattern, which makes one of them unreadable'
        );
        self::assertSame(
            \strlen(Patterns::CHARACTERS) + \count(Delimiter::cases()),
            \strlen(Patterns::everyCharacter())
        );
    }

    /** Four bars: a character opens and closes on one, which is why they need a gap. */
    public function testACharacterOpensAndClosesOnABar(): void
    {
        foreach (str_split(Patterns::everyCharacter()) as $character) {
            $modules = Patterns::modules($character, 2);

            self::assertSame('1', $modules[0], "{$character} opens on a bar");
            self::assertSame('1', $modules[-1], "{$character} closes on a bar");
            self::assertCount(
                self::BARS_PER_CHARACTER,
                array_filter(
                    $this->runLengths($modules),
                    static fn (int $width, int $index): bool => $index % 2 === 0,
                    ARRAY_FILTER_USE_BOTH
                )
            );
        }
    }

    /**
     * And so the symbol does too. There is no stop pattern beyond the closing
     * delimiter and no trailing gap — a reference encoder that appends a
     * narrow space after the last bar is drawing quiet zone, not data.
     */
    public function testTheSymbolEndsOnABar(): void
    {
        $modules = Scanme::create()->generate('123456', Symbology::Codabar)->toModuleString();

        self::assertSame('1', $modules[0]);
        self::assertSame('1', $modules[-1]);
    }

    /**
     * The delimiters are options, not payload.
     *
     * Most implementations make the caller write them into the data —
     * 'A123456A' rather than '123456' — which puts a detail of the symbology
     * into the caller's number and makes canEncode() refuse every value a
     * caller actually holds.
     */
    public function testTheDelimitersAreOptionsAndNotPartOfThePayload(): void
    {
        $registry = Scanme::create()->getRegistry();
        $generator = $registry->getGenerator(Symbology::Codabar->value);

        self::assertTrue($generator->canEncode('123456'), 'the number a caller holds');
        self::assertFalse($generator->canEncode('A123456A'), 'the delimiters are not data');

        $symbol = Scanme::create()->generate('123456', Symbology::Codabar);

        self::assertSame('123456', $symbol->getText(), 'the delimiters are not printed');
        self::assertSame('A123456A', $symbol->getMetadataValue('characters'), 'but they are drawn');
    }

    /** @return iterable<string, array{Delimiter, Delimiter}> */
    public static function delimiterProvider(): iterable
    {
        foreach (Delimiter::cases() as $start) {
            foreach (Delimiter::cases() as $stop) {
                yield "{$start->value} to {$stop->value}" => [$start, $stop];
            }
        }
    }

    #[DataProvider('delimiterProvider')]
    public function testEveryDelimiterPairIsDrawnAndReported(Delimiter $start, Delimiter $stop): void
    {
        $symbol = Scanme::create()->generate(
            '42',
            Symbology::Codabar,
            new CodabarOptions(start: $start, stop: $stop)
        );

        self::assertSame($start->value, $symbol->getMetadataValue('start'));
        self::assertSame($stop->value, $symbol->getMetadataValue('stop'));
        self::assertSame("{$start->value}42{$stop->value}", $symbol->getMetadataValue('characters'));
        self::assertSame(
            Patterns::modules("{$start->value}42{$stop->value}", 2),
            $symbol->toModuleString()
        );
    }

    /**
     * T, N, * and E are the same four delimiters under an older spelling, not
     * a variant — a scanner reporting 'A123A' and a manual calling it 'T123T'
     * describe one symbol.
     */
    #[DataProvider('spellingProvider')]
    public function testBothSpellingsOfADelimiterAreTheSameDelimiter(
        string $name,
        Delimiter $expected
    ): void {
        self::assertSame($expected, Delimiter::fromName($name));
        self::assertSame($expected, Delimiter::fromName(strtolower($name)));
        self::assertSame(
            Patterns::pattern($expected->value),
            Patterns::pattern(Delimiter::fromName($name)->value)
        );
    }

    /** @return iterable<string, array{string, Delimiter}> */
    public static function spellingProvider(): iterable
    {
        yield 'A' => ['A', Delimiter::A];
        yield 'T is A' => ['T', Delimiter::A];
        yield 'B' => ['B', Delimiter::B];
        yield 'N is B' => ['N', Delimiter::B];
        yield 'C' => ['C', Delimiter::C];
        yield 'star is C' => ['*', Delimiter::C];
        yield 'D' => ['D', Delimiter::D];
        yield 'E is D' => ['E', Delimiter::D];
    }

    public function testEveryDelimiterKnowsItsOtherSpellingAndTheyAreDistinct(): void
    {
        $alternatives = [];
        foreach (Delimiter::cases() as $delimiter) {
            $alternatives[] = $delimiter->alternative();
            self::assertSame($delimiter, Delimiter::fromName($delimiter->alternative()));
        }

        self::assertSame(Delimiter::ALTERNATIVES, $alternatives);
        self::assertCount(\count(Delimiter::cases()), array_unique($alternatives));
    }

    #[DataProvider('badDelimiterProvider')]
    public function testAnythingElseIsNotADelimiter(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A Codabar delimiter is one of');

        Delimiter::fromName($name);
    }

    /** @return iterable<string, array{string}> */
    public static function badDelimiterProvider(): iterable
    {
        yield 'a data character' => ['0'];
        yield 'a letter that is not one' => ['F'];
        yield 'empty' => [''];
        yield 'two characters' => ['AB'];
    }

    /**
     * Width is not the character count times anything, because the wide count
     * varies: a symbol of colons is wider than the same length of digits.
     */
    #[DataProvider('widthProvider')]
    public function testTheWidthFollowsTheWideCountOfEachCharacter(
        string $data,
        int $ratio,
        int $expected
    ): void {
        $symbol = Scanme::create()->generate(
            $data,
            Symbology::Codabar,
            new CodabarOptions(wideRatio: $ratio)
        );

        self::assertSame($expected, $symbol->getWidth());
        self::assertSame($expected, Patterns::width("A{$data}A", $ratio));
        self::assertSame(1, $symbol->getHeight(), 'Codabar has no descender row');
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function widthProvider(): iterable
    {
        // A delimiter has three wide elements (10 modules at ratio 2), a digit
        // two (9), and a colon three (10). Plus one module per gap.
        yield 'one digit' => ['0', 2, 10 + 1 + 9 + 1 + 10];
        yield 'one colon' => [':', 2, 10 + 1 + 10 + 1 + 10];
        yield 'three digits' => ['123', 2, 10 + 1 + 3 * (9 + 1) + 10];
        yield 'one digit, ratio 3' => ['0', 3, 13 + 1 + 11 + 1 + 13];
    }

    public function testTheColonIsWiderThanADigitAtTheSameLength(): void
    {
        $scanme = Scanme::create();

        self::assertGreaterThan(
            $scanme->generate('11111111', Symbology::Codabar)->getWidth(),
            $scanme->generate('::::::::', Symbology::Codabar)->getWidth(),
            'a table with a constant wide count would make these equal'
        );
    }

    /** @return iterable<string, array{int}> */
    public static function badRatioProvider(): iterable
    {
        yield 'one' => [1];
        yield 'four' => [4];
        yield 'zero' => [0];
    }

    #[DataProvider('badRatioProvider')]
    public function testTheRatioIsRefusedOutsideTheStandardsRange(int $ratio): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be 2 or 3');

        new CodabarOptions(wideRatio: $ratio);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function encodabilityProvider(): iterable
    {
        yield 'empty' => ['', false];
        yield 'digits' => ['123', true];
        yield 'every data character' => [Patterns::CHARACTERS, true];
        yield 'hyphen' => ['-', true];
        yield 'the delimiters' => ['A', false];
        yield 'delimited payload' => ['A123A', false];
        yield 'a letter' => ['12X4', false];
        yield 'lowercase delimiter' => ['a', false];
        yield 'a space' => ['1 2', false];
        yield 'an asterisk' => ['1*2', false];
        yield 'high byte' => ["\xff", false];
    }

    #[DataProvider('encodabilityProvider')]
    public function testItAcceptsItsSixteenCharactersAndNothingElse(
        string $data,
        bool $encodable
    ): void {
        $scanme = Scanme::create();

        self::assertSame(
            $encodable,
            $scanme->getRegistry()->getGenerator(Symbology::Codabar->value)->canEncode($data)
        );

        try {
            $scanme->generate($data, Symbology::Codabar);
            self::assertTrue($encodable, 'encoded a payload canEncode() rejects');
        } catch (UnsupportedDataException) {
            self::assertFalse($encodable, 'refused a payload canEncode() accepts');
        }
    }

    public function testTheBackendRefusesOnItsOwnTermsToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Codabar accepts');

        (new CodabarGenerator())->generate('A123A');
    }

    /**
     * There is deliberately no check-character option: the variants in
     * circulation disagree and nothing here can verify one. Recorded as a test
     * so the absence is a decision rather than an oversight.
     */
    public function testThereIsNoCheckCharacterOption(): void
    {
        $parameters = (new \ReflectionClass(CodabarOptions::class))
            ->getConstructor()
            ?->getParameters() ?? [];

        $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters);

        self::assertSame(['start', 'stop', 'wideRatio'], $names);
    }

    public function testItIsRegisteredAndDescribesItself(): void
    {
        $capabilities = Scanme::create()
            ->getRegistry()
            ->getGenerator(Symbology::Codabar->value)
            ->getCapabilities();

        self::assertSame('Codabar', $capabilities->title);
        self::assertSame(['codabar', 'coda-bar', 'nw-7', 'code-2-of-7'], $capabilities->allNames());
        self::assertSame(CodabarOptions::class, $capabilities->optionsClass);
        self::assertTrue($capabilities->providesText);
        self::assertFalse($capabilities->hasErrorCorrection());
    }

    public function testEveryOutputFormatAcceptsCodabar(): void
    {
        $scanme = Scanme::create();

        foreach ($scanme->getRegistry()->rendererFormats() as $format) {
            self::assertTrue($scanme->supports(Symbology::Codabar, $format), "codabar in {$format}");
            self::assertNotSame('', $scanme->render('4917234', Symbology::Codabar, $format));
        }
    }

    public function testItRunsOnThePurePhpBackend(): void
    {
        $backend = (new CodabarGenerator())->getActiveBackend();

        self::assertNotNull($backend);
        self::assertSame('php', $backend->getName());
    }

    /** @return list<int> */
    private function runLengths(string $modules): array
    {
        preg_match_all('/(.)\1*/', $modules, $matches);

        return array_map(strlen(...), $matches[0]);
    }
}
