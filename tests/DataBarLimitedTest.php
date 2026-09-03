<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\DataBar\Patterns;
use CrazyGoat\ScanMePHP\Generator\DataBarLimited\Backend\PhpBackend;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What holds for every GS1 DataBar Limited symbol, rather than the sampled
 * ones.
 *
 * The reference fixture says our modules match somebody else's for 114
 * payloads. These are the claims that have to hold for all two trillion, and
 * as with Omnidirectional they are mostly claims about the enumeration behind
 * a data character: the value is not looked up, it is counted to, so the walk
 * has to reach each legal combination exactly once. A fixture cannot say that;
 * counting can.
 */
class DataBarLimitedTest extends TestCase
{
    public function testTheSymbologyIsRegisteredAndDescribesItself(): void
    {
        $capabilities = Defaults::registry()
            ->getGenerator(Symbology::DataBarLimited->value)
            ->getCapabilities();

        $this->assertSame('GS1 DataBar Limited', $capabilities->title);
        $this->assertTrue($capabilities->providesText);
        $this->assertSame([], $capabilities->errorCorrectionLevels);
        // Nothing to choose. Omnidirectional has its truncated height;
        // Limited's height is fixed and there is no second variant of it.
        $this->assertNull($capabilities->optionsClass);
    }

    #[DataProvider('aliasProvider')]
    public function testEveryAliasResolves(string $alias): void
    {
        $this->assertSame(
            Symbology::DataBarLimited->value,
            Defaults::registry()->getGenerator($alias)->getCapabilities()->name
        );
    }

    /** @return \Generator<string, array{string}> */
    public static function aliasProvider(): \Generator
    {
        foreach (['gs1-databar-limited', 'rss-limited', 'rss-ltd'] as $alias) {
            yield $alias => [$alias];
        }
    }

    public function testEverySymbolIsTheSameWidthAndKeepsItsMarginOnOneSide(): void
    {
        foreach (['0000000000000', '1999999999999', '0590123412345'] as $data) {
            $symbol = $this->generate($data);

            $this->assertSame(PhpBackend::MODULES, $symbol->getWidth(), "width for {$data}");
            // Asymmetric, and that is the measurement. The left guard is
            // itself a space, so the margin the left side needs is already
            // inside the seventy-four; the right side has to be given five.
            $this->assertSame(0, $symbol->getQuietZone()->left);
            $this->assertSame(PhpBackend::RIGHT_QUIET_ZONE, $symbol->getQuietZone()->right);
        }
    }

    public function testBothGuardsAreASpaceThenABar(): void
    {
        $modules = $this->generate('0123456789012')->toModuleString();

        $this->assertSame('01', substr($modules, 0, 2), 'the left guard is not a space then a bar');
        $this->assertSame('01', substr($modules, -2), 'the right guard is not a space then a bar');
    }

    public function testTheTextIsTheGtinUnderItsApplicationIdentifier(): void
    {
        $this->assertSame('(01)01234567890128', $this->generate('0123456789012')->getText());
        $this->assertSame('(01)01234567890128', $this->generate('01234567890128')->getText());
        $this->assertSame('(01)01234567890128', $this->generate('(01)01234567890128')->getText());
    }

    #[DataProvider('badPayloadProvider')]
    public function testTheFacadeSaysWhyItCannotEncode(string $data): void
    {
        $this->expectException(UnsupportedDataException::class);

        (new Scanme(Defaults::registry()))->render($data, Symbology::DataBarLimited, 'svg');
    }

    /** @return \Generator<string, array{string}> */
    public static function badPayloadProvider(): \Generator
    {
        yield 'too few digits' => ['123'];
        yield 'too many digits' => ['012345678901234'];
        yield 'a letter' => ['012345678901A'];
        yield 'a wrong check digit' => ['01234567890129'];
        yield 'empty' => [''];
        yield 'a different application identifier' => ['(10)01234567890128'];
        // The one refusal Omnidirectional does not share. Two characters of
        // 2013571 values cover 0 to 1999999999999 and stop, so an indicator
        // digit of 2 is a real GTIN with no Limited symbol.
        yield 'an indicator digit of two' => ['2001234567890'];
        yield 'an indicator digit of nine' => ['9001234567890'];
    }

    /**
     * Every value is a different set of widths, and every one of them is legal.
     *
     * Two trillion payloads is too many to walk, and it does not need walking:
     * a character is a group, a set of bar widths and a set of space widths,
     * and the value indexes the last two independently. So the claim reduces to
     * the two enumerations being injective inside each group, plus the groups
     * being told apart by how many modules they spend on bars — which they are,
     * since no two of the seven share that count.
     */
    public function testEveryCharacterGroupEnumeratesABijection(): void
    {
        $table = Patterns::LIMITED;
        $offsets = [...$table['offsets'], Patterns::LIMITED_VALUES];

        $this->assertCount(7, array_unique($table['barModules']), 'two groups look alike');

        foreach ($table['offsets'] as $group => $offset) {
            $bars = $table['combinations'][$group];
            $spaces = intdiv($offsets[$group + 1] - $offset, $bars);

            $this->assertSame(
                $offsets[$group + 1] - $offset,
                $bars * $spaces,
                "group {$group} does not divide into whole characters"
            );

            $this->assertSame($bars, \count($this->sweep(
                $bars,
                $table['barModules'][$group],
                $table['widestBar'][$group],
                true
            )), "group {$group} repeats a set of bar widths");

            $this->assertSame($spaces, \count($this->sweep(
                $spaces,
                $table['spaceModules'][$group],
                $table['widestSpace'][$group],
                false
            )), "group {$group} repeats a set of space widths");
        }
    }

    /**
     * The narrow-element rule is on the bars here, and on the spaces in
     * Omnidirectional.
     *
     * Swapping the two still produces a plausible symbol, which is why it is
     * asserted rather than trusted: it changes how many combinations sit in
     * each bucket, so every value past that point scans as a different number.
     */
    public function testEveryBarGroupHasANarrowElementAndTheSpacesNeedNot(): void
    {
        $spacesAlwaysWide = true;
        $withoutANarrowBar = [];

        for ($value = 0; $value < 200_000; $value += 7) {
            $widths = Patterns::character($value, Patterns::LIMITED, true);
            $spaces = [];
            $bars = [];
            foreach ($widths as $index => $width) {
                if ($index % 2 === 0) {
                    $spaces[] = $width;
                } else {
                    $bars[] = $width;
                }
            }

            if (!\in_array(1, $bars, true)) {
                $withoutANarrowBar[] = $value;
            }
            if (!\in_array(1, $spaces, true)) {
                $spacesAlwaysWide = false;
            }
        }

        $this->assertSame(
            [],
            \array_slice($withoutANarrowBar, 0, 5),
            'a character has no narrow bar'
        );
        $this->assertFalse($spacesAlwaysWide, 'no character has spaces that are all wide');
    }

    /**
     * Eighty-nine finder patterns, one per checksum residue, nothing skipped.
     *
     * Omnidirectional splits its residue over two finders and leaves two of the
     * eighty-one pairs unreachable. Limited has one finder and exactly as many
     * patterns as residues, so every index addresses a pattern and every
     * pattern is drawn by some symbol.
     */
    public function testEveryChecksumResidueDrawsItsOwnFinder(): void
    {
        $this->assertSame(Patterns::LIMITED_MODULUS, \count(Patterns::LIMITED_FINDERS));

        $seen = [];
        foreach (array_keys(Patterns::LIMITED_FINDERS) as $checksum) {
            $finder = Patterns::limitedFinder($checksum);

            $this->assertCount(14, $finder, "finder {$checksum} is not fourteen elements");
            $this->assertSame(18, array_sum($finder), "finder {$checksum} is not eighteen modules");
            // The two single modules at the end are what a scanner sweeping
            // the label recognises; without them a finder is just more data.
            $this->assertSame([1, 1], \array_slice($finder, -2), "finder {$checksum} does not end narrow");

            $seen[implode(',', $finder)] = true;
        }

        $this->assertCount(
            Patterns::LIMITED_MODULUS,
            $seen,
            'two checksum residues draw the same finder'
        );
    }

    /**
     * The finder table is stored as pairs, and the halves are generated.
     *
     * Every one of the eighty-nine patterns is seven spaces summing to nine
     * interleaved with seven bars summing to nine, each half a composition of
     * nine into seven parts of at most three whose last part is one. There are
     * exactly twenty-one of those, which is why the table holds two small
     * indices per finder instead of fourteen widths.
     */
    public function testEveryFinderHalfIsOneOfTwentyOne(): void
    {
        $halves = [];
        for ($index = 0; $index < Patterns::LIMITED_FINDER_HALVES; $index++) {
            $half = Patterns::limitedFinderHalf($index);

            $this->assertCount(7, $half, "half {$index} is not seven widths");
            $this->assertSame(9, array_sum($half), "half {$index} is not nine modules");
            $this->assertLessThanOrEqual(3, max($half), "half {$index} has an element wider than three");
            $this->assertSame(1, $half[6], "half {$index} does not end narrow");

            $halves[implode(',', $half)] = true;
        }

        $this->assertCount(Patterns::LIMITED_FINDER_HALVES, $halves, 'two halves are the same widths');

        foreach (Patterns::LIMITED_FINDERS as $checksum => [$spaces, $bars]) {
            $this->assertLessThan(Patterns::LIMITED_FINDER_HALVES, $spaces, "finder {$checksum} spaces");
            $this->assertLessThan(Patterns::LIMITED_FINDER_HALVES, $bars, "finder {$checksum} bars");
        }
    }

    /**
     * The distinct width sets an enumeration reaches over $count values.
     *
     * Violations are collected rather than asserted one value at a time: the
     * seven groups come to some fifty thousand width sets between them, and a
     * broken enumeration is broken for a whole stretch of them, so a list of
     * the first few offenders says more than fifty thousand passing assertions.
     *
     * @return array<string, true>
     */
    private function sweep(int $count, int $modules, int $widest, bool $requireNarrow): array
    {
        $seen = [];
        $illegal = [];

        for ($value = 0; $value < $count; $value++) {
            $widths = Patterns::widths($value, $modules, 7, $widest, $requireNarrow);

            if (array_sum($widths) !== $modules
                || max($widths) > $widest
                || ($requireNarrow && !\in_array(1, $widths, true))
            ) {
                $illegal[] = $value . ': ' . implode(',', $widths);
            }

            $seen[implode(',', $widths)] = true;
        }

        $this->assertSame(
            [],
            \array_slice($illegal, 0, 5),
            "an enumeration of {$modules} modules produced widths the symbology does not allow"
        );

        return $seen;
    }

    private function generate(string $data): \CrazyGoat\ScanMePHP\Symbol
    {
        return Defaults::registry()->getGenerator(Symbology::DataBarLimited->value)->generate($data);
    }
}
