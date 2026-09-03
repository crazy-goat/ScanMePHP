<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\AsciiEncodation;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\DataMatrixOptions;
use CrazyGoat\ScanMePHP\Generator\Gs1\ApplicationIdentifier;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;
use CrazyGoat\ScanMePHP\Generator\Gs1128\Gs1128Generator;
use CrazyGoat\ScanMePHP\Generator\Gs1DataMatrix\Gs1DataMatrixGenerator;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Gs1Test extends TestCase
{
    private Scanme $scanme;

    protected function setUp(): void
    {
        $this->scanme = Scanme::create();
    }

    // -------------------------------------------------------------- parsing

    /** @return iterable<string, array{string, string}> */
    public static function payloadProvider(): iterable
    {
        // The separator rule, one shape per case. \x1d is FNC1 as a scanner
        // reports it.
        yield 'one predefined element' => ['(01)09501101020917', '0109501101020917'];
        yield 'two predefined elements' => ['(01)09501101020917(3103)000189', '01095011010209173103000189'];
        yield 'one variable element' => ['(10)ABC123', '10ABC123'];
        yield 'variable then anything' => ['(10)ABC123(11)991231', "10ABC123\x1d11991231"];
        yield 'predefined then variable' => ['(11)991231(10)ABC123', '1199123110ABC123'];
        yield 'two variable elements' => ['(90)A(91)B', "90A\x1d91B"];

        // Fixed length is not the same question as predefined length: (402) is
        // exactly seventeen digits and is still not on GS1's list.
        yield 'fixed length is not predefined length' => [
            '(402)12345678901234567(10)X',
            "40212345678901234567\x1d10X",
        ];
    }

    #[DataProvider('payloadProvider')]
    public function testTheSeparatorsGoWhereTheTableSaysTheyDo(string $elements, string $payload): void
    {
        $this->assertSame($payload, ElementString::parse($elements)->payload());
    }

    public function testATrailingSeparatorIsNeverDrawn(): void
    {
        // (10) is variable length, but nothing follows it, so there is nothing
        // to separate it from and the FNC1 would be a wasted character.
        $this->assertStringEndsNotWith(ElementString::SEPARATOR, ElementString::parse('(11)991231(10)ABC')->payload());
        $this->assertStringEndsNotWith(ElementString::SEPARATOR, ElementString::parse('(10)ABC')->payload());
    }

    public function testTheHumanReadableFormIsWhatWasWritten(): void
    {
        $elements = '(01)09501101020917(10)LOT0001';

        $this->assertSame($elements, ElementString::parse($elements)->humanReadable());
        $this->assertSame($elements, $this->scanme->generate($elements, Symbology::Gs1128)->getText());
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusedProvider(): iterable
    {
        yield 'empty' => ['', 'at least one element string'];
        yield 'no parentheses' => ['0109501101020917', 'Expected an application identifier'];
        yield 'unclosed' => ['(01', 'Unclosed application identifier'];
        yield 'not an identifier' => ['(99999)X', 'Not a GS1 application identifier'];
        yield 'identifier that does not exist' => ['(05)12345678901234', 'Not a GS1 application identifier'];
        yield 'letters in the identifier' => ['(AB)X', 'Not a GS1 application identifier'];
        yield 'data too short' => ['(01)0950110102091', 'takes exactly 14'];
        yield 'data too long' => ['(01)095011010209177', 'takes exactly 14'];
        yield 'no data at all' => ['(01)', 'takes exactly 14'];
        yield 'a length between the legal ones' => ['(7007)12345678', 'takes 6 or 12'];
        yield 'past a variable maximum' => ['(10)' . str_repeat('A', 21), 'takes 1 to 20'];
        yield 'closing parenthesis in the data' => ['(10)A)C', 'cannot express'];
        // An opening one reads as the start of the next element string,
        // so it is refused for a different and more specific reason.
        yield 'opening parenthesis in the data' => ['(10)A(B)C', 'Not a GS1 application identifier'];
    }

    #[DataProvider('refusedProvider')]
    public function testAPayloadThatIsNotGs1IsRefused(string $data, string $message): void
    {
        $this->assertFalse(ElementString::isParsable($data), "'{$data}' should not parse");
        $this->assertFalse((new Gs1128Generator())->canEncode($data));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($message, '/') . '/');
        ElementString::parse($data);
    }

    #[DataProvider('refusedProvider')]
    public function testTheFacadeRefusesItTooAndSaysWhatIsAccepted(string $data): void
    {
        $this->expectException(UnsupportedDataException::class);
        $this->scanme->generate($data, Symbology::Gs1128);
    }

    // ------------------------------------------------------- what is not checked

    /**
     * Two things a GS1 verifier checks and this does not.
     *
     * The encoder these tables were derived from validates neither the
     * character set of an identifier's data nor its check digit, so neither is
     * shipped: a table nothing can verify is worse than an absent one, because
     * it looks like a guarantee. This test exists so the boundary is stated
     * rather than discovered, and so that shipping either later has to delete
     * a test rather than quietly extend a table.
     */
    public function testDataIsNotValidatedBeyondItsLength(): void
    {
        // (3103) is a weight to three decimal places: six digits. A letter in
        // it is not a number, and this encodes it anyway.
        $this->assertTrue(ElementString::isParsable('(3103)00018A'));

        // (11) is a production date as YYMMDD. There is no thirteenth month.
        $this->assertTrue(ElementString::isParsable('(11)991301'));

        // (01) is a GTIN-14, whose last digit is a modulo 10 check. This one is
        // wrong and is encoded regardless.
        $this->assertTrue(ElementString::isParsable('(01)09501101020911'));
    }

    /**
     * Where the Data Matrix module fixture stops, and why.
     *
     * zxing-cpp switches to C40 encodation once a letter run is long enough to
     * pay for the latch. This library implements ASCII encodation only — a
     * documented choice in AsciiEncodation, not a GS1 matter — so past that
     * point the two encoders stop producing comparable symbols and
     * gs1_dm_reference.csv holds no such case. The decoder round trip carries
     * them instead.
     *
     * Pinning the codeword count here is what keeps that boundary honest: if
     * an alternative encodation ever lands, this fails and the fixture can
     * grow to cover what it could not before.
     */
    public function testLetterRunsAreWhereTheMatrixFixtureStops(): void
    {
        $payload = ElementString::parse('(01)09501101020917(21)ABCDEFGHIJ(10)LOT0001')->payload();

        // 38 bytes become 27 codewords: '010950110102091721' is 18 digits
        // pairing into 9, then ten letters at one each; '10LOT0001' is 1 + 3
        // + 2; plus the FNC1 in front and the one standing in for the
        // separator. C40 would pack three of those letters into two codewords.
        $this->assertSame(38, \strlen($payload));
        $this->assertSame(27, \count(AsciiEncodation::encodeGs1($payload)));
    }

    // ------------------------------------------------------------ the symbol

    public function testTheSymbolIsCode128BarsWithOneMoreCharacter(): void
    {
        $elements = '(10)ABC123';
        $gs1 = $this->scanme->generate($elements, Symbology::Gs1128);
        $plain = $this->scanme->generate('10ABC123', Symbology::Code128);

        // The FNC1 that marks the symbol as GS1 is one symbol character, and a
        // Code 128 character is eleven modules wide.
        $this->assertSame($plain->getWidth() + 11, $gs1->getWidth());
        $this->assertSame($gs1->getMetadataValue('characters'), $plain->getMetadataValue('characters') + 1);
    }

    public function testTheMetadataSaysWhatAScannerWillReport(): void
    {
        $symbol = $this->scanme->generate('(10)ABC123(11)991231', Symbology::Gs1128);

        $this->assertSame('gs1-128', $symbol->getMetadataValue('symbology'));
        $this->assertSame(2, $symbol->getMetadataValue('elements'));
        $this->assertSame("10ABC123\x1d11991231", $symbol->getMetadataValue('payload'));
    }

    public function testItIsRegisteredUnderItsAliases(): void
    {
        $registry = $this->scanme->getRegistry();

        foreach (['gs1-128', 'gs1128', 'ean128', 'ean-128', 'ucc128'] as $name) {
            $this->assertTrue($registry->hasGenerator($name), $name);
        }

        foreach (['gs1-data-matrix', 'gs1-datamatrix', 'gs1dm'] as $name) {
            $this->assertTrue($registry->hasGenerator($name), $name);
        }
    }

    // ------------------------------------------------------ GS1 Data Matrix

    /**
     * The two GS1 symbologies carry the same payload, spelled differently.
     *
     * FNC1 is a symbol character in Code 128 and a codeword in Data Matrix,
     * but what it separates is identical — which is the point of parsing the
     * element strings once, in a layer that knows about neither.
     */
    public function testBothGs1SymbologiesCarryTheSamePayload(): void
    {
        $elements = '(10)LOT0001(11)260101';

        $this->assertSame(
            $this->scanme->generate($elements, Symbology::Gs1128)->getMetadataValue('payload'),
            $this->scanme->generate($elements, Symbology::Gs1DataMatrix)->getMetadataValue('payload'),
        );
    }

    public function testAGs1DataMatrixIsNotAPlainOneWithParentheses(): void
    {
        $elements = '(10)LOT0001';

        // Data Matrix takes any byte string, so it will happily encode the
        // parenthesised form as literal characters — a symbol that scans,
        // carrying data no GS1 system expects. The two must not be the same
        // bars, and the plain generator must not claim to make a GS1 symbol.
        $this->assertNotSame(
            $this->scanme->generate($elements, Symbology::DataMatrix)->toModuleString(),
            $this->scanme->generate($elements, Symbology::Gs1DataMatrix)->toModuleString(),
        );
    }

    public function testTheMatrixMetadataSaysWhatAScannerWillReport(): void
    {
        $symbol = $this->scanme->generate('(10)ABC123(11)991231', Symbology::Gs1DataMatrix);

        $this->assertSame('gs1-data-matrix', $symbol->getMetadataValue('symbology'));
        $this->assertSame(2, $symbol->getMetadataValue('elements'));
        $this->assertSame("10ABC123\x1d11991231", $symbol->getMetadataValue('payload'));
    }

    #[DataProvider('refusedProvider')]
    public function testTheMatrixRefusesTheSamePayloads(string $data): void
    {
        $this->assertFalse((new Gs1DataMatrixGenerator())->canEncode($data), $data);

        $this->expectException(UnsupportedDataException::class);
        $this->scanme->generate($data, Symbology::Gs1DataMatrix);
    }

    public function testAMatrixSizeTooSmallForThePayloadIsRefused(): void
    {
        $generator = new Gs1DataMatrixGenerator();
        $elements = '(01)09501101020917(10)LOT0001(11)260101';

        // 10x10 holds three data codewords, which is not this.
        $this->assertFalse($generator->canEncode($elements, new DataMatrixOptions(size: '10x10')));
        $this->assertTrue($generator->canEncode($elements, new DataMatrixOptions(size: '26x26')));
    }

    /**
     * A GS1 payload is not offered as a Code 128 payload, and the reverse.
     *
     * Both are printable ASCII, so Code 128 will happily encode the
     * parenthesised form as literal characters — bars that scan, carrying
     * parentheses no GS1 system expects. Asking the registry which generators
     * accept a payload has to distinguish them.
     */
    public function testTheTwoSymbologiesAcceptDifferentPayloads(): void
    {
        $gs1 = new Gs1128Generator();

        $this->assertTrue($gs1->canEncode('(01)09501101020917'));
        $this->assertFalse($gs1->canEncode('SHIPMENT-4471'));

        $accepting = $this->scanme->getRegistry()->generatorsFor('(01)09501101020917');
        $this->assertContains('gs1-128', $accepting);
    }

    public function testEveryIdentifierEncodesAtEveryLengthItClaimsToAccept(): void
    {
        $encodable = 0;

        foreach (ApplicationIdentifier::all() as $ai) {
            foreach (ApplicationIdentifier::lengths($ai) as $length) {
                $elements = sprintf('(%s)%s', $ai, str_repeat('7', $length));

                $this->assertTrue(
                    ElementString::isParsable($elements),
                    "({$ai}) claims to accept {$length} characters but refuses them"
                );
                $encodable++;
            }
        }

        // Every row of the table, at every length it accepts. A table entry
        // that no payload can reach is not a table entry.
        $this->assertGreaterThan(1000, $encodable);
    }
}
