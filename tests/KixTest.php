<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\FourState\Alphabet;
use CrazyGoat\ScanMePHP\Generator\FourState\Patterns;
use CrazyGoat\ScanMePHP\Generator\FourState\State;
use CrazyGoat\ScanMePHP\Generator\Kix\Backend\PhpBackend;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What holds for every KIX symbol, rather than for the 188 in the fixture.
 *
 * KIX is the smallest symbology in the library: no start pattern, no stop
 * pattern, no check character, no options. Almost everything below is
 * therefore a claim about what is *not* there, because the mistakes available
 * are all mistakes of addition — an envelope borrowed from RM4SCC, whose
 * alphabet KIX shares and whose code it is built out of.
 */
class KixTest extends TestCase
{
    public function testTheSymbologyIsRegisteredAndDescribesItself(): void
    {
        $capabilities = Defaults::registry()->getGenerator(Symbology::Kix->value)->getCapabilities();

        $this->assertSame('KIX', $capabilities->title);
        $this->assertSame(Dimension::Linear, $capabilities->dimension);
        $this->assertSame([], $capabilities->errorCorrectionLevels);
        // No options: the bar height a caller might want is a render option,
        // because it has to scale all three bands together.
        $this->assertNull($capabilities->optionsClass);
        // Nothing to print under the bars. The payload is an address PostNL
        // already prints above them.
        $this->assertFalse($capabilities->providesText);
        $this->assertNull($this->generate('2500GG30250')->getText());
    }

    #[DataProvider('aliasProvider')]
    public function testEveryAliasResolves(string $alias): void
    {
        $this->assertSame(
            Symbology::Kix->value,
            Defaults::registry()->getGenerator($alias)->getCapabilities()->name
        );
    }

    /** @return \Generator<string, array{string}> */
    public static function aliasProvider(): \Generator
    {
        foreach (['kix-code', 'klantindex', 'postnl'] as $alias) {
            yield $alias => [$alias];
        }
    }

    /**
     * A KIX symbol is its characters concatenated, and nothing else.
     *
     * This is the whole symbology in one assertion, and it is the property
     * every available mistake breaks: a start bar, a stop bar, a check
     * character or a separator would each leave the fixture's shorter symbols
     * looking plausible while making this fail.
     */
    public function testASymbolIsExactlyItsCharactersInOrder(): void
    {
        $alone = [];
        foreach (str_split(Alphabet::CHARACTERS) as $character) {
            $alone[$character] = Patterns::states($this->generate($character));
            $this->assertSame(4, \strlen($alone[$character]), "{$character} alone is not four bars");
        }

        foreach (['2500GG30250', 'LE28HS', '1013AV23XA', Alphabet::CHARACTERS[0] . 'Z9A'] as $data) {
            $expected = '';
            foreach (str_split($data) as $character) {
                $expected .= $alone[$character];
            }

            $this->assertSame($expected, Patterns::states($this->generate($data)), "bars for {$data}");
        }
    }

    /**
     * KIX and RM4SCC draw the same character the same way.
     *
     * Measured against zint in both fixtures, and shared in one class here.
     * The assertion is that they still share it: the day one of them grows its
     * own copy of the table is the day the two can disagree, and nothing about
     * either symbol would look wrong.
     */
    public function testTheAlphabetIsTheOneRm4sccDraws(): void
    {
        foreach (str_split(Alphabet::CHARACTERS) as $character) {
            $rm4scc = Patterns::states(
                Defaults::registry()->getGenerator(Symbology::Rm4scc->value)->generate($character)
            );

            $this->assertSame(
                // Start bar, the character, the check character, stop bar.
                substr($rm4scc, 1, 4),
                Patterns::states($this->generate($character)),
                "{$character} is drawn differently by the two symbologies"
            );
        }
    }

    /**
     * Two ascenders and two descenders in every character.
     *
     * The only integrity KIX has. It catches a misread bar — no other state
     * can be substituted without breaking the count — and it is all that
     * catches anything, since there is no check character over the symbol.
     */
    public function testEveryCharacterSpendsTwoAscendersAndTwoDescenders(): void
    {
        $drawn = [];

        foreach (str_split(Alphabet::CHARACTERS) as $character) {
            $bars = Alphabet::bars($character);
            $this->assertCount(Patterns::BARS_PER_CHARACTER, $bars);

            $ascenders = 0;
            $descenders = 0;
            foreach ($bars as $bar) {
                $ascenders += $bar->hasAscender() ? 1 : 0;
                $descenders += $bar->hasDescender() ? 1 : 0;
            }

            $this->assertSame(2, $ascenders, "{$character} has {$ascenders} ascenders");
            $this->assertSame(2, $descenders, "{$character} has {$descenders} descenders");

            $drawn[implode('', array_map(static fn (State $s): string => $s->value, $bars))] = true;
        }

        // Thirty-six characters, thirty-six patterns: the base-six index is a
        // bijection or two characters are one symbol.
        $this->assertCount(36, $drawn);
    }

    /**
     * No start bar and no stop bar, so the ends are ordinary characters.
     *
     * Written as a claim about width rather than about bars, because that is
     * where an extra bar would show up in every renderer at once: bars = 4n,
     * and a bar is a module with a module of gap after it, so the symbol is
     * 8n - 1 wide.
     */
    #[DataProvider('lengthProvider')]
    public function testTheWidthIsTheCharactersAndNothingElse(int $length): void
    {
        $symbol = $this->generate(str_repeat('9', $length));

        $this->assertSame(8 * $length - 1, $symbol->getWidth(), "width at {$length} characters");
        $this->assertSame($length, $symbol->getMetadataValue('characters'));
        // RM4SCC's start bar is an ascender and its stop bar is full height.
        // Neither can appear here, since the first and last bars belong to the
        // payload and this payload's character draws neither.
        $states = Patterns::states($symbol);
        $this->assertSame(4 * $length, \strlen($states));
        $this->assertSame(Patterns::states($this->generate('9')), substr($states, 0, 4));
    }

    /** @return \Generator<string, array{int}> */
    public static function lengthProvider(): \Generator
    {
        foreach ([1, 2, 6, 11, PhpBackend::MAX_LENGTH] as $length) {
            yield "{$length} characters" => [$length];
        }
    }

    /**
     * There is no check character, and no metadata pretending otherwise.
     *
     * A caller reaching for a postal code tends to assume the symbology checks
     * something. KIX does not, and the absence has to be visible rather than
     * merely undocumented.
     */
    public function testNothingChecksTheSymbol(): void
    {
        $symbol = $this->generate('2500GG30250');

        $this->assertNull($symbol->getMetadataValue('checkCharacter'));
        $this->assertNull($symbol->getMetadataValue('checksum'));

        // The consequence, stated: dropping a character produces another legal
        // symbol, and it is a prefix of the first one.
        $shorter = Patterns::states($this->generate('2500GG3025'));
        $this->assertSame($shorter, substr(Patterns::states($symbol), 0, \strlen($shorter)));
    }

    /**
     * The tracker runs under every bar and the gaps are empty.
     *
     * A gap column is empty in all three rows and a bar column is dark in the
     * middle one whatever its state — that is what makes a tracker-only bar a
     * bar rather than a hole in the symbol.
     */
    public function testTheTrackerRunsUnderEveryBarAndTheGapsAreEmpty(): void
    {
        $symbol = $this->generate('2500GG30250');

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
     */
    public function testAnOverriddenBarHeightScalesAllThreeBands(): void
    {
        $symbol = $this->generate('2500GG30250');

        $this->assertSame([3, 2, 3], (new PngOptions())->resolveRowHeights($symbol));
        $this->assertSame([6, 4, 6], (new PngOptions(barHeight: 16))->resolveRowHeights($symbol));
        // Rounding may not flatten a band away, however small the request.
        $this->assertSame([1, 1, 1], (new PngOptions(barHeight: 1))->resolveRowHeights($symbol));
    }

    /**
     * The quiet zone, which does more work here than in RM4SCC.
     *
     * With no start pattern to recognise, white space is the only thing that
     * says where the first character is — so a KIX symbol without its margin
     * is not merely hard to read, it is ambiguous about its own length.
     */
    public function testTheQuietZoneIsTheClearSpacePostNlAsksFor(): void
    {
        $quietZone = $this->generate('2500GG30250')->getQuietZone();

        foreach ([$quietZone->left, $quietZone->right, $quietZone->top, $quietZone->bottom] as $side) {
            $this->assertSame(4, $side);
        }
    }

    public function testLowercaseIsUpperCasedRatherThanRefused(): void
    {
        $this->assertSame(
            Patterns::states($this->generate('2500GG')),
            Patterns::states($this->generate('2500gg'))
        );
    }

    #[DataProvider('badPayloadProvider')]
    public function testTheFacadeSaysWhyItCannotEncode(string $data): void
    {
        $this->expectException(UnsupportedDataException::class);

        (new Scanme(Defaults::registry()))->render($data, Symbology::Kix, 'svg');
    }

    /** @return \Generator<string, array{string}> */
    public static function badPayloadProvider(): \Generator
    {
        yield 'empty' => [''];
        // A Dutch postcode is written "2500 GG" and the space is not
        // encodable, so keeping it would print a symbol saying something else.
        yield 'a space' => ['2500 GG'];
        yield 'punctuation' => ['2500-GG'];
        yield 'not ascii' => ['2500GŁ'];
        // Eighteen is where KIX stops, and where the reference encoder does.
        yield 'past the specification' => [str_repeat('9', PhpBackend::MAX_LENGTH + 1)];
    }

    private function generate(string $data): Symbol
    {
        return Defaults::registry()->getGenerator(Symbology::Kix->value)->generate($data);
    }
}
