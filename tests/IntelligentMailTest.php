<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\FourState\Patterns;
use CrazyGoat\ScanMePHP\Generator\IntelligentMail\Backend\PhpBackend;
use CrazyGoat\ScanMePHP\Generator\IntelligentMail\BarMap;
use CrazyGoat\ScanMePHP\Generator\IntelligentMail\CharacterTable;
use CrazyGoat\ScanMePHP\Generator\IntelligentMail\Codewords;
use CrazyGoat\ScanMePHP\Generator\IntelligentMail\Number;
use CrazyGoat\ScanMePHP\Generator\IntelligentMail\Payload;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What holds for every Intelligent Mail symbol, rather than for the 78 drawn.
 *
 * The fixture beside this file is a bar-for-bar comparison, and for a
 * symbology this scattered that is a blunt instrument: a symbol either matches
 * zint or it is wrong nearly everywhere, and nothing in between is diagnosed.
 * These are the claims underneath it — about the character table, about the
 * bar map, about the arithmetic that has to carry 102 bits through a language
 * whose integers are 63 — each of which can fail on its own and be read on its
 * own.
 */
class IntelligentMailTest extends TestCase
{
    private const TRACKING = '01234567094987654321';

    public function testTheSymbologyIsRegisteredAndDescribesItself(): void
    {
        $capabilities = Defaults::registry()
            ->getGenerator(Symbology::IntelligentMail->value)
            ->getCapabilities();

        $this->assertSame('Intelligent Mail', $capabilities->title);
        $this->assertSame(Dimension::Linear, $capabilities->dimension);
        $this->assertSame([], $capabilities->errorCorrectionLevels);
        // No options: the bar height is a render option, because it has to
        // scale all three bands together.
        $this->assertNull($capabilities->optionsClass);
        // The tracking code is printed in the address block, not under bars
        // that sit at the bottom of the envelope.
        $this->assertFalse($capabilities->providesText);
        $this->assertNull($this->generate(self::TRACKING)->getText());
    }

    #[DataProvider('aliasProvider')]
    public function testEveryAliasResolves(string $alias): void
    {
        $this->assertSame(
            Symbology::IntelligentMail->value,
            Defaults::registry()->getGenerator($alias)->getCapabilities()->name
        );
    }

    /** @return \Generator<string, array{string}> */
    public static function aliasProvider(): \Generator
    {
        foreach (['imb', 'usps-imb', 'onecode', 'usps4cb'] as $alias) {
            yield $alias => [$alias];
        }
    }

    /**
     * Sixty-five bars, whatever the payload is.
     *
     * The only symbology in the library whose width says nothing about what it
     * carries: the value is padded to 102 bits rather than to its own length,
     * so a bare tracking code and one with eleven digits of routing draw
     * symbols of exactly the same size.
     */
    #[DataProvider('routingProvider')]
    public function testEverySymbolIsTheSameWidth(string $routing): void
    {
        $symbol = $this->generate(self::TRACKING . $routing);

        $this->assertSame(PhpBackend::BARS, \strlen(Patterns::states($symbol)));
        $this->assertSame(2 * PhpBackend::BARS - 1, $symbol->getWidth());
    }

    /** @return \Generator<string, array{string}> */
    public static function routingProvider(): \Generator
    {
        foreach (Payload::ROUTING_LENGTHS as $length) {
            yield "{$length} digits of routing code" => [str_repeat('9', $length)];
        }
    }

    /**
     * The character table is the enumeration, not a table that was typed out.
     *
     * USPS-B-3200 prints 1365 numbers and every one of them follows from two
     * rules: five bits set, then two, and a pattern next to its mirror image.
     * A transcription would be checked by the fixture only where a payload
     * happens to reach the codeword that was mistyped, which for a table this
     * size is almost nowhere.
     */
    public function testTheCharacterTableIsEveryPatternOfFiveBitsThenEveryPatternOfTwo(): void
    {
        $characters = CharacterTable::all();

        $this->assertCount(CharacterTable::LENGTH, $characters);
        $this->assertCount(CharacterTable::LENGTH, array_unique($characters), 'two codewords draw alike');

        foreach ($characters as $index => $pattern) {
            $set = substr_count(decbin($pattern), '1');

            $this->assertSame(
                $index < 1287 ? 5 : 2,
                $set,
                "codeword {$index} has {$set} bars in its descender pattern"
            );
        }
    }

    /**
     * A character sits next to its mirror image, or it is its own.
     *
     * This is the ordering rule, and it is the reason a symbol read back to
     * front decodes to a different codeword rather than to a plausible wrong
     * one — which is how a reader works out which way round the envelope went
     * past it.
     */
    public function testEachCharacterIsPairedWithItsMirrorImage(): void
    {
        foreach ([[0, 1287], [1287, CharacterTable::LENGTH]] as [$from, $to]) {
            // The patterns that are their own mirror image fill in from the
            // top of the group, downwards, so they are a contiguous tail.
            $palindromes = 0;
            for ($index = $to - 1; $index >= $from; $index--) {
                $pattern = CharacterTable::pattern($index);
                if (CharacterTable::mirror($pattern) !== $pattern) {
                    break;
                }

                $palindromes++;
            }

            $this->assertGreaterThan(0, $palindromes, "no palindrome closes the group at {$from}");

            // Everything below them is a pair, laid in from the bottom: an
            // even offset and the odd one after it are mirror images.
            for ($index = $from; $index < $to - $palindromes; $index++) {
                $pattern = CharacterTable::pattern($index);
                $mirrored = CharacterTable::mirror($pattern);

                $this->assertNotSame($pattern, $mirrored, "codeword {$index} is a palindrome out of place");
                $this->assertSame(
                    $mirrored,
                    CharacterTable::pattern($index + (($index - $from) % 2 === 0 ? 1 : -1)),
                    "codeword {$index} is not beside its mirror image"
                );
            }
        }
    }

    /**
     * Every bit of every character is drawn exactly once.
     *
     * Ten characters of thirteen bits is 130; sixty-five bars carry two each.
     * A bar map with a bit in it twice is a bar map with a bit missing, and
     * the missing one would be a bit of the payload that no scanner ever sees.
     */
    public function testTheBarMapDrawsEveryBitOnceAndOnlyOnce(): void
    {
        $this->assertCount(PhpBackend::BARS, BarMap::BARS);

        $descenders = [];
        $ascenders = [];

        foreach (BarMap::BARS as [$descender, $descenderBit, $ascender, $ascenderBit]) {
            $descenders["{$descender}:{$descenderBit}"] = true;
            $ascenders["{$ascender}:{$ascenderBit}"] = true;
        }

        $this->assertCount(PhpBackend::BARS, $descenders, 'a bit is drawn by two descenders');
        $this->assertCount(PhpBackend::BARS, $ascenders, 'a bit is drawn by two ascenders');
        $this->assertCount(
            Codewords::COUNT * CharacterTable::CHARACTER_BITS,
            $descenders + $ascenders,
            'a bit is never drawn'
        );
    }

    /**
     * One digit moves most of the symbol.
     *
     * The property that separates this symbology from the rest of the family,
     * and the reason its damage tolerance works: a character is spread over
     * the full width, so ink lost in one place costs a bit from many
     * characters instead of destroying one. The observed minimum over the
     * twenty-five digits of this payload is twenty-five bars; a quarter is
     * asserted, because what would break here is an encoder that had gone
     * local, not one that moved twenty-four.
     */
    #[DataProvider('neighbourProvider')]
    public function testChangingOneDigitMovesAQuarterOfTheBars(string $data): void
    {
        $before = Patterns::states($this->generate(self::TRACKING . '12345'));
        $after = Patterns::states($this->generate($data));

        $moved = 0;
        for ($bar = 0; $bar < PhpBackend::BARS; $bar++) {
            $moved += $before[$bar] === $after[$bar] ? 0 : 1;
        }

        $this->assertGreaterThan(intdiv(PhpBackend::BARS, 4), $moved, "only {$moved} bars moved for {$data}");
    }

    /** @return \Generator<string, array{string}> */
    public static function neighbourProvider(): \Generator
    {
        // The first digit of the tracking code, the endorsement digit, the
        // last digit of the serial number, and a digit of the routing code.
        foreach (['11234567094987654321', '02234567094987654321', '01234567094987654322'] as $tracking) {
            yield $tracking => [$tracking . '12345'];
        }

        yield 'routing code' => [self::TRACKING . '12346'];
    }

    /**
     * The four routing code lengths are four different things.
     *
     * No routing code and five zeroes are the pair that catches an encoder
     * treating the routing code as a plain number: they read the same as
     * numbers and mean different deliveries, and what keeps them apart is the
     * offset each length is pushed past every shorter one by.
     */
    public function testARoutingCodeOfZeroesIsNotAMissingRoutingCode(): void
    {
        $drawn = [];

        foreach (Payload::ROUTING_LENGTHS as $length) {
            $drawn[] = Patterns::states($this->generate(self::TRACKING . str_repeat('0', $length)));
        }

        $this->assertCount(4, array_unique($drawn), 'two routing code lengths draw the same symbol');
    }

    public function testTheHyphenIsPunctuationRatherThanData(): void
    {
        $this->assertSame(
            Patterns::states($this->generate(self::TRACKING . '01234')),
            Patterns::states($this->generate(self::TRACKING . '-01234'))
        );
    }

    public function testTheSymbolSaysWhatItCarries(): void
    {
        $symbol = $this->generate(self::TRACKING . '-01234');

        $this->assertSame(self::TRACKING, $symbol->getMetadataValue('trackingCode'));
        $this->assertSame('01234', $symbol->getMetadataValue('routingCode'));

        $fcs = $symbol->getMetadataValue('frameCheckSequence');
        $this->assertIsInt($fcs);
        $this->assertGreaterThanOrEqual(0, $fcs);
        // Eleven bits, and the whole of the symbology's error handling: it
        // detects, and there is nothing anywhere in it that corrects.
        $this->assertLessThan(1 << 11, $fcs);
    }

    /**
     * The arithmetic is exact at a width PHP integers do not reach.
     *
     * A 102-bit value is built by multiply-and-add and taken apart by
     * divide-with-remainder, and both are done by hand on thirteen bytes. This
     * drives a value past 2^63 and back through the same mixed radix the
     * codewords use: anything that silently overflowed would come back as a
     * different number.
     */
    public function testTheValueSurvivesBeingBuiltAndTakenApart(): void
    {
        $digits = '9499999999999999999999999999999';
        $number = Number::zero();
        foreach (str_split($digits) as $digit) {
            $number = $number->mulAdd(10, (int) $digit);
        }

        $recovered = '';
        for ($step = 0; $step < \strlen($digits); $step++) {
            [$number, $remainder] = $number->divMod(10);
            $recovered = $remainder . $recovered;
        }

        $this->assertSame($digits, $recovered);
        $this->assertSame(0, $number->toInt(), 'the value did not divide down to nothing');
    }

    public function testAValueWiderThanTheSymbologyAllowsThrowsRatherThanWraps(): void
    {
        $number = Number::zero()->mulAdd(1, 255);
        for ($byte = 1; $byte < Number::BYTES; $byte++) {
            $number = $number->mulAdd(256, 255);
        }

        $this->expectException(\LogicException::class);

        $number->mulAdd(2, 0);
    }

    /**
     * Three rows, and the tracker under every bar.
     */
    public function testTheTrackerRunsUnderEveryBarAndTheGapsAreEmpty(): void
    {
        $symbol = $this->generate(self::TRACKING . '01234567891');

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

    public function testAnOverriddenBarHeightScalesAllThreeBands(): void
    {
        $symbol = $this->generate(self::TRACKING);

        $this->assertSame([3, 2, 3], (new PngOptions())->resolveRowHeights($symbol));
        $this->assertSame([6, 4, 6], (new PngOptions(barHeight: 16))->resolveRowHeights($symbol));
        $this->assertSame([1, 1, 1], (new PngOptions(barHeight: 1))->resolveRowHeights($symbol));
    }

    public function testTheQuietZoneIsTheClearZoneUspsAsksFor(): void
    {
        $quietZone = $this->generate(self::TRACKING)->getQuietZone();

        // An eighth of an inch against a module of half a bar pitch at
        // twenty-two bars to the inch, rounded up.
        foreach ([$quietZone->left, $quietZone->right, $quietZone->top, $quietZone->bottom] as $side) {
            $this->assertSame(PhpBackend::QUIET_ZONE, $side);
        }
    }

    #[DataProvider('badPayloadProvider')]
    public function testTheFacadeSaysWhyItCannotEncode(string $data): void
    {
        $this->expectException(UnsupportedDataException::class);

        (new Scanme(Defaults::registry()))->render($data, Symbology::IntelligentMail, 'svg');
    }

    /** @return \Generator<string, array{string}> */
    public static function badPayloadProvider(): \Generator
    {
        yield 'empty' => [''];
        yield 'the tracking code alone, one digit short' => ['0123456709498765432'];
        yield 'one digit of routing code' => [self::TRACKING . '1'];
        // Six, ten and twelve are all near-misses of a real routing code, and
        // all of them would otherwise encode as some other delivery point.
        yield 'six digits of routing code' => [self::TRACKING . '123456'];
        yield 'ten digits of routing code' => [self::TRACKING . '1234567890'];
        yield 'twelve digits of routing code' => [self::TRACKING . '123456789012'];
        yield 'not digits' => ['0123456709498765432X'];
        // The endorsement digit runs 0 to 4; a five would encode as a
        // different payload's symbol rather than as an invalid one.
        yield 'an endorsement digit of five' => ['05234567094987654321'];
        yield 'an endorsement digit of nine' => ['09234567094987654321'];
    }

    private function generate(string $data): Symbol
    {
        return Defaults::registry()->getGenerator(Symbology::IntelligentMail->value)->generate($data);
    }
}
