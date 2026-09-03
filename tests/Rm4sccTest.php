<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\FourState\Alphabet;
use CrazyGoat\ScanMePHP\Generator\FourState\Patterns;
use CrazyGoat\ScanMePHP\Generator\FourState\State;
use CrazyGoat\ScanMePHP\Generator\Rm4scc\Characters;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What holds for every RM4SCC symbol, rather than for the 160 in the fixture.
 *
 * The fixture says our bars match zint's for the payloads somebody chose.
 * These are the claims that have to hold for the rest, and most of them are
 * claims about an enumeration rather than about a symbol: RM4SCC has no
 * character table, only the six two-of-four nibbles and a base-six index into
 * them, so being wrong here means being wrong across a run of characters.
 */
class Rm4sccTest extends TestCase
{
    public function testTheSymbologyIsRegisteredAndDescribesItself(): void
    {
        $capabilities = Defaults::registry()->getGenerator(Symbology::Rm4scc->value)->getCapabilities();

        $this->assertSame('RM4SCC', $capabilities->title);
        $this->assertSame(Dimension::Linear, $capabilities->dimension);
        $this->assertSame([], $capabilities->errorCorrectionLevels);
        // No options at all: the one thing a caller might want to change is the
        // bar height, and that is a render option because it must scale all
        // three bands together.
        $this->assertNull($capabilities->optionsClass);
        // Nothing to print. The payload is a postcode and a delivery point
        // suffix, and Royal Mail prints those in the address, not under bars
        // that live on the other side of the envelope.
        $this->assertFalse($capabilities->providesText);
        $this->assertNull($this->generate('LE28HS')->getText());
    }

    #[DataProvider('aliasProvider')]
    public function testEveryAliasResolves(string $alias): void
    {
        $this->assertSame(
            Symbology::Rm4scc->value,
            Defaults::registry()->getGenerator($alias)->getCapabilities()->name
        );
    }

    /** @return \Generator<string, array{string}> */
    public static function aliasProvider(): \Generator
    {
        foreach (['royal-mail', 'royal-mail-4state', 'rm4scc-cbc'] as $alias) {
            yield $alias => [$alias];
        }
    }

    /**
     * The six nibbles are the enumeration, not a list that was typed out.
     *
     * If they were a table, this is where a transposed pair would hide: the
     * alphabet is indexed by their order, so swapping two of them relabels six
     * characters at a time and still draws legal-looking symbols.
     */
    public function testTheNibblesAreEveryFourBitValueWithTwoBitsSet(): void
    {
        $enumerated = [];
        for ($value = 0; $value < 16; $value++) {
            if (substr_count(decbin($value), '1') === 2) {
                $enumerated[] = $value;
            }
        }

        $this->assertSame($enumerated, Patterns::TWO_OF_FOUR);
    }

    /**
     * Two of every character's four bars reach up and two reach down.
     *
     * This is the symbology's whole error detection: a bar read as one state
     * too tall or too short breaks the count, so a scanner can refuse the
     * character instead of reporting a different one.
     */
    public function testEveryCharacterSpendsExactlyTwoAscendersAndTwoDescenders(): void
    {
        $seen = [];

        foreach (str_split(Alphabet::CHARACTERS) as $character) {
            $bars = Alphabet::bars($character);
            $this->assertCount(4, $bars, "character {$character} is not four bars");

            $ascenders = \count(array_filter($bars, static fn (State $b): bool => $b->hasAscender()));
            $descenders = \count(array_filter($bars, static fn (State $b): bool => $b->hasDescender()));

            $this->assertSame(2, $ascenders, "character {$character} has {$ascenders} ascenders");
            $this->assertSame(2, $descenders, "character {$character} has {$descenders} descenders");

            $seen[implode('', array_map(static fn (State $b): string => $b->value, $bars))] = true;
        }

        // Thirty-six characters, thirty-six patterns. A collision would print
        // two postcodes the same, which no fixture of chosen payloads notices.
        $this->assertCount(36, $seen, 'two characters draw the same four bars');
    }

    public function testTheSymbolOpensWithAnAscenderAndClosesWithAFullBar(): void
    {
        $states = Patterns::states($this->generate('LE28HS'));

        $this->assertSame('A', $states[0]);
        $this->assertSame('F', $states[-1]);
    }

    /**
     * Width follows from the payload's length and nothing else.
     *
     * Four bars per character, one more character for the check, a start and a
     * stop bar, and a module of space after every bar but the last.
     */
    #[DataProvider('lengthProvider')]
    public function testTheWidthIsTheLengthAndNothingElse(int $length): void
    {
        $symbol = $this->generate(substr(str_repeat('LE28HS', 9), 0, $length));
        $bars = 4 * ($length + 1) + 2;

        $this->assertSame(2 * $bars - 1, $symbol->getWidth(), "width for {$length} characters");
        $this->assertSame($bars, \strlen(Patterns::states($symbol)));
    }

    /** @return \Generator<string, array{int}> */
    public static function lengthProvider(): \Generator
    {
        foreach ([1, 2, 6, 9, Characters::MAX_LENGTH] as $length) {
            yield "{$length} characters" => [$length];
        }
    }

    /**
     * Three rows, and the tracker under every bar.
     *
     * A gap column is empty in all three rows and a bar column is dark in the
     * middle one whatever its state — that is what makes a tracker-only bar a
     * bar rather than a hole in the symbol.
     */
    public function testTheTrackerRunsUnderEveryBarAndTheGapsAreEmpty(): void
    {
        $symbol = $this->generate('BX11LT1A');

        $this->assertSame(3, $symbol->getHeight());
        $this->assertSame(Patterns::ROW_HEIGHTS, $symbol->getRowHeights());

        for ($x = 0; $x < $symbol->getWidth(); $x++) {
            $bar = $x % 2 === 0;
            $this->assertSame($bar, $symbol->get($x, 1), "column {$x} of the tracker row");

            if (!$bar) {
                $this->assertFalse($symbol->get($x, 0), "column {$x} of the ascender row is not a gap");
                $this->assertFalse($symbol->get($x, 2), "column {$x} of the descender row is not a gap");
            }
        }
    }

    /**
     * The bands keep their proportions when a caller asks for a taller symbol.
     *
     * Flattening them to one height is what a linear symbology's bar height
     * override does, and here it would erase the data: the ratio between
     * ascender, tracker and descender is what a bar means.
     */
    public function testAnOverriddenBarHeightScalesAllThreeBands(): void
    {
        $symbol = $this->generate('LE28HS');

        $this->assertSame([3, 2, 3], (new PngOptions())->resolveRowHeights($symbol));
        $this->assertSame([6, 4, 6], (new PngOptions(barHeight: 16))->resolveRowHeights($symbol));
        // Rounding may not flatten a band away, however small the request.
        $this->assertSame([1, 1, 1], (new PngOptions(barHeight: 1))->resolveRowHeights($symbol));
    }

    public function testTheQuietZoneIsTheClearSpaceRoyalMailAsksFor(): void
    {
        $quietZone = $this->generate('LE28HS')->getQuietZone();

        // 2mm on every side at the nominal 0.5mm bar. On every side, not just
        // the ends: a reader finds the tracker band by finding where the bars
        // stop.
        foreach ([$quietZone->left, $quietZone->right, $quietZone->top, $quietZone->bottom] as $side) {
            $this->assertSame(4, $side);
        }
    }

    /**
     * The check character is a sum, so the order of the payload cannot matter.
     */
    public function testTheCheckCharacterDoesNotDependOnTheOrderOfThePayload(): void
    {
        $check = $this->generate('LE28HS')->getMetadataValue('checkCharacter');

        foreach (['SH82EL', 'HSLE82', '82LEHS'] as $shuffled) {
            $this->assertSame($check, $this->generate($shuffled)->getMetadataValue('checkCharacter'));
        }
    }

    /**
     * Appending a Z changes nothing about the check character.
     *
     * The nibbles are worth 1 to 6 rather than 0 to 5, and Z is the character
     * worth six in both — so it adds a multiple of the modulus and lands back
     * where it started. Under the 0-to-5 reading of the same table, Z is worth
     * five and this moves; that is the one observable difference between the
     * two conventions, and it is why the test exists.
     */
    public function testAppendingTheCharacterWorthSixLeavesTheCheckCharacterAlone(): void
    {
        foreach (['0', 'LE28HS', 'BX11LT1A'] as $data) {
            $this->assertSame(
                $this->generate($data)->getMetadataValue('checkCharacter'),
                $this->generate($data . 'Z')->getMetadataValue('checkCharacter'),
                "appending Z to {$data} moved the check character"
            );
        }
    }

    public function testLowercaseIsUpperCasedRatherThanRefused(): void
    {
        $this->assertSame(
            Patterns::states($this->generate('LE28HS')),
            Patterns::states($this->generate('le28hs'))
        );
    }

    #[DataProvider('badPayloadProvider')]
    public function testTheFacadeSaysWhyItCannotEncode(string $data): void
    {
        $this->expectException(UnsupportedDataException::class);

        (new Scanme(Defaults::registry()))->render($data, Symbology::Rm4scc, 'svg');
    }

    /** @return \Generator<string, array{string}> */
    public static function badPayloadProvider(): \Generator
    {
        yield 'empty' => [''];
        // A postcode is written with a space and the space is not encodable,
        // so keeping it would print a symbol saying something else.
        yield 'a space' => ['LE2 8HS'];
        yield 'punctuation' => ['LE28-HS'];
        yield 'not ascii' => ['LE28ŁS'];
        // Fifty is the reference encoder's ceiling. Past it we would be
        // emitting symbols nothing has ever agreed with.
        yield 'past the reference encoder' => [str_repeat('0', Characters::MAX_LENGTH + 1)];
    }

    private function generate(string $data): Symbol
    {
        return Defaults::registry()->getGenerator(Symbology::Rm4scc->value)->generate($data);
    }
}
