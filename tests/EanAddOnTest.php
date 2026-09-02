<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Generator\Ean\Patterns;
use CrazyGoat\ScanMePHP\Generator\Ean2\Ean2Generator;
use CrazyGoat\ScanMePHP\Generator\Ean5\Ean5Generator;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EAN-2 and EAN-5: the add-on symbols, and what makes them unlike the rest.
 *
 * Three things set them apart, and each is a way to get this wrong. They have
 * no check digit for a caller to supply, so there is nothing to reconcile and
 * a caller who passes a spare digit is passing a different add-on. They have
 * no trailing guard, so a missing right-hand quiet zone does not merely crowd
 * the symbol, it truncates it. And their parity is not decoration: for EAN-5
 * it is the only redundancy in the symbol, and for EAN-2 it is chosen by the
 * printed value modulo 4, which means four fifths of the parity space is
 * unreachable and a table shifted by one row still produces a legal-looking
 * symbol.
 *
 * The module patterns themselves are verified against zxing-cpp in
 * EanUpcReferenceTest, and the bars are verified by a real scanner in
 * DecoderRoundTripTest. What is left for this file is the behaviour around
 * them.
 */
class EanAddOnTest extends TestCase
{
    /** @return iterable<string, array{string, int, string}> */
    public static function structureProvider(): iterable
    {
        // Width is guard + digits + separators, and nothing else: 5 + 2*7 + 2
        // for an EAN-2, 5 + 5*7 + 4*2 for an EAN-5.
        yield 'ean-2' => [Symbology::Ean2->value, 21, '52'];
        yield 'ean-5' => [Symbology::Ean5->value, 48, '12345'];
    }

    #[DataProvider('structureProvider')]
    public function testAnAddOnIsGuardDigitsAndSeparators(
        string $symbology,
        int $width,
        string $data
    ): void {
        $symbol = Scanme::create()->generate($data, $symbology);
        $modules = $symbol->toModuleString();

        $this->assertSame($width, $symbol->getWidth());
        // One row: an add-on has no guards to descend below the bars.
        $this->assertSame(1, $symbol->getHeight());
        $this->assertStringStartsWith(Patterns::ADDON_START_GUARD, $modules);
        // It ends on a bar. Only the quiet zone marks the end of the symbol,
        // which is why that zone is not cosmetic here.
        $this->assertStringEndsWith('1', $modules);
        $this->assertSame($data, $symbol->getText());
    }

    #[DataProvider('structureProvider')]
    public function testTheQuietZoneLeavesRoomForTheSymbolItBelongsTo(
        string $symbology,
        int $width,
        string $data
    ): void {
        $zone = Scanme::create()->generate($data, $symbology)->getQuietZone();

        // ISO/IEC 15420: at least seven modules between a main symbol and its
        // add-on, five to the right of the add-on.
        $this->assertSame(7, $zone->left, $symbology);
        $this->assertSame(5, $zone->right, $symbology);
    }

    /**
     * Every EAN-2 value, against the parity the standard's table selects.
     *
     * Exhaustive because it can be: a hundred symbols is the whole symbology,
     * and modulo 4 over a two-digit value is exactly the kind of arithmetic
     * that is off by one in a way no sample would show.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function ean2ParityProvider(): iterable
    {
        $table = ['OO', 'OE', 'EO', 'EE'];

        for ($value = 0; $value < 100; $value++) {
            $digits = sprintf('%02d', $value);
            yield $digits => [$digits, $table[$value % 4]];
        }
    }

    #[DataProvider('ean2ParityProvider')]
    public function testEan2ParityFollowsTheValueModuloFour(string $digits, string $parity): void
    {
        $symbol = Scanme::create()->generate($digits, Symbology::Ean2->value);

        $this->assertSame($parity, $symbol->getMetadataValue('parity'));
    }

    /**
     * EAN-5's check digit is never printed, so nothing else can catch it.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function ean5CheckDigitProvider(): iterable
    {
        // Weights 3 and 9 alternating from the left, modulo 10 — and no
        // subtraction from ten, unlike every other check digit in the family.
        yield '00000' => ['00000', 0];
        yield '12345' => ['12345', 1];
        yield '51234' => ['51234', 9];
        yield '90000' => ['90000', 7];
        yield '99999' => ['99999', 3];
        yield '10000' => ['10000', 3];
        yield '01000' => ['01000', 9];
    }

    #[DataProvider('ean5CheckDigitProvider')]
    public function testEan5CheckDigitIsWeightedThreeAndNineFromTheLeft(
        string $digits,
        int $expected
    ): void {
        $this->assertSame($expected, Patterns::addOnCheckDigit($digits));
        $this->assertSame(
            $expected,
            Scanme::create()->generate($digits, Symbology::Ean5->value)->getMetadataValue('checkDigit')
        );
    }

    /**
     * The parity table is indexed by that check digit, and every row of it is
     * reachable — a table with a duplicated or missing row would let two
     * different prices choose the same parity.
     */
    public function testEveryEan5ParityPatternIsReachableAndDistinct(): void
    {
        $this->assertCount(10, Patterns::EAN5_PARITY);
        $this->assertSame(
            Patterns::EAN5_PARITY,
            array_values(array_unique(Patterns::EAN5_PARITY))
        );

        foreach (Patterns::EAN5_PARITY as $pattern) {
            $this->assertMatchesRegularExpression('/^[OE]{5}$/', $pattern);
        }

        $seen = [];
        for ($value = 0; $value < 100000; $value++) {
            $digits = sprintf('%05d', $value);
            $seen[Patterns::addOnCheckDigit($digits)] = Patterns::addOnParity($digits);

            if (\count($seen) === 10) {
                break;
            }
        }

        ksort($seen);
        $this->assertSame(Patterns::EAN5_PARITY, array_values($seen));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function rejectionProvider(): iterable
    {
        yield 'ean-2, one digit' => [Symbology::Ean2->value, '5', 'exactly 2 digits'];
        // A caller used to the rest of the family will reach for a check
        // digit. There is none, so a third digit is a different add-on and
        // not a claim about this one — refused rather than truncated.
        yield 'ean-2, three digits' => [Symbology::Ean2->value, '520', 'exactly 2 digits'];
        yield 'ean-2, not digits' => [Symbology::Ean2->value, '5A', 'exactly 2 digits'];
        yield 'ean-2, empty' => [Symbology::Ean2->value, '', 'exactly 2 digits'];
        yield 'ean-5, four digits' => [Symbology::Ean5->value, '1234', 'exactly 5 digits'];
        yield 'ean-5, six digits' => [Symbology::Ean5->value, '123456', 'exactly 5 digits'];
        yield 'ean-5, spaces' => [Symbology::Ean5->value, '12 45', 'exactly 5 digits'];
    }

    #[DataProvider('rejectionProvider')]
    public function testAnAddOnRefusesAnythingButItsOwnDigitCount(
        string $symbology,
        string $data,
        string $because
    ): void {
        $generator = Scanme::create()->getRegistry()->getGenerator($symbology);

        $this->assertFalse($generator->canEncode($data), "canEncode({$data})");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($because, '/') . '/');
        $generator->generate($data);
    }

    /**
     * A leading zero is data, not padding.
     *
     * '07' and '7' are not the same request: the second is not an EAN-2 at
     * all, and silently padding it would encode issue 7 for a caller who may
     * have meant issue 70.
     */
    public function testLeadingZerosAreKept(): void
    {
        $scanme = Scanme::create();

        $this->assertSame('07', $scanme->generate('07', Symbology::Ean2->value)->getText());
        $this->assertSame('00042', $scanme->generate('00042', Symbology::Ean5->value)->getText());
        $this->assertFalse(
            $scanme->getRegistry()->getGenerator(Symbology::Ean2->value)->canEncode('7')
        );
    }

    /**
     * addOnParity() is shared by both backends, so it has to refuse a length
     * neither of them draws rather than pick a table by accident.
     */
    public function testTheSharedParityHelperRefusesALengthThatIsNotAnAddOn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An add-on has 2 or 5 digits, got 3: 123');

        Patterns::addOnParity('123');
    }

    public function testTheAddOnsAreRegisteredAndDescribeThemselves(): void
    {
        $described = Scanme::create()->getRegistry()->describeGenerators();

        $this->assertSame(['ean2', 'ean-2'], $described['ean2']->allNames());
        $this->assertSame(['ean5', 'ean-5'], $described['ean5']->allNames());

        foreach (['ean2', 'ean5'] as $name) {
            $this->assertTrue($described[$name]->providesText, $name);
            $this->assertFalse($described[$name]->hasErrorCorrection(), $name);
            $this->assertStringContainsString('no check digit', $described[$name]->dataDescription);
        }
    }

    /**
     * An add-on is a plain linear symbol, so every renderer must take it —
     * there is nothing here for capability negotiation to refuse.
     */
    public function testEveryOutputFormatAcceptsAnAddOn(): void
    {
        $scanme = Scanme::create();

        foreach ($scanme->getRegistry()->rendererFormats() as $format) {
            foreach ([[Symbology::Ean2->value, '52'], [Symbology::Ean5->value, '51234']] as [$sym, $data]) {
                $this->assertTrue($scanme->supports($sym, $format), "{$sym} → {$format}");
                $this->assertNotSame('', $scanme->render($data, $sym, $format), "{$sym} → {$format}");
            }
        }
    }

    /** The generators expose the single pure-PHP backend the family uses. */
    public function testBothAddOnsRunOnThePurePhpBackend(): void
    {
        foreach ([new Ean2Generator(), new Ean5Generator()] as $generator) {
            $this->assertSame(['php'], $generator->getBackendSelector()->names());
            $this->assertSame('php', $generator->getActiveBackend()?->getName());
        }
    }
}
