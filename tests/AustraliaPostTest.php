<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\AustraliaPost\AustraliaPostOptions;
use CrazyGoat\ScanMePHP\Generator\AustraliaPost\Backend\PhpBackend;
use CrazyGoat\ScanMePHP\Generator\AustraliaPost\Bars;
use CrazyGoat\ScanMePHP\Generator\AustraliaPost\Format;
use CrazyGoat\ScanMePHP\Generator\AustraliaPost\Payload;
use CrazyGoat\ScanMePHP\Generator\FourState\Patterns;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What holds for every Australia Post symbol, rather than for the 527 in the
 * fixture.
 *
 * Two of these are worth more than the rest. The **tables** are enumerations,
 * so the tests below assert the three rules that generate them rather than
 * comparing against a copy of the same table — a copy would agree with itself
 * forever. And the **parity** is checked by its defining property instead of
 * by its value: a Reed-Solomon codeword is one whose syndromes are zero, and
 * computing them here from a field this file builds itself is an opinion about
 * our symbols that does not go through the class that made them.
 */
class AustraliaPostTest extends TestCase
{
    private const SORTING_CODE = '96130590';

    public function testTheSymbologyIsRegisteredAndDescribesItself(): void
    {
        $capabilities = Defaults::registry()->getGenerator(Symbology::AustraliaPost->value)->getCapabilities();

        $this->assertSame('Australia Post', $capabilities->title);
        $this->assertSame(Dimension::Linear, $capabilities->dimension);
        $this->assertSame([], $capabilities->errorCorrectionLevels);
        $this->assertSame(AustraliaPostOptions::class, $capabilities->optionsClass);
        // Nothing to print under the bars: the sorting code is derived from an
        // address that is already on the envelope, and the customer field is
        // the mailer's own business.
        $this->assertFalse($capabilities->providesText);
        $this->assertNull($this->generate(self::SORTING_CODE)->getText());
    }

    #[DataProvider('aliasProvider')]
    public function testEveryAliasResolves(string $alias): void
    {
        $this->assertSame(
            Symbology::AustraliaPost->value,
            Defaults::registry()->getGenerator($alias)->getCapabilities()->name
        );
    }

    /** @return \Generator<string, array{string}> */
    public static function aliasProvider(): \Generator
    {
        foreach (['auspost', 'australia-post-4state', 'customer-barcode'] as $alias) {
            yield $alias => [$alias];
        }
    }

    /**
     * Both tables are the rule, not a list.
     *
     * Combinations with no tracker bar first, then those with a tracker in the
     * leading bar only, then the leftovers — each group in ascending order.
     * This is where a transcribed table acquires a swapped pair, and a swapped
     * pair draws two characters that are legal, scannable and somebody else's.
     */
    #[DataProvider('tableProvider')]
    public function testTheTableIsTheEnumerationAndNotAList(string $alphabet, int $bars): void
    {
        $low = [];
        $leading = [];
        $rest = [];

        for ($value = 0; $value < 4 ** $bars; $value++) {
            $states = Bars::states($value, $bars);
            $trackers = \count(array_filter($states, static fn (int $s): bool => $s === Bars::FILLER));

            if ($trackers === 0) {
                $low[] = $value;
            } elseif ($trackers === 1 && $states[0] === Bars::FILLER) {
                $leading[] = $value;
            } else {
                $rest[] = $value;
            }
        }

        $expected = [...$low, ...$leading, ...$rest];

        foreach (str_split($alphabet) as $index => $character) {
            $drawn = $bars === Bars::NUMERIC_BARS
                ? Bars::numeric($character)
                : Bars::character($character);

            $this->assertSame($expected[$index], Bars::value($drawn), "\"{$character}\" is out of order");
        }
    }

    /** @return \Generator<string, array{string, int}> */
    public static function tableProvider(): \Generator
    {
        yield 'N table' => [Bars::DIGITS, Bars::NUMERIC_BARS];
        yield 'C table' => [Bars::CHARACTERS, Bars::CHARACTER_BARS];
    }

    /**
     * No two characters draw the same bars, in either table.
     *
     * A collision would print two customer fields the same, which no fixture
     * of chosen payloads notices.
     */
    #[DataProvider('tableProvider')]
    public function testNoTwoCharactersDrawTheSameBars(string $alphabet, int $bars): void
    {
        $drawn = [];

        foreach (str_split($alphabet) as $character) {
            $states = $bars === Bars::NUMERIC_BARS
                ? Bars::numeric($character)
                : Bars::character($character);
            $drawn[implode('', $states)] = true;
        }

        $this->assertCount(\strlen($alphabet), $drawn);
    }

    /**
     * A digit is not the same thing in the two tables.
     *
     * Nothing in the symbol says which table a customer field is written in —
     * the field's width says it, and only the width. This is the observable
     * consequence: the same five digits are one symbol as a five-character
     * field and a different one as part of an eight-digit field.
     */
    public function testTheSameDigitsAreADifferentFieldInEachTable(): void
    {
        $characters = Patterns::states($this->generate(self::SORTING_CODE . '12345'));
        $numeric = Patterns::states($this->generate(self::SORTING_CODE . '12345678'));

        // Both fields are sixteen bars, so both symbols are the same width and
        // carry the same Format Control Code; only the field differs.
        $this->assertSame(\strlen($numeric), \strlen($characters));
        $this->assertNotSame(substr($characters, 22, 16), substr($numeric, 22, 16));
    }

    /**
     * The parity is a Reed-Solomon codeword, checked by its definition.
     *
     * Every symbol's data and parity together, read three bars at a time as
     * six-bit codewords, form a polynomial with α¹ to α⁴ as roots. Evaluating
     * it at those four points has to give zero, and the field here is built
     * from the primitive polynomial rather than borrowed from the encoder — so
     * this is an outside opinion about our symbols, not the encoder agreeing
     * with itself.
     */
    #[DataProvider('fieldProvider')]
    public function testTheParityMakesTheWholeSymbolACodeword(string $data): void
    {
        $states = Patterns::states($this->generate($data));
        $letters = array_column(Bars::STATES, 'value');

        $codewords = [];
        // Everything but the two start bars and the two stop bars.
        foreach (str_split(substr($states, 2, -2), Bars::CHARACTER_BARS) as $chunk) {
            $codewords[] = Bars::value(array_map(
                static fn (string $letter): int => (int) array_search($letter, $letters, true),
                str_split($chunk)
            ));
        }

        foreach ($this->syndromes($codewords) as $root => $syndrome) {
            $this->assertSame(0, $syndrome, "syndrome at root {$root} for {$data}");
        }
    }

    /**
     * Corrupting one bar breaks the codeword.
     *
     * The other half of the claim above: syndromes that are zero for every
     * input would be a field with no discrimination in it at all.
     */
    public function testMovingOneBarBreaksTheCodeword(): void
    {
        $states = Patterns::states($this->generate(self::SORTING_CODE));
        $letters = array_column(Bars::STATES, 'value');

        // The last bar of the sorting code, moved one state along.
        $bar = 21;
        $states[$bar] = $letters[(array_search($states[$bar], $letters, true) + 1) % 4];

        $codewords = [];
        foreach (str_split(substr($states, 2, -2), Bars::CHARACTER_BARS) as $chunk) {
            $codewords[] = Bars::value(array_map(
                static fn (string $letter): int => (int) array_search($letter, $letters, true),
                str_split($chunk)
            ));
        }

        $this->assertNotSame([0, 0, 0, 0], array_values($this->syndromes($codewords)));
    }

    /**
     * Width follows from the customer field and nothing else.
     *
     * Thirty-seven bars, fifty-two or sixty-seven — the three Australia Post
     * symbols, and the only three.
     */
    #[DataProvider('fieldProvider')]
    public function testTheWidthIsTheFieldAndNothingElse(string $data, int $bars): void
    {
        $symbol = $this->generate($data);

        $this->assertSame($bars, \strlen(Patterns::states($symbol)));
        $this->assertSame(2 * $bars - 1, $symbol->getWidth());
        $this->assertContains($bars, [37, 52, 67]);
    }

    /** @return \Generator<string, array{string, int}> */
    public static function fieldProvider(): \Generator
    {
        yield 'no field' => [self::SORTING_CODE, 37];
        yield '5 characters' => [self::SORTING_CODE . 'AB CD', 52];
        yield '8 digits' => [self::SORTING_CODE . '12345678', 52];
        yield '10 characters' => [self::SORTING_CODE . 'abcdefghij', 67];
        yield '15 digits' => [self::SORTING_CODE . '123456789012345', 67];
    }

    /**
     * The symbol opens and closes with the same two bars.
     *
     * An ascender and a tracker at each end, not mirrored — which means the
     * frame alone does not say which way up the article is, and a reader has
     * to get that from the Format Control Code.
     */
    public function testTheSymbolIsFramedTheSameWayAtBothEnds(): void
    {
        $states = Patterns::states($this->generate(self::SORTING_CODE));

        $this->assertSame('AT', substr($states, 0, 2));
        $this->assertSame('AT', substr($states, -2));
    }

    /**
     * Every field is a whole number of codewords, which is why the filler
     * exists.
     *
     * The Standard Customer Barcode has no customer information at all and
     * still spends a bar on the field, because twenty bars of Format Control
     * Code and sorting code do not divide by three. That single bar is the
     * whole reason the symbology is thirty-seven bars rather than thirty-six.
     */
    #[DataProvider('fieldProvider')]
    public function testTheDataBarsDivideIntoCodewords(string $data, int $bars): void
    {
        $payload = Payload::of($data);
        $dataBars = 4 + 16 + $payload->customerFieldBars();

        $this->assertSame(0, $dataBars % Bars::CHARACTER_BARS, 'the fields do not divide into codewords');
        $this->assertSame($bars, 2 + $dataBars + 12 + 2);
    }

    /** The filler is a tracker bar, and there is at most one per field. */
    #[DataProvider('fieldProvider')]
    public function testAFieldIsPaddedByAtMostOneBar(string $data): void
    {
        $payload = Payload::of($data);
        $written = \strlen($payload->customerInformation)
            * (Payload::isNumeric(\strlen($payload->customerInformation)) ? Bars::NUMERIC_BARS : Bars::CHARACTER_BARS);

        $padding = \array_slice($payload->customerBars(), $written);

        $this->assertLessThanOrEqual(1, \count($padding), 'a field is padded by more than the standard\'s one bar');
        $this->assertSame(array_fill(0, \count($padding), Bars::FILLER), $padding, 'the padding is not a filler bar');
    }

    /**
     * The format is a choice, and it changes the symbol.
     *
     * Same sorting code, four articles. Nothing in the data string says which
     * was meant, which is why it is an option — and the symbols differ in the
     * Format Control Code and, because the parity is taken over it, in the
     * parity too.
     */
    public function testTheFormatIsTheCallersAndNotThePayloads(): void
    {
        $drawn = [];

        foreach (Format::cases() as $format) {
            $symbol = $this->generate(self::SORTING_CODE, $format);
            $drawn[$format->value] = Patterns::states($symbol);

            $this->assertSame($format->value, $symbol->getMetadataValue('format'));
            $this->assertSame($format->code(), $symbol->getMetadataValue('formatControlCode'));
            // Only the ends of the symbol move: the sorting code sits at the
            // same sixteen bars whatever the article is.
            $this->assertSame('TFDFFAAFFFADTFFF', substr($drawn[$format->value], 6, 16));
        }

        $this->assertCount(4, array_unique($drawn), 'two formats draw the same symbol');
    }

    /**
     * The two wider codes are not a choice.
     *
     * A caller asks for the Standard barcode and gets 11, 59 or 62 according
     * to how much customer information there is, because the field's width is
     * the only thing that tells a reader where the parity begins.
     */
    #[DataProvider('fieldProvider')]
    public function testTheWiderStandardCodesFollowFromTheField(string $data, int $bars): void
    {
        $code = $this->generate($data)->getMetadataValue('formatControlCode');

        $this->assertSame(match ($bars) {
            37 => '11',
            52 => '59',
            default => '62',
        }, $code);
    }

    public function testTheSymbolSaysWhatItCarries(): void
    {
        $symbol = $this->generate(self::SORTING_CODE . 'AB CD');

        $this->assertSame(Symbology::AustraliaPost->value, $symbol->getMetadataValue('symbology'));
        $this->assertSame(self::SORTING_CODE, $symbol->getMetadataValue('sortingCode'));
        $this->assertSame('AB CD', $symbol->getMetadataValue('customerInformation'));
    }

    /** Three rows, and the tracker under every bar. */
    public function testTheTrackerRunsUnderEveryBarAndTheGapsAreEmpty(): void
    {
        $symbol = $this->generate(self::SORTING_CODE . 'AB CD');

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

    /** The bands keep their proportions when a caller asks for a taller symbol. */
    public function testAnOverriddenBarHeightScalesAllThreeBands(): void
    {
        $symbol = $this->generate(self::SORTING_CODE);

        $this->assertSame([3, 2, 3], (new PngOptions())->resolveRowHeights($symbol));
        $this->assertSame([6, 4, 6], (new PngOptions(barHeight: 16))->resolveRowHeights($symbol));
        $this->assertSame([1, 1, 1], (new PngOptions(barHeight: 1))->resolveRowHeights($symbol));
    }

    public function testTheQuietZoneIsTheClearSpaceAustraliaPostAsksFor(): void
    {
        $quietZone = $this->generate(self::SORTING_CODE)->getQuietZone();

        foreach ([$quietZone->left, $quietZone->right, $quietZone->top, $quietZone->bottom] as $side) {
            $this->assertSame(PhpBackend::QUIET_ZONE, $side);
        }
    }

    /** canEncode answers for the option bag it was given, not for the default. */
    public function testWhetherAPayloadFitsDependsOnTheFormat(): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::AustraliaPost->value);
        $data = self::SORTING_CODE . 'AB CD';

        $this->assertTrue($generator->canEncode($data));
        $this->assertFalse($generator->canEncode($data, new AustraliaPostOptions(Format::ReplyPaid)));
        $this->assertTrue($generator->canEncode(self::SORTING_CODE, new AustraliaPostOptions(Format::ReplyPaid)));
    }

    #[DataProvider('badPayloadProvider')]
    public function testTheFacadeSaysWhyItCannotEncode(string $data, Format $format): void
    {
        $this->expectException(UnsupportedDataException::class);

        (new Scanme(Defaults::registry()))->render(
            $data,
            Symbology::AustraliaPost,
            'svg',
            new AustraliaPostOptions($format)
        );
    }

    /** @return \Generator<string, array{string, Format}> */
    public static function badPayloadProvider(): \Generator
    {
        yield 'empty' => ['', Format::Standard];
        yield 'a short sorting code' => ['9613059', Format::Standard];
        // Nine digits is not a sorting code and one digit of customer
        // information; there is no field one digit wide.
        yield 'nine digits' => ['961305901', Format::Standard];
        yield 'letters in the sorting code' => ['9613059A', Format::Standard];
        // A field has to be filled exactly. Padding it out is not ours to
        // invent: filler bars in the middle of a C-table field index as lower
        // case letters.
        yield 'a field half filled' => [self::SORTING_CODE . 'ABC', Format::Standard];
        yield 'a field overfilled' => [self::SORTING_CODE . 'ABCDEF', Format::Standard];
        // Eight characters is an N-table field, whatever is in it.
        yield 'letters in a numeric field' => [self::SORTING_CODE . 'ABCDEFGH', Format::Standard];
        yield 'punctuation the C table has no room for' => [self::SORTING_CODE . 'AB-CD', Format::Standard];
        yield 'not ascii' => [self::SORTING_CODE . 'ABŁCD', Format::Standard];
        yield 'customer information on a reply paid article' => [self::SORTING_CODE . 'AB CD', Format::ReplyPaid];
        yield 'customer information on a routing article' => [self::SORTING_CODE . '12345678', Format::Routing];
    }

    /**
     * The four syndromes of a codeword sequence over GF(64).
     *
     * x^6 + x + 1, base α¹ — the standard's field, built here rather than
     * borrowed, so that a symbol which is a codeword in some *other* field
     * fails this.
     *
     * @param list<int> $codewords
     * @return array<int, int>
     */
    private function syndromes(array $codewords): array
    {
        $exp = [];
        $log = [];
        $x = 1;
        for ($i = 0; $i < 63; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if (($x & 64) !== 0) {
                $x ^= 0b100_0011;
            }
        }

        $multiply = static fn (int $a, int $b): int => $a === 0 || $b === 0 ? 0 : $exp[($log[$a] + $log[$b]) % 63];

        $syndromes = [];
        for ($root = 1; $root <= PhpBackend::CHECK_CODEWORDS; $root++) {
            $value = 0;
            foreach ($codewords as $codeword) {
                $value = $multiply($value, $exp[$root]) ^ $codeword;
            }
            $syndromes[$root] = $value;
        }

        return $syndromes;
    }

    private function generate(string $data, Format $format = Format::Standard): Symbol
    {
        return Defaults::registry()
            ->getGenerator(Symbology::AustraliaPost->value)
            ->generate($data, new AustraliaPostOptions($format));
    }
}
