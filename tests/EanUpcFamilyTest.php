<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Format;
use CrazyGoat\ScanMePHP\Generator\Ean\Patterns;
use CrazyGoat\ScanMePHP\Generator\Ean13\Ean13Generator;
use CrazyGoat\ScanMePHP\Generator\Ean8\Ean8Generator;
use CrazyGoat\ScanMePHP\Generator\UpcA\UpcAGenerator;
use CrazyGoat\ScanMePHP\Generator\UpcE\Backend\PhpBackend as UpcEBackend;
use CrazyGoat\ScanMePHP\Generator\UpcE\UpcEGenerator;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EAN-8, UPC-A and UPC-E: the properties that hold between them.
 *
 * The module patterns themselves are checked against an independent encoder in
 * EanUpcReferenceTest and against a real decoder in DecoderRoundTripTest. What
 * is left for this file is the arithmetic and the relationships — the check
 * digit, the zero-suppression rules, and the fact that a UPC-A is an EAN-13 —
 * which are exactly the places a plausible-looking wrong answer can hide.
 */
class EanUpcFamilyTest extends TestCase
{
    private Ean8Generator $ean8;

    private UpcAGenerator $upcA;

    private UpcEGenerator $upcE;

    protected function setUp(): void
    {
        $this->ean8 = new Ean8Generator();
        $this->upcA = new UpcAGenerator();
        $this->upcE = new UpcEGenerator();
    }

    /**
     * One check-digit routine for four symbologies, so it is written from the
     * right — where the weight-3 digit always is — rather than from the left.
     * These values come from the worked examples in ISO/IEC 15420.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function checkDigitProvider(): iterable
    {
        yield 'ean-13' => ['590123412345', 7];
        yield 'ean-13, another' => ['400638133393', 1];
        yield 'ean-13, zero' => ['000000000000', 0];
        yield 'ean-8' => ['9638507', 4];
        yield 'ean-8, nines' => ['9999999', 5];
        yield 'upc-a' => ['03600029145', 2];
        yield 'upc-a, eleven nines' => ['99999999999', 3];
    }

    #[DataProvider('checkDigitProvider')]
    public function testTheCheckDigitIsWeightedFromTheRight(string $payload, int $expected): void
    {
        $this->assertSame($expected, Patterns::checkDigit($payload));
    }

    /** @return iterable<string, array{string, string}> */
    public static function ean8Provider(): iterable
    {
        yield 'seven digits get a check digit' => ['9638507', '96385074'];
        yield 'eight digits are verified' => ['96385074', '96385074'];
        yield 'check digit zero' => ['0000000', '00000000'];
    }

    #[DataProvider('ean8Provider')]
    public function testEan8NormalisesAndVerifiesTheCheckDigit(string $data, string $expected): void
    {
        $symbol = $this->ean8->generate($data);

        $this->assertSame($expected, $symbol->getText());
        $this->assertSame((int) $expected[7], $symbol->getMetadataValue('checkDigit'));
        $this->assertTrue($this->ean8->canEncode($data));
    }

    public function testEan8HasTheStructureTheStandardPrescribes(): void
    {
        $symbol = $this->ean8->generate('96385074');
        $modules = $symbol->rows()[0];

        $this->assertSame(67, $symbol->getWidth(), '3 + 28 + 5 + 28 + 3 modules');
        $this->assertSame('101', substr($modules, 0, 3), 'start guard');
        $this->assertSame('01010', substr($modules, 31, 5), 'centre guard');
        $this->assertSame('101', substr($modules, 64, 3), 'end guard');

        // Symmetric, unlike EAN-13: there is no digit printed to the left of
        // the symbol, so the left zone need not make room for one.
        $this->assertSame(7, $symbol->getQuietZone()->left);
        $this->assertSame(7, $symbol->getQuietZone()->right);
    }

    public function testEan8GuardBarsDescendBelowTheOtherBars(): void
    {
        $symbol = $this->ean8->generate('96385074');
        $descenders = $symbol->rows()[1];

        $this->assertSame(2, $symbol->getHeight());
        $this->assertFalse($symbol->hasUniformRows());
        // Dark only under the three guards, so the digits printed between them
        // have somewhere to sit.
        $this->assertSame(str_repeat('0', 28), substr($descenders, 3, 28));
        $this->assertSame('01010', substr($descenders, 31, 5));
        $this->assertSame(str_repeat('0', 28), substr($descenders, 36, 28));
    }

    /**
     * The relationship the whole of North American retail rests on: the bars
     * of a UPC-A and of the EAN-13 of the same number with a leading zero are
     * the same bars. If this ever stops holding, one of the two is wrong.
     */
    public function testAUpcAIsBitForBitTheEan13OfTheSameNumber(): void
    {
        $ean13 = new Ean13Generator();

        foreach (['036000291452', '012345678905', '000000000000', '999999999993'] as $upc) {
            $this->assertSame(
                $ean13->generate('0' . $upc)->toModuleString(),
                $this->upcA->generate($upc)->toModuleString(),
                $upc
            );
        }
    }

    public function testUpcACarriesTwelveDigitsAndTheEan13ItEquals(): void
    {
        $symbol = $this->upcA->generate('03600029145');

        $this->assertSame('036000291452', $symbol->getText());
        $this->assertSame(2, $symbol->getMetadataValue('checkDigit'));
        $this->assertSame('0036000291452', $symbol->getMetadataValue('ean13'));
        $this->assertSame(9, $symbol->getQuietZone()->left);
        $this->assertSame(9, $symbol->getQuietZone()->right);
    }

    public function testUpcARejectsAWrongCheckDigitInsteadOfCorrectingIt(): void
    {
        $this->assertFalse($this->upcA->canEncode('036000291450'));

        $this->expectException(UnsupportedDataException::class);
        Scanme::create()->render('036000291450', Symbology::UpcA, Format::Svg);
    }

    /**
     * Expansion and compression have to be exact inverses over the whole
     * valid space, or a UPC-E prints one article number and scans as another.
     * Checked as one assertion over every number system and every
     * zero-suppression rule rather than a handful of examples.
     */
    public function testEveryValidUpcECompressesBackFromItsUpcA(): void
    {
        $broken = [];

        for ($system = 0; $system <= 1; $system++) {
            for ($last = 0; $last <= 9; $last++) {
                for ($prefix = 0; $prefix < 200; $prefix++) {
                    $six = sprintf('%05d%d', $prefix * 501 % 100000, $last);
                    if (!$this->obeysSuppressionRules($six)) {
                        continue;
                    }

                    $upcE = UpcEBackend::normalise($system . $six);
                    $upcA = UpcEBackend::expand($upcE);

                    if (UpcEBackend::compress($upcA) !== $upcE) {
                        $broken[] = $upcE . ' -> ' . $upcA . ' -> ' . UpcEBackend::compress($upcA);
                    }
                }
            }
        }

        $this->assertSame([], $broken);
        // Guard against a loop that silently stopped testing anything.
        $this->assertTrue($this->upcE->canEncode('04252614'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function upcECompressionProvider(): iterable
    {
        // One per rule, with the UPC-A each stands for. The last digit of the
        // UPC-E selects the rule, which is why getting rule 3 or 4 wrong is
        // invisible under any other rule.
        yield 'rule for last digit 0-2' => ['04252614', '042100005264'];
        yield 'rule for last digit 3' => ['00030037', '000300000007'];
        yield 'rule for last digit 4' => ['00001144', '000010000014'];
        yield 'rule for last digit 5-9' => ['01234565', '012345000065'];
        yield 'all zeros' => ['00000000', '000000000000'];
        yield 'number system 1' => ['10000007', '100000000007'];
    }

    #[DataProvider('upcECompressionProvider')]
    public function testUpcEExpandsToItsUpcAAndBack(string $upcE, string $upcA): void
    {
        $this->assertSame($upcA, UpcEBackend::expand($upcE));
        $this->assertSame($upcE, UpcEBackend::compress($upcA));

        // Both forms are accepted as input and produce the same symbol.
        $this->assertSame(
            $this->upcE->generate($upcE)->toModuleString(),
            $this->upcE->generate($upcA)->toModuleString()
        );
        $this->assertSame($upcA, $this->upcE->generate($upcA)->getMetadataValue('upca'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function upcERejectedProvider(): iterable
    {
        yield 'number system 2' => ['212345000069', 'number system 0 or 1'];
        yield 'zeros in no rule' => ['012345678905', 'no UPC-E form'];
        yield 'rule 3 with a low third digit' => ['00010030', 'third digit cannot be 0, 1 or 2'];
        yield 'rule 4 with a zero fourth digit' => ['00000044', 'fourth digit cannot be 0'];
        yield 'rule 5-9 with a zero fifth digit' => ['01230065', 'fifth digit cannot be 0'];
        yield 'number system 2 in upc-e form' => ['21234565', '7 or 8 digits starting with'];
        yield 'nine digits' => ['012345678', '7 or 8 digits starting with'];
        yield 'letters' => ['0123456A', '7 or 8 digits starting with'];
    }

    /**
     * A UPC-E that breaks the rules would expand to a UPC-A that compresses
     * back to a different UPC-E, so refusing it is the only honest answer:
     * there is no shorter symbol for that article number.
     */
    #[DataProvider('upcERejectedProvider')]
    public function testUpcERefusesWhatItCannotRepresent(string $data, string $because): void
    {
        $this->assertFalse($this->upcE->canEncode($data), $data);

        try {
            UpcEBackend::normalise($data);
            $this->fail("expected {$data} to be refused");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($because, $e->getMessage());
        }
    }

    public function testUpcEHasTheStructureTheStandardPrescribes(): void
    {
        $symbol = $this->upcE->generate('04252614');
        $modules = $symbol->rows()[0];

        $this->assertSame(51, $symbol->getWidth(), '3 + 42 + 6 modules, with no centre guard');
        $this->assertSame('101', substr($modules, 0, 3), 'start guard');
        $this->assertSame('010101', substr($modules, 45, 6), 'end guard');
        $this->assertSame(9, $symbol->getQuietZone()->left);
        $this->assertSame(7, $symbol->getQuietZone()->right);
        $this->assertSame('042100005264', $symbol->getMetadataValue('upca'));
    }

    /**
     * The check digit is not drawn: like EAN-13's first digit it is carried by
     * the parity pattern, so two UPC-Es differing only there must differ in
     * their bars.
     */
    public function testUpcECheckDigitIsCarriedByParityRatherThanDrawn(): void
    {
        // Nines everywhere but the last digit: valid under every rule, so all
        // twenty (number system, check digit) pairs are reachable.
        $patterns = [];
        for ($system = 0; $system <= 1; $system++) {
            for ($last = 0; $last <= 9; $last++) {
                $patterns[] = $this->upcE->generate(sprintf('%d99999%d', $system, $last))->rows()[0];
            }
        }

        $this->assertCount(20, $patterns);
        $this->assertCount(20, array_unique($patterns), 'parity must distinguish them');
    }

    public function testTheFamilyIsRegisteredAndDescribesItself(): void
    {
        $described = Scanme::create()->getRegistry()->describeGenerators();

        $this->assertSame(['ean8', 'ean-8'], $described['ean8']->allNames());
        $this->assertSame(['upc-a', 'upca', 'upc'], $described['upc-a']->allNames());
        $this->assertSame(['upc-e', 'upce'], $described['upc-e']->allNames());

        foreach (['ean8', 'upc-a', 'upc-e'] as $name) {
            $this->assertTrue($described[$name]->providesText, $name);
            $this->assertFalse($described[$name]->hasErrorCorrection(), $name);
            $this->assertNotSame('', $described[$name]->dataDescription, $name);
        }
    }

    /** The four zero-suppression rules, as constraints on the six digits drawn. */
    private function obeysSuppressionRules(string $six): bool
    {
        return match (true) {
            $six[5] === '3' => $six[2] > '2',
            $six[5] === '4' => $six[3] !== '0',
            $six[5] >= '5' => $six[4] !== '0',
            default => true,
        };
    }
}
