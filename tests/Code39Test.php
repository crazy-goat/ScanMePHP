<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\Code39\Charset;
use CrazyGoat\ScanMePHP\Generator\Code39\Code39Generator;
use CrazyGoat\ScanMePHP\Generator\Code39\Code39Options;
use CrazyGoat\ScanMePHP\Generator\Code39\Mode;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Code 39 beyond its module patterns, which Code39ReferenceTest already pins
 * bit for bit against an independent encoder.
 *
 * What is left is everything the bars alone cannot show: that the table
 * satisfies the nine-elements-three-wide invariant a scanner relies on, that
 * the check character is computed over the encoded characters and not the
 * caller's bytes, that the two modes disagree about what is encodable, and
 * that a payload's length says nothing about its width once escapes are in
 * play.
 */
class Code39Test extends TestCase
{
    private const NARROW_ELEMENTS = 6;

    private const WIDE_ELEMENTS = 3;

    /**
     * The invariant that makes Code 39 self-checking: nine elements per
     * character, three of them wide. A scanner rejects a character that
     * violates it, so a table entry that does is a symbol nobody can read —
     * and unlike a swapped row, no reference fixture would show it as anything
     * but the wrong character.
     */
    public function testEveryPatternIsNineElementsWithThreeWide(): void
    {
        $patterns = [];

        foreach (str_split(Charset::CHARACTERS) as $character) {
            $modules = $this->patternOf($character);
            $elements = $this->runLengths($modules);

            self::assertCount(9, $elements, "pattern for {$character}");
            self::assertSame(
                [1, 2],
                array_values(array_unique($elements)) === [2, 1] ? [1, 2] : array_values(array_unique($elements)),
                "an element is neither narrow nor wide in {$character}"
            );
            self::assertSame(
                self::WIDE_ELEMENTS,
                \count(array_filter($elements, static fn (int $width): bool => $width === 2)),
                "wide elements in {$character}"
            );
            self::assertSame(
                self::NARROW_ELEMENTS,
                \count(array_filter($elements, static fn (int $width): bool => $width === 1)),
                "narrow elements in {$character}"
            );
            self::assertSame('1', $modules[0], 'a pattern must open on a bar');

            $patterns[$modules] = $character;
        }

        // Plus the guard, which shares the alphabet's shape but must not
        // collide with any character in it, or a payload could end the symbol.
        $guard = $this->guardModules();
        self::assertArrayNotHasKey($guard, $patterns, 'a character has the guard pattern');

        self::assertCount(
            Charset::CHECK_MODULUS,
            $patterns,
            'two characters share a pattern, which makes one of them unreadable'
        );
    }

    public function testTheCharacterSetIsTheCheckCharacterOrdering(): void
    {
        self::assertSame(Charset::CHECK_MODULUS, \strlen(Charset::CHARACTERS));
        self::assertSame(Charset::CHARACTERS, implode('', array_unique(str_split(Charset::CHARACTERS))));

        // The order is not cosmetic: it is the symbol value, so digits have to
        // be their own value and letters have to start at ten.
        foreach (['0' => 0, '9' => 9, 'A' => 10, 'Z' => 35, '-' => 36, '%' => 42] as $character => $value) {
            self::assertSame($value, Charset::symbolValue((string) $character));
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function widthProvider(): iterable
    {
        yield 'one character' => ['A', 1];
        yield 'six characters' => ['ABC123', 6];
        yield 'the whole set' => [Charset::CHARACTERS, Charset::CHECK_MODULUS];
    }

    #[DataProvider('widthProvider')]
    public function testTheWidthIsThirteenModulesPerCharacterPlusTheGuards(
        string $data,
        int $characters
    ): void {
        $symbol = Scanme::create()->generate($data, Symbology::Code39);

        // Twelve modules of pattern and one of inter-character gap, for the
        // payload and both guards, less the gap the last one does not need.
        $expected = ($characters + 2) * 13 - 1;

        self::assertSame($expected, $symbol->getWidth());
        self::assertSame($expected, Charset::width($characters, 2));
        self::assertSame(1, $symbol->getHeight(), 'Code 39 has no descender row');
    }

    public function testAWiderRatioWidensOnlyTheWideElements(): void
    {
        $scanme = Scanme::create();
        $narrow = $scanme->generate('SCANME', Symbology::Code39);
        $wide = $scanme->generate('SCANME', Symbology::Code39, new Code39Options(wideRatio: 3));

        // Eight characters including the guards, three wide elements each,
        // one extra module apiece.
        self::assertSame($narrow->getWidth() + 8 * self::WIDE_ELEMENTS, $wide->getWidth());
        self::assertSame(Charset::width(6, 3), $wide->getWidth());
        self::assertSame(3, $wide->getMetadataValue('wideRatio'));
    }

    /** @return iterable<string, array{int}> */
    public static function badRatioProvider(): iterable
    {
        yield 'one' => [1];
        yield 'four' => [4];
        yield 'zero' => [0];
        yield 'negative' => [-2];
    }

    #[DataProvider('badRatioProvider')]
    public function testTheRatioIsRefusedOutsideTheStandardsRange(int $ratio): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be 2 or 3');

        new Code39Options(wideRatio: $ratio);
    }

    /** @return iterable<string, array{string, string}> */
    public static function checkCharacterProvider(): iterable
    {
        // Unweighted sums modulo 43, worked from the character values.
        yield 'zero sums to zero' => ['0', '0'];
        yield 'single letter' => ['A', 'A'];
        yield 'the modulus itself' => ['0Z', 'Z'];
        // 35 + 35 is 70, which is 27 past the modulus.
        yield 'wraps around' => ['ZZ', 'R'];
        yield 'a part number' => ['SCANME-42', 'M'];
        // The digits sum to 45, so the check character is '2' — a digit,
        // which is exactly why it cannot be told from data on sight.
        yield 'digits' => ['1234567890', '2'];
    }

    #[DataProvider('checkCharacterProvider')]
    public function testTheCheckCharacterIsAnUnweightedSumModuloFortyThree(
        string $data,
        string $expected
    ): void {
        self::assertSame($expected, Charset::checkCharacter($data));

        $symbol = Scanme::create()->generate(
            $data,
            Symbology::Code39,
            new Code39Options(checkCharacter: true)
        );

        self::assertSame($expected, $symbol->getMetadataValue('checkCharacter'));
        self::assertSame($data . $expected, $symbol->getMetadataValue('characters'));
        self::assertSame(Charset::width(\strlen($data) + 1, 2), $symbol->getWidth());
    }

    /**
     * Being unweighted, the check character cannot see a transposition. That
     * is a documented weakness of the symbology and the reason the option
     * exists at all rather than being always on — a caller should know what
     * they are getting.
     */
    public function testTheCheckCharacterDoesNotCatchATransposition(): void
    {
        self::assertSame(Charset::checkCharacter('AB'), Charset::checkCharacter('BA'));
    }

    /**
     * The order that matters: expand, then check.
     *
     * Computing the check character over the caller's bytes rather than over
     * the characters actually drawn gives a value a scanner disagrees with on
     * every extended symbol, and on no standard one — so a test that only
     * covered Code 39 would never see it.
     */
    public function testTheCheckCharacterCoversTheExpansionNotThePayload(): void
    {
        $symbol = Scanme::create()->generate(
            'ab',
            Symbology::Code39Extended,
            new Code39Options(checkCharacter: true)
        );

        self::assertSame('+A+B', substr((string) $symbol->getMetadataValue('characters'), 0, 4));
        self::assertSame(Charset::checkCharacter('+A+B'), $symbol->getMetadataValue('checkCharacter'));
        self::assertNotSame(
            Charset::checkCharacter('AB'),
            $symbol->getMetadataValue('checkCharacter'),
            'the check character was computed over the payload instead of the expansion'
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function extensionProvider(): iterable
    {
        yield 'lowercase shifts on plus' => ['a', '+A'];
        yield 'nul shifts on percent' => ["\x00", '%U'];
        yield 'control shifts on dollar' => ["\x01", '$A'];
        yield 'delete shifts on percent' => ["\x7f", '%T'];
        yield 'uppercase is itself' => ['A', 'A'];
        yield 'digit is itself' => ['7', '7'];
        yield 'space is itself' => [' ', ' '];
        yield 'hyphen is itself' => ['-', '-'];
        yield 'full stop is itself' => ['.', '.'];
        // The four shift characters are not themselves in this mode. Passing
        // them through unshifted is the whole class of bug this covers: the
        // symbol would still scan, as different data.
        yield 'dollar is escaped' => ['$', '/D'];
        yield 'percent is escaped' => ['%', '/E'];
        yield 'slash is escaped' => ['/', '/O'];
        yield 'plus is escaped' => ['+', '/K'];
        // And the character that would otherwise end the symbol.
        yield 'asterisk is escaped' => ['*', '/J'];
    }

    #[DataProvider('extensionProvider')]
    public function testExtendedModeExpandsToTheCharactersActuallyDrawn(
        string $byte,
        string $expected
    ): void {
        self::assertSame($expected, Charset::toExtended($byte));

        $symbol = Scanme::create()->generate($byte, Symbology::Code39Extended);
        self::assertSame($expected, $symbol->getMetadataValue('characters'));
        self::assertSame(Charset::width(\strlen($expected), 2), $symbol->getWidth());
    }

    /**
     * Every ASCII byte has an expansion, and no two share one — a collision
     * would make one of the pair unrecoverable.
     */
    public function testTheExpansionIsTotalAndInjectiveOverAscii(): void
    {
        $seen = [];
        for ($byte = 0; $byte < 128; $byte++) {
            $encoded = Charset::toExtended(\chr($byte));

            self::assertMatchesRegularExpression('/^[0-9A-Z\-. $\/+%]{1,2}$/', $encoded, "byte {$byte}");
            self::assertArrayNotHasKey($encoded, $seen, sprintf(
                'bytes %d and %d both encode as %s',
                $seen[$encoded] ?? -1,
                $byte,
                $encoded
            ));

            $seen[$encoded] = $byte;
        }

        self::assertCount(128, $seen);

        // Thirty-nine bytes are characters in their own right, and everything
        // else costs two — including '$', '/', '+' and '%', which are in the
        // 43 but are shifts here, so extended mode has four fewer single-
        // character bytes than the character set has characters. That gap is
        // where an encoder that passes them through unshifted goes wrong.
        $single = array_filter(array_keys($seen), static fn (string $e): bool => \strlen($e) === 1);
        self::assertCount(Charset::CHECK_MODULUS - 4, $single);
    }

    /**
     * The trap this symbology sets: byte count and symbol width are the same
     * function of the data only in standard mode.
     */
    public function testPayloadLengthDoesNotDetermineWidthInExtendedMode(): void
    {
        $scanme = Scanme::create();

        self::assertSame(
            $scanme->generate('ABCDE', Symbology::Code39)->getWidth(),
            $scanme->generate('ABCDE', Symbology::Code39Extended)->getWidth(),
            'five characters inside the 43 cost the same in both modes'
        );

        self::assertSame(
            Charset::width(10, 2),
            $scanme->generate('abcde', Symbology::Code39Extended)->getWidth(),
            'five lowercase bytes are ten characters'
        );
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function encodabilityProvider(): iterable
    {
        yield 'empty' => ['', false, false];
        yield 'uppercase' => ['ABC', true, true];
        yield 'digits' => ['123', true, true];
        yield 'the whole set' => [Charset::CHARACTERS, true, true];
        yield 'lowercase' => ['abc', false, true];
        yield 'underscore' => ['A_B', false, true];
        yield 'asterisk' => ['A*B', false, true];
        yield 'newline' => ["A\nB", false, true];
        yield 'nul' => ["\x00", false, true];
        yield 'delete' => ["\x7f", false, true];
        yield 'high byte' => ["\xff", false, false];
        yield 'utf-8' => ['zażółć', false, false];
    }

    #[DataProvider('encodabilityProvider')]
    public function testTheTwoModesDisagreeAboutWhatIsEncodable(
        string $data,
        bool $standard,
        bool $extended
    ): void {
        $registry = Scanme::create()->getRegistry();

        self::assertSame(
            $standard,
            $registry->getGenerator(Symbology::Code39->value)->canEncode($data),
            'standard'
        );
        self::assertSame(
            $extended,
            $registry->getGenerator(Symbology::Code39Extended->value)->canEncode($data),
            'extended'
        );
    }

    #[DataProvider('encodabilityProvider')]
    public function testWhatCanEncodeRefusesIsAlsoWhatGenerateRefuses(
        string $data,
        bool $standard,
        bool $extended
    ): void {
        $scanme = Scanme::create();

        foreach ([Symbology::Code39->value => $standard, Symbology::Code39Extended->value => $extended] as $name => $ok) {
            try {
                $scanme->generate($data, $name);
                self::assertTrue($ok, "{$name} encoded a payload canEncode() rejects");
            } catch (UnsupportedDataException) {
                self::assertFalse($ok, "{$name} refused a payload canEncode() accepts");
            }
        }
    }

    /**
     * Two layers refuse a payload, and they say different things on purpose.
     *
     * The facade checks canEncode() first and reports which symbology could
     * not take the data and what it does take. The backend is the last line,
     * reached by anyone holding a generator directly, and there the message
     * names the other mode: a caller reaching for lowercase has not made a
     * typo, they have picked the wrong one of the two symbologies.
     */
    public function testStandardModeNamesTheOtherModeWhenItRefuses(): void
    {
        try {
            Scanme::create()->generate('hello', Symbology::Code39);
            self::fail('lowercase is not a Code 39 character');
        } catch (UnsupportedDataException $expected) {
            self::assertStringContainsString('digits, A-Z', $expected->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Code 39 Extended');

        (new Code39Generator())->generate('hello');
    }

    public function testExtendedModeRefusesAByteAboveAscii(): void
    {
        try {
            Scanme::create()->generate("caf\xe9", Symbology::Code39Extended);
            self::fail('a byte above 127 has no Code 39 representation');
        } catch (UnsupportedDataException $expected) {
            self::assertStringContainsString('ASCII', $expected->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ASCII only');

        (new Code39Generator(Mode::Extended))->generate("caf\xe9");
    }

    public function testBothModesAreRegisteredAndDescribeThemselves(): void
    {
        $registry = Scanme::create()->getRegistry();

        foreach (
            [
                Symbology::Code39->value => ['Code 39', ['code39', 'code-39', 'c39']],
                Symbology::Code39Extended->value => [
                    'Code 39 Extended',
                    ['code39ext', 'code-39-extended', 'code39-full-ascii'],
                ],
            ] as $name => [$title, $names]
        ) {
            $capabilities = $registry->getGenerator($name)->getCapabilities();

            self::assertSame($title, $capabilities->title);
            self::assertSame($names, $capabilities->allNames());
            self::assertSame(Code39Options::class, $capabilities->optionsClass);
            self::assertTrue($capabilities->providesText);
            self::assertFalse($capabilities->hasErrorCorrection());
        }
    }

    /**
     * The registry can be asked which symbology a payload belongs to, and the
     * answer has to distinguish the two modes or it is no use for choosing.
     */
    public function testTheRegistryDistinguishesTheModesByPayload(): void
    {
        $registry = Scanme::create()->getRegistry();

        self::assertContains(Symbology::Code39->value, $registry->generatorsFor('SCANME'));
        self::assertContains(Symbology::Code39Extended->value, $registry->generatorsFor('SCANME'));

        self::assertNotContains(Symbology::Code39->value, $registry->generatorsFor('scanme'));
        self::assertContains(Symbology::Code39Extended->value, $registry->generatorsFor('scanme'));
    }

    public function testTheHumanReadableLineIsThePayloadAlone(): void
    {
        $symbol = Scanme::create()->generate(
            'PART-99',
            Symbology::Code39,
            new Code39Options(checkCharacter: true)
        );

        // No guards, and no check character: ISO/IEC 16388 keeps both out of
        // the printed line even though a scanner reports the latter.
        self::assertSame('PART-99', $symbol->getText());
        self::assertStringNotContainsString('*', $symbol->getText());
    }

    public function testTheQuietZoneIsTenNarrowModulesEitherSide(): void
    {
        $quietZone = Scanme::create()->generate('A', Symbology::Code39)->getQuietZone();

        self::assertSame(Charset::QUIET_ZONE, $quietZone->left);
        self::assertSame(Charset::QUIET_ZONE, $quietZone->right);
    }

    public function testEveryOutputFormatAcceptsCode39(): void
    {
        $scanme = Scanme::create();

        foreach ($scanme->getRegistry()->rendererFormats() as $format) {
            foreach ([Symbology::Code39, Symbology::Code39Extended] as $symbology) {
                self::assertTrue($scanme->supports($symbology, $format), "{$symbology->value} in {$format}");
                self::assertNotSame(
                    '',
                    $scanme->render('SCANME-39', $symbology, $format),
                    "{$symbology->value} in {$format}"
                );
            }
        }
    }

    public function testBothModesRunOnThePurePhpBackend(): void
    {
        foreach ([Mode::Standard, Mode::Extended] as $mode) {
            $backend = (new Code39Generator($mode))->getActiveBackend();

            self::assertNotNull($backend);
            self::assertSame('php', $backend->getName());
        }
    }

    /** The guard, as this library draws it, at the default ratio. */
    private function guardModules(): string
    {
        $symbol = Scanme::create()->generate('A', Symbology::Code39);

        return substr($symbol->toModuleString(), 0, 12);
    }

    private function patternOf(string $character): string
    {
        $symbol = Scanme::create()->generate($character, Symbology::Code39);

        // Guard, gap, then the one character.
        return substr($symbol->toModuleString(), 13, 12);
    }

    /** @return list<int> */
    private function runLengths(string $modules): array
    {
        preg_match_all('/(.)\1*/', $modules, $matches);

        return array_map(strlen(...), $matches[0]);
    }
}
