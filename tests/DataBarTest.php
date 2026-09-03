<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\DataBar\Patterns;
use CrazyGoat\ScanMePHP\Generator\DataBarOmni\Backend\PhpBackend;
use CrazyGoat\ScanMePHP\Generator\DataBarOmni\DataBarOmniOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What holds for every DataBar symbol, rather than for the sampled ones.
 *
 * The reference fixture says our symbols match somebody else's for 156
 * payloads. These are the claims that have to hold for all ten trillion of
 * them, and they are mostly claims about the enumeration behind a data
 * character — that it is a bijection onto the legal width combinations. A
 * fixture cannot say that; counting can.
 */
class DataBarTest extends TestCase
{
    public function testTheSymbologyIsRegisteredAndDescribesItself(): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::DataBarOmni->value);
        $capabilities = $generator->getCapabilities();

        $this->assertSame('GS1 DataBar Omnidirectional', $capabilities->title);
        $this->assertSame(DataBarOmniOptions::class, $capabilities->optionsClass);
        $this->assertTrue($capabilities->providesText);
        $this->assertSame([], $capabilities->errorCorrectionLevels);
    }

    #[DataProvider('aliasProvider')]
    public function testEveryAliasResolves(string $alias): void
    {
        $this->assertSame(
            Symbology::DataBarOmni->value,
            Defaults::registry()->getGenerator($alias)->getCapabilities()->name
        );
    }

    /** @return \Generator<string, array{string}> */
    public static function aliasProvider(): \Generator
    {
        foreach (['databar', 'gs1-databar', 'databar-omnidirectional', 'rss14', 'rss-14'] as $alias) {
            yield $alias => [$alias];
        }
    }

    public function testEverySymbolIsTheSameWidthAndCarriesNoQuietZone(): void
    {
        foreach (['0000000000000', '9999999999999', '5901234123457'] as $data) {
            $symbol = $this->generate($data);

            $this->assertSame(PhpBackend::MODULES, $symbol->getWidth(), "width for {$data}");
            // The guard patterns do a quiet zone's work, which is why the
            // oracle draws ninety-six modules edge to edge where it puts ten
            // either side of a Code 128.
            $this->assertSame(0, $symbol->getQuietZone()->left);
            $this->assertSame(0, $symbol->getQuietZone()->right);
        }
    }

    public function testTheSymbolOpensWithASpaceAndClosesWithABar(): void
    {
        $modules = $this->generate('01234567890128')->toModuleString();

        $this->assertSame('01', substr($modules, 0, 2), 'the left guard is not a space then a bar');
        $this->assertSame('01', substr($modules, -2), 'the right guard is not a space then a bar');
    }

    public function testTruncatingChangesTheHeightAndNothingElse(): void
    {
        $full = $this->generate('01234567890128');
        $short = $this->generate('01234567890128', new DataBarOmniOptions(truncated: true));

        $this->assertSame($full->toModuleString(), $short->toModuleString(), 'truncation moved a module');
        $this->assertSame(PhpBackend::BAR_HEIGHT, $full->getModuleHeight());
        $this->assertSame(PhpBackend::TRUNCATED_BAR_HEIGHT, $short->getModuleHeight());
    }

    public function testTheTextIsTheGtinUnderItsApplicationIdentifier(): void
    {
        // The '(01)' is not in the bars. DataBar means AI 01, so a scanner
        // reports one that was never encoded, and the printed text has to say
        // the same thing the scanner will.
        $this->assertSame('(01)01234567890128', $this->generate('0123456789012')->getText());
        $this->assertSame('(01)01234567890128', $this->generate('01234567890128')->getText());
        $this->assertSame('(01)01234567890128', $this->generate('(01)01234567890128')->getText());
    }

    #[DataProvider('badPayloadProvider')]
    public function testTheFacadeSaysWhyItCannotEncode(string $data): void
    {
        $this->expectException(UnsupportedDataException::class);

        (new Scanme(Defaults::registry()))->render($data, Symbology::DataBarOmni, 'svg');
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
    }

    /**
     * Every outside value is a different set of widths, and every one of them
     * is legal.
     *
     * This is the claim the whole symbology rests on: the value is not looked
     * up, it is *counted to*, so the enumeration has to hit each combination
     * exactly once. If it double-counts anywhere, two GTINs print the same
     * bars — which no fixture of a hundred payloads would notice.
     */
    public function testTheOutsideEnumerationIsABijection(): void
    {
        $seen = [];
        for ($value = 0; $value < Patterns::OUTSIDE_VALUES; $value++) {
            $widths = Patterns::character($value, Patterns::OUTSIDE, false);
            $seen[implode(',', $widths)] = true;

            $bars = $widths[0] + $widths[2] + $widths[4] + $widths[6];
            $spaces = $widths[1] + $widths[3] + $widths[5] + $widths[7];
            $this->assertSame(16, $bars + $spaces, "character {$value} is not sixteen modules");
        }

        $this->assertCount(Patterns::OUTSIDE_VALUES, $seen, 'two outside values share their widths');
    }

    /** The same claim for the inside characters, which start with a space. */
    public function testTheInsideEnumerationIsABijection(): void
    {
        $seen = [];
        for ($value = 0; $value < Patterns::INSIDE_VALUES; $value++) {
            $widths = Patterns::character($value, Patterns::INSIDE, true);
            $seen[implode(',', $widths)] = true;

            $this->assertSame(15, array_sum($widths), "character {$value} is not fifteen modules");
        }

        $this->assertCount(Patterns::INSIDE_VALUES, $seen, 'two inside values share their widths');
    }

    /**
     * The rule that applies to a character's spaces and not to its bars.
     *
     * Having it the wrong way round still produces a plausible symbol, which
     * is why it is asserted rather than trusted: it shifts every value past
     * the point the bucket sizes change, so the symbol scans and says
     * something else.
     */
    public function testEverySpaceGroupHasANarrowElementAndTheBarsNeedNot(): void
    {
        $barsAlwaysWide = true;

        for ($value = 0; $value < Patterns::OUTSIDE_VALUES; $value++) {
            $widths = Patterns::character($value, Patterns::OUTSIDE, false);
            $bars = [$widths[0], $widths[2], $widths[4], $widths[6]];
            $spaces = [$widths[1], $widths[3], $widths[5], $widths[7]];

            $this->assertContains(1, $spaces, "outside character {$value} has no narrow space");
            if (!\in_array(1, $bars, true)) {
                $barsAlwaysWide = false;
            }
        }

        $this->assertFalse($barsAlwaysWide, 'no outside character has bars that are all wide');
    }

    /**
     * The finders are the checksum, and two of the eighty-one pairs are never
     * used.
     *
     * There is no check character in the bars: the left finder's index is the
     * checksum over nine and the right one's is the remainder. Nine squared is
     * eighty-one and the checksum has seventy-nine values, so two pairs address
     * nothing — and which two is a decision of the standard rather than a
     * consequence of the arithmetic, so it is asserted against the range the
     * oracle's own symbols occupy.
     */
    public function testTwoFinderPairsAreNeverDrawn(): void
    {
        $seen = [];
        for ($value = 0; $value < 400_000; $value += 137) {
            $data = str_pad((string) $value, 13, '0', \STR_PAD_LEFT);
            $seen[(int) $this->generate($data)->getMetadataValue('checksum')] = true;
        }

        $this->assertArrayNotHasKey(8, $seen, 'the skipped low residue was drawn');
        $this->assertArrayNotHasKey(72, $seen, 'the skipped high residue was drawn');
        $this->assertGreaterThan(70, \count($seen), 'the checksum barely moves');

        foreach (array_keys($seen) as $checksum) {
            $this->assertLessThan(81, $checksum, 'a checksum addresses no finder pair');
            $this->assertArrayHasKey(intdiv($checksum, 9), Patterns::FINDERS);
            $this->assertArrayHasKey($checksum % 9, Patterns::FINDERS);
        }
    }

    /** Nine finder patterns, five elements each, fifteen modules each. */
    public function testTheFinderTableIsTheShapeAScannerLooksFor(): void
    {
        $this->assertCount(9, Patterns::FINDERS);

        foreach (Patterns::FINDERS as $index => $finder) {
            $this->assertCount(5, $finder, "finder {$index} is not five elements");
            $this->assertSame(15, array_sum($finder), "finder {$index} is not fifteen modules");
            // The two single modules at the end are what a scanner sweeping at
            // an angle recognises; without them a finder is just more data.
            $this->assertSame([1, 1], \array_slice($finder, -2), "finder {$index} does not end narrow");
        }
    }

    private function generate(string $data, ?DataBarOmniOptions $options = null): \CrazyGoat\ScanMePHP\Symbol
    {
        return Defaults::registry()->getGenerator(Symbology::DataBarOmni->value)->generate($data, $options);
    }
}
