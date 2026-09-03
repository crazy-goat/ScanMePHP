<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoding\MaxiCode\Mode;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Exception\IncompatibleRendererException;
use CrazyGoat\ScanMePHP\Generator\Aztec\AztecOptions;
use CrazyGoat\ScanMePHP\Generator\Codabar\CodabarOptions;
use CrazyGoat\ScanMePHP\Generator\Codabar\Delimiter;
use CrazyGoat\ScanMePHP\Generator\Code39\Charset;
use CrazyGoat\ScanMePHP\Generator\Code39\Code39Options;
use CrazyGoat\ScanMePHP\Generator\Code93\Charset as Code93Charset;
use CrazyGoat\ScanMePHP\Generator\DataBarOmni\Backend\PhpBackend;
use CrazyGoat\ScanMePHP\Generator\DataBarOmni\DataBarOmniOptions;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\DataMatrixOptions;
use CrazyGoat\ScanMePHP\Generator\Ean\Composite;
use CrazyGoat\ScanMePHP\Generator\Itf\ItfOptions;
use CrazyGoat\ScanMePHP\Generator\Itf\Patterns as ItfPatterns;
use CrazyGoat\ScanMePHP\Generator\Itf14\Backend\PhpBackend as Itf14Backend;
use CrazyGoat\ScanMePHP\Generator\Itf14\Itf14Options;
use CrazyGoat\ScanMePHP\Generator\MaxiCode\MaxiCodeOptions;
use CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Options;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;
use CrazyGoat\ScanMePHP\Tests\Support\Decoder;
use CrazyGoat\ScanMePHP\Tests\Support\ScansBack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The only test in this suite that does not grade its own homework.
 *
 * Everything else verifies our encoders against tables taken from the same
 * standards those encoders implement — a closed loop that cannot detect a
 * table misread in the same direction as its test. Here an independent
 * decoder (zxing-cpp) reads the rendered PNG and tells us what it sees.
 *
 * Every symbology added to this library belongs in this file before it is
 * considered done.
 */
class DecoderRoundTripTest extends TestCase
{
    use ScansBack;

    /** zxing-cpp's own name for each symbology we ship. */
    private const FORMAT_NAMES = [
        Symbology::QrCode->value => 'QR Code',
        Symbology::Code128->value => 'Code 128',
        Symbology::Code39->value => 'Code 39',
        Symbology::Code39Extended->value => 'Code 39 Extended',
        Symbology::Code93->value => 'Code 93',
        Symbology::Codabar->value => 'Codabar',
        Symbology::Ean13->value => 'EAN-13',
        Symbology::Ean8->value => 'EAN-8',
        Symbology::UpcA->value => 'UPC-A',
        Symbology::UpcE->value => 'UPC-E',
        // ITF-14's bars are ordinary ITF, exactly as a UPC-A's are an
        // EAN-13's; the digit count and the bearer bar are what make it
        // an ITF-14, and a decoder reports both as ITF.
        Symbology::Itf->value => 'ITF',
        Symbology::Itf14->value => 'ITF',
        // GS1-128 is Code 128 bars: the FNC1 after the start code is what
        // makes a reader hand the data to a GS1 parser, and no decoder reports
        // it as a format of its own. What proves the FNC1 was seen is the text
        // coming back parenthesised.
        Symbology::Gs1128->value => 'Code 128',
        Symbology::DataMatrix->value => 'Data Matrix',
        Symbology::Aztec->value => 'Aztec',
        Symbology::Pdf417->value => 'PDF417',
        Symbology::MaxiCode->value => 'MaxiCode',
        // DataBar carries a GTIN and nothing else, so the '(01)' a scanner
        // reports was never in the bars — it is what the symbology means. The
        // text coming back parenthesised is therefore not evidence of an FNC1
        // as it is for GS1-128; it is the decoder saying which symbology it
        // thinks it read.
        Symbology::DataBarOmni->value => 'DataBar Omni',
        // As with GS1-128: the same bars, and what marks it as GS1 is an FNC1
        // the decoder reports by parenthesising what it hands back.
        Symbology::Gs1DataMatrix->value => 'Data Matrix',
        // And once more for QR, where FNC1 is a mode indicator rather than a
        // value in the alphabet. Same tell: the text comes back parenthesised
        // only if the decoder saw it and parsed against its own AI table.
        Symbology::Gs1Qr->value => 'QR Code',
    ];

    /**
     * The symbologies zxing-cpp will not report on their own.
     *
     * An add-on is not an article number — it is a fragment printed beside
     * one — and zxing-cpp has no reader for a lone EAN-2 or EAN-5, only an
     * option to pick one up next to a main symbol. So these two are gated by
     * testAnAddOnScansBackBesideAMainSymbol() instead of by a case of their
     * own, and listing them here is what keeps that substitution deliberate: a
     * symbology may be absent from FORMAT_NAMES only by appearing in this
     * list, never by being forgotten.
     *
     * If a future zxing-cpp learns to read these standalone, the composite
     * test still passes and this list should shrink.
     */
    private const NO_STANDALONE_READER = [
        Symbology::Ean2->value,
        Symbology::Ean5->value,
    ];

    public function testTheDecoderItselfIsWiredUp(): void
    {
        $this->requireDecoder();

        // Guard against the bridge quietly returning "nothing found" for
        // everything, which would make every assertion below vacuous.
        $symbols = Decoder::decode($this->renderForScanning('WIRED', Symbology::Code128->value));

        self::assertCount(1, $symbols);
        self::assertSame('WIRED', $symbols[0]['text']);
        self::assertTrue(Decoder::isAvailable());
    }

    /** Every built-in symbology must appear here. */
    public function testEveryRegisteredSymbologyHasARoundTripCase(): void
    {
        $covered = array_merge(array_keys(self::FORMAT_NAMES), self::NO_STANDALONE_READER);
        $registered = array_map(
            static fn (Symbology $s): string => $s->value,
            Symbology::cases()
        );

        sort($covered);
        sort($registered);

        self::assertSame(
            $registered,
            $covered,
            'a symbology without a decoder round-trip case is not verified against anything but itself'
        );
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function qrProvider(): iterable
    {
        yield 'url' => ['https://qrcode.crazy-goat.com', null];
        yield 'single character' => ['A', null];
        yield 'digits' => ['1234567890', null];
        yield 'punctuation' => ['{"id":42,"ok":true}', null];
        yield 'utf-8' => ['zażółć gęślą jaźń', null];
        yield 'long text' => [str_repeat('The quick brown fox. ', 20), null];
    }

    #[DataProvider('qrProvider')]
    public function testQrCodeScansBack(string $data, ?string $expected): void
    {
        $this->assertScansBack($data, Symbology::QrCode->value, self::FORMAT_NAMES['qrcode'], $expected);
    }

    /** @return iterable<string, array{ErrorCorrectionLevel}> */
    public static function eclProvider(): iterable
    {
        foreach (ErrorCorrectionLevel::cases() as $level) {
            yield $level->name => [$level];
        }
    }

    /**
     * A wrong ECC block table shows up as an unreadable symbol at exactly one
     * level, which a self-checking test would never notice.
     */
    #[DataProvider('eclProvider')]
    public function testEveryQrErrorCorrectionLevelScansBack(ErrorCorrectionLevel $level): void
    {
        $this->assertScansBack(
            'https://qrcode.crazy-goat.com/level/' . $level->name,
            Symbology::QrCode->value,
            self::FORMAT_NAMES['qrcode'],
            null,
            new QrOptions(errorCorrection: $level)
        );
    }

    /** @return iterable<string, array{int}> */
    public static function qrVersionProvider(): iterable
    {
        // Spread across the character-count-bit boundaries (9/26) and the
        // version-information threshold (7), where the format changes shape.
        foreach ([1, 6, 7, 9, 10, 26, 27, 40] as $version) {
            yield "version {$version}" => [$version];
        }
    }

    #[DataProvider('qrVersionProvider')]
    public function testForcedQrVersionsScanBack(int $version): void
    {
        $this->assertScansBack(
            'SCANME',
            Symbology::QrCode->value,
            self::FORMAT_NAMES['qrcode'],
            null,
            new QrOptions(version: $version)
        );
    }

    /** @return iterable<string, array{string}> */
    public static function code128Provider(): iterable
    {
        yield 'letters' => ['ABC-123'];
        yield 'single letter' => ['A'];
        yield 'lowercase' => ['abcxyz'];
        // Code C packs digit pairs; the switch back to B is where it breaks.
        yield 'even digits' => ['00'];
        yield 'odd digits' => ['12345'];
        yield 'long digit run' => ['1234567890123456'];
        yield 'digits then letters' => ['0123456789ABC'];
        yield 'letters then digits' => ['ABC0123456789'];
        yield 'digits around letters' => ['12345678A12345678'];
        yield 'space and symbols' => ['A B$%*+-./:C'];
        yield 'full ascii printable' => [self::printableAscii()];
    }

    private static function printableAscii(): string
    {
        $out = '';
        // Code Set A is deliberately not implemented, so start at space.
        for ($byte = 0x20; $byte <= 0x7e; $byte++) {
            $out .= \chr($byte);
        }

        return $out;
    }

    /** @return iterable<string, array{string}> */
    public static function gs1128Provider(): iterable
    {
        yield 'one predefined element' => ['(01)09501101020917'];
        yield 'two predefined elements' => ['(01)09501101020917(3103)000189'];
        yield 'a variable element alone' => ['(10)LOT0001'];
        yield 'a separator between elements' => ['(10)LOT0001(11)260101'];
        yield 'predefined before variable needs none' => ['(11)260101(10)LOT0001'];
        yield 'an SSCC' => ['(00)123456789012345678'];
        yield 'fixed length that is not predefined' => ['(402)12345678901234567(10)X'];
        yield 'three elements' => ['(01)09501101020917(10)LOT0001(11)260101'];
        yield 'digits across a separator' => ['(10)1234567(11)991231'];
        yield 'a long one' => ['(01)09501101020917(21)ABCDEFGHIJ(10)LOT0001(11)260101(17)261231'];
    }

    /**
     * The decoder reports a GS1-128 by handing back the parenthesised form.
     *
     * That is a stronger statement than the bars decoding. zxing-cpp writes
     * those parentheses only when it saw an FNC1 after the start code and
     * parsed the payload against its own application identifier table — so a
     * separator we placed wrongly comes back as different element strings, not
     * as a decode failure.
     */
    #[DataProvider('gs1128Provider')]
    public function testAGs1128ScansBackAsItsElementStrings(string $elements): void
    {
        $this->assertScansBack($elements, Symbology::Gs1128->value, self::FORMAT_NAMES['gs1-128']);
    }

    /** @return iterable<string, array{string}> */
    public static function gs1DataMatrixProvider(): iterable
    {
        yield 'one predefined element' => ['(01)09501101020917'];
        yield 'a separator between elements' => ['(10)LOT0001(11)260101'];
        yield 'an SSCC' => ['(00)123456789012345678'];
        yield 'all digits across a separator' => ['(21)123456(11)991231'];
        yield 'three elements' => ['(01)09501101020917(10)LOT0001(11)260101'];
        // Past where the module fixture can reach: this writer would use C40
        // and we use ASCII, so the symbols differ while both are correct.
        // Gs1Test::testLetterRunsAreWhereTheMatrixFixtureStops names the
        // boundary; this is what verifies the payload anyway.
        yield 'a long letter run' => ['(01)09501101020917(21)ABCDEFGHIJ(10)LOT0001'];
    }

    #[DataProvider('gs1DataMatrixProvider')]
    public function testAGs1DataMatrixScansBackAsItsElementStrings(string $elements): void
    {
        $this->assertScansBack($elements, Symbology::Gs1DataMatrix->value, self::FORMAT_NAMES['gs1-data-matrix']);
    }

    /** @return iterable<string, array{string}> */
    public static function gs1QrProvider(): iterable
    {
        yield 'one predefined element' => ['(01)09501101020917'];
        yield 'a separator between elements' => ['(10)LOT0001(11)260101'];
        yield 'an SSCC' => ['(00)123456789012345678'];
        yield 'all digits across a separator' => ['(21)123456(11)991231'];
        yield 'three elements' => ['(01)09501101020917(10)LOT0001(11)260101'];
        // Long digit runs are where this writer would segment into numeric
        // mode and we encode the lot as bytes — a larger symbol carrying the
        // same data. Gs1QrTest::testDigitRunsAreEncodedAsBytes names that;
        // this is what verifies the payload survives anyway.
        yield 'a long digit run' => ['(01)09501101020917(21)12345678901234567890'];
        yield 'past a single error correction block' => ['(240)' . str_repeat('X', 30) . '(10)LOT0001'];
    }

    #[DataProvider('gs1QrProvider')]
    public function testAGs1QrScansBackAsItsElementStrings(string $elements): void
    {
        $this->assertScansBack($elements, Symbology::Gs1Qr->value, self::FORMAT_NAMES['gs1-qr']);
    }

    /** @return iterable<string, array{string}> */
    public static function aztecProvider(): iterable
    {
        yield 'one mode' => ['HELLO'];
        yield 'a latch into lower case' => ['hello world'];
        yield 'digits' => ['0123456789'];
        yield 'the two-character punctuation codes' => ["END. NEXT, THEN: DONE\r\nLINE2"];
        yield 'a shift back to capitals' => ['helloXworld'];
        yield 'the whole punctuation table' => ['!"#$%&\'()*+,-./:;<=>?[]{}'];
        yield 'the mixed table' => ["@\\^_`|~\x7f"];
        yield 'a realistic ticket' => ['https://example.com/ticket/ABC123?seat=14C'];
        // Past compact into full, where the codeword width and the Galois
        // field both change and the reference grid appears.
        yield 'a full symbol' => [str_repeat('Mixed Case 123. ', 30)];
        yield 'ten-bit codewords' => [str_repeat('A', 400)];
        yield 'twelve-bit codewords' => [str_repeat('A', 1600)];
    }

    #[DataProvider('aztecProvider')]
    public function testAnAztecScansBack(string $data): void
    {
        $this->assertScansBack($data, Symbology::Aztec->value, self::FORMAT_NAMES['aztec']);
    }

    /** @return iterable<string, array{string}> */
    public static function aztecBinaryProvider(): iterable
    {
        yield 'high bytes' => ["\x80\xff\x00\x7f"];
        yield 'the control characters no mode holds' => ["A\x0e\x1aB"];
        // The binary run's length field is five bits up to 31 bytes and
        // sixteen after that. Both sides of that and the step across it.
        yield 'a run of thirty-one' => ['X' . str_repeat("\x80", 31) . 'Y'];
        yield 'a run of thirty-two' => ['X' . str_repeat("\x80", 32) . 'Y'];
        yield 'a run of thirty-three' => ['X' . str_repeat("\x80", 33) . 'Y'];
        yield 'a long run' => [str_repeat("\x00\xff", 200)];
    }

    #[DataProvider('aztecBinaryProvider')]
    public function testAnAztecWithBinaryDataScansBack(string $data): void
    {
        $this->assertBytesScanBack($data, Symbology::Aztec->value, self::FORMAT_NAMES['aztec']);
    }

    /**
     * A pinned size and a raised percentage both still scan.
     *
     * An option that can produce an unreadable symbol is a way to hand a caller
     * a barcode that fails at the gate, so every value the option accepts has
     * to survive a real scanner — the same reason every QR mask is scanned.
     *
     * @return iterable<string, array{AztecOptions}>
     */
    public static function aztecOptionProvider(): iterable
    {
        foreach ([0, 23, 33, 50, 90] as $percent) {
            yield sprintf('%d%% error correction', $percent) => [new AztecOptions(errorCorrectionPercent: $percent)];
        }

        foreach ([15, 27, 31, 37, 57, 67, 113] as $size) {
            yield sprintf('pinned to %d modules', $size) => [new AztecOptions(size: $size)];
        }
    }

    /** @return iterable<string, array{string}> */
    /**
     * MaxiCode's payload, in the plain mode.
     *
     * Every case here is a code set decision, because that is the whole of
     * MaxiCode's high-level encoding: five overlapping sets, and a search that
     * picks which to be in. The upper half of Latin-1 gets its own cases
     * because sets C, D and E are where an off-by-one in a table hides — those
     * bytes never appear in ordinary text, so nothing else would catch it.
     *
     * @return iterable<string, array{string}>
     */
    public static function maxiCodeProvider(): iterable
    {
        yield 'capitals only, which is set A alone' => ['HELLO WORLD'];
        yield 'lower case, so a latch into set B' => ['hello world'];
        yield 'mixed case, so latches both ways' => ['MiXeD CaSe TeXt'];
        // One and two capitals inside a lower-case run are the reason set B has
        // a two- and a three-character shift; three or more make a latch cheaper.
        yield 'one capital inside lower case' => ['abcXdef'];
        yield 'two capitals inside lower case' => ['abcXYdef'];
        yield 'three capitals inside lower case' => ['abcXYZdef'];
        yield 'four capitals inside lower case' => ['abcWXYZdef'];
        yield 'digits short enough to stay in set A' => ['AB 10001'];
        // Nine is the only run length that compacts, so these straddle the seam.
        yield 'eight digits' => ['12345678'];
        yield 'exactly nine digits' => ['123456789'];
        yield 'ten digits' => ['1234567890'];
        yield 'eighteen digits' => [str_repeat('9', 18)];
        yield 'the punctuation split across sets A and B' => ['!"#$%&\'()*+,-./:;<=>?@[]^_`{|}~'];
        yield 'a realistic label' => ['SHIP TO 123 MAIN ST APT 4'];
        yield 'as much as the plain mode holds' => [str_repeat('A', 93)];
    }

    #[DataProvider('maxiCodeProvider')]
    public function testAMaxiCodeScansBack(string $data): void
    {
        $this->assertScansBack($data, Symbology::MaxiCode->value, self::FORMAT_NAMES['maxicode']);
    }

    /**
     * The bytes no code set holds by itself.
     *
     * Between them the five sets cover all 256 values, which makes MaxiCode the
     * only symbology here that reaches every byte without a binary mode — and
     * the only one where getting a table wrong produces a symbol that scans
     * back as a different character rather than not at all.
     *
     * @return iterable<string, array{string}>
     */
    public static function maxiCodeBinaryProvider(): iterable
    {
        yield 'set C, the upper case accents' => ["\xC0\xC1\xC2\xD9\xDA\xDF"];
        yield 'set D, the lower case accents' => ["\xE0\xE1\xE2\xF9\xFA\xFF"];
        yield 'set E, the control characters' => ["\x00\x01\x02\x1A\x1B\x1F"];
        yield 'the C1 range, which is split across three sets' => ["\x80\x8A\x95\x9F"];
        yield 'every set in one payload' => ["A a \xC0 \xE0 \x01 \xA0"];
        yield 'a byte from each of the four corners' => ["\x00\x7F\x80\xFF"];
    }

    #[DataProvider('maxiCodeBinaryProvider')]
    public function testAMaxiCodeBinaryPayloadScansBack(string $data): void
    {
        $this->assertBytesScanBack($data, Symbology::MaxiCode->value, self::FORMAT_NAMES['maxicode']);
    }

    /**
     * The structured carrier message, which the decoder hands back in front of
     * the payload separated by group separators — which it escapes as "<GS>",
     * so that is what the expectations hold.
     *
     * That is the point of modes 2 and 3 and the reason they are worth the nine
     * codewords they cost: the routing block is a field a reader reports
     * separately, not a prefix glued onto the data.
     *
     * @return iterable<string, array{MaxiCodeOptions, string, string}>
     */
    public static function maxiCodeStructuredProvider(): iterable
    {
        yield 'a nine digit postcode' => [
            new MaxiCodeOptions(Mode::NumericPostcode, '339788292', 28, 146),
            'PARCEL',
            '339788292<GS>028<GS>146<GS>PARCEL',
        ];
        yield 'a short numeric postcode' => [
            new MaxiCodeOptions(Mode::NumericPostcode, '1234', 840, 1),
            'PARCEL',
            '1234<GS>840<GS>001<GS>PARCEL',
        ];
        yield 'a six character postcode' => [
            new MaxiCodeOptions(Mode::AlphanumericPostcode, 'AB1234', 826, 999),
            'UK PARCEL',
            'AB1234<GS>826<GS>999<GS>UK PARCEL',
        ];
        // Shorter than six, so the field is padded — and the padding comes back,
        // because six positions is what the field is.
        yield 'a postcode shorter than the field' => [
            new MaxiCodeOptions(Mode::AlphanumericPostcode, 'W1A', 826, 1),
            'X',
            'W1A   <GS>826<GS>001<GS>X',
        ];
        yield 'as much as a structured mode holds' => [
            new MaxiCodeOptions(Mode::NumericPostcode, '12345', 616, 42),
            str_repeat('B', 84),
            '12345<GS>616<GS>042<GS>' . str_repeat('B', 84),
        ];
    }

    #[DataProvider('maxiCodeStructuredProvider')]
    public function testAStructuredMaxiCodeScansBack(MaxiCodeOptions $options, string $data, string $expected): void
    {
        $this->assertScansBack(
            $data,
            Symbology::MaxiCode->value,
            self::FORMAT_NAMES['maxicode'],
            $expected,
            $options,
        );
    }

    /**
     * GTINs that walk the seams of DataBar's arithmetic.
     *
     * The interesting payloads are not realistic article numbers, they are the
     * values where the encoding changes shape: the point the thirteen digits
     * split around, the first and last value a character group holds, and the
     * ends of the range. A GTIN in the middle of a group exercises one bucket
     * of the enumeration; these exercise its edges.
     *
     * @return iterable<string, array{string}>
     */
    public static function dataBarOmniProvider(): iterable
    {
        yield 'the smallest GTIN' => ['0000000000000'];
        yield 'the largest GTIN' => ['9999999999999'];
        yield 'one below the split' => ['0000004537076'];
        yield 'the split itself' => ['0000004537077'];
        yield 'one above the split' => ['0000004537078'];
        yield 'the last value of the first inside group' => ['0000000000335'];
        yield 'the first value of the second inside group' => ['0000000000336'];
        yield 'the last value of the first outside group' => ['0000000255520'];
        yield 'a book number' => ['9780306406157'];
        yield 'a fourteen-digit GTIN with its check digit' => ['01234567890128'];
        yield 'the application identifier spelled out' => ['(01)01234567890128'];
        yield 'an indicator digit other than zero' => ['5901234123457'];
    }

    #[DataProvider('dataBarOmniProvider')]
    public function testADataBarOmniScansBack(string $data): void
    {
        $digits = PhpBackend::normalise($data);

        $this->assertScansBack(
            $data,
            Symbology::DataBarOmni->value,
            self::FORMAT_NAMES['databar-omni'],
            '(01)' . $digits,
        );
    }

    /**
     * The bars do not change when the symbol is printed short.
     *
     * GS1 lists DataBar Truncated as its own symbology, and it is the same
     * ninety-six modules at 13X instead of 33X. What is given up is the
     * omnidirectional scan, not the data — so a truncated symbol must still
     * read back as the same number, and this is what says the option is a
     * height and nothing more.
     */
    public function testATruncatedDataBarCarriesTheSameNumber(): void
    {
        $this->assertScansBack(
            '01234567890128',
            Symbology::DataBarOmni->value,
            self::FORMAT_NAMES['databar-omni'],
            '(01)01234567890128',
            new DataBarOmniOptions(truncated: true),
        );
    }

    public static function pdf417Provider(): iterable
    {
        yield 'capitals only' => ['HELLO WORLD'];
        yield 'a latch into lower case' => ['hello world'];
        yield 'mixed case, so latches both ways' => ['MiXeD CaSe TeXt'];
        yield 'digits short enough to stay in text' => ['AB 10001'];
        yield 'digits long enough for numeric compaction' => [str_repeat('9', 30)];
        // The numeric group is forty-four digits, so these straddle the seam
        // where a forty-fifth digit opens a second group.
        yield 'a full numeric group' => [str_repeat('1', 44)];
        yield 'one digit past a group' => [str_repeat('1', 45)];
        yield 'two groups' => [str_repeat('1', 88)];
        yield 'the punctuation submode' => ['!"#$%&\'()*+,-./:;<=>?@[]^_`{|}~'];
        yield 'the characters that live in two submodes' => ['N.Y., NY 10001 $1,234.56 A/B*C'];
        yield 'the three control characters text compaction holds' => ["A\tB\rC\nD"];
        yield 'a realistic label' => ['SHIP TO: 123 Main St., Apt 4, Springfield IL 62704'];
        yield 'long enough to need many rows' => [str_repeat('Mixed Case 123. ', 40)];
    }

    #[DataProvider('pdf417Provider')]
    public function testAPdf417ScansBack(string $data): void
    {
        $this->assertScansBack($data, Symbology::Pdf417->value, self::FORMAT_NAMES['pdf417']);
    }

    /** @return iterable<string, array{string}> */
    public static function pdf417BinaryProvider(): iterable
    {
        yield 'high bytes' => ["\x80\xff\x00\x7f"];
        yield 'a single foreign byte, which costs a shift' => ["AB\xc8CD"];
        // Byte compaction converts groups of six and writes any tail one byte
        // per codeword, so the interesting lengths are around a group.
        yield 'five bytes, all tail' => [str_repeat("\xc8", 5)];
        yield 'exactly one group' => [str_repeat("\xc8", 6)];
        yield 'a group and a tail' => [str_repeat("\xc8", 7)];
        yield 'two whole groups' => [str_repeat("\xc8", 12)];
        yield 'a long binary run' => [str_repeat("\x00\xff", 150)];
        // A driving licence's AAMVA header carries a record separator, which
        // no text submode holds, so a real one is a binary payload however
        // much of it reads as text.
        yield 'a licence header' => ["@\n\x1e\rANSI 636000100102DL00410278ZV03190008DL"];
    }

    #[DataProvider('pdf417BinaryProvider')]
    public function testAPdf417WithBinaryDataScansBack(string $data): void
    {
        $this->assertBytesScanBack($data, Symbology::Pdf417->value, self::FORMAT_NAMES['pdf417']);
    }

    /**
     * Every shape and level the options accept still scans.
     *
     * The shape is the caller's to choose, which means a caller can ask for a
     * symbol one column wide and ninety rows tall. That has to be readable,
     * for the same reason every QR mask and every Aztec size is scanned here:
     * an option that can produce an unreadable symbol is a way to hand someone
     * a barcode that fails at the gate.
     *
     * @return iterable<string, array{string, Pdf417Options}>
     */
    public static function pdf417OptionProvider(): iterable
    {
        foreach (range(0, 8) as $level) {
            // Level 8 spends 512 codewords on error correction, which needs
            // room: six columns is the narrowest shape that holds it.
            yield sprintf('error correction level %d', $level) => [
                'SCANME',
                new Pdf417Options(errorCorrectionLevel: $level, columns: 6),
            ];
        }

        foreach ([1, 2, 5, 10, 20, 30] as $columns) {
            yield sprintf('%d data columns', $columns) => [
                'SCANME 12345',
                new Pdf417Options(columns: $columns),
            ];
        }

        yield 'a row floor well above what the data needs' => [
            'SCANME',
            new Pdf417Options(columns: 3, rows: 40),
        ];
        yield 'rows one module tall' => ['SCANME', new Pdf417Options(rowHeight: 1)];
        yield 'rows ten modules tall' => ['SCANME', new Pdf417Options(rowHeight: 10)];
    }

    #[DataProvider('pdf417OptionProvider')]
    public function testAPdf417ScansBackWithEveryOption(string $data, Pdf417Options $options): void
    {
        $this->assertScansBack(
            $data,
            Symbology::Pdf417->value,
            self::FORMAT_NAMES['pdf417'],
            generatorOptions: $options
        );
    }

    #[DataProvider('aztecOptionProvider')]
    public function testAnAztecScansBackWithEveryOption(AztecOptions $options): void
    {
        $this->assertScansBack(
            'SCANME',
            Symbology::Aztec->value,
            self::FORMAT_NAMES['aztec'],
            generatorOptions: $options
        );
    }

    /** @return iterable<string, array{int}> */
    public static function maskProvider(): iterable
    {
        for ($mask = QrOptions::MIN_MASK; $mask <= QrOptions::MAX_MASK; $mask++) {
            yield sprintf('mask %d', $mask) => [$mask];
        }
    }

    /**
     * All eight maskings scan. That is the claim the option rests on.
     *
     * Pinning a mask is only a safe thing to expose if every choice produces a
     * readable symbol — otherwise the option is a way to hand a caller a
     * barcode that fails at the till. The automatic path picks one of these
     * eight, so this also widens what the round trip covers for plain QR.
     */
    #[DataProvider('maskProvider')]
    public function testAQrScansBackAtEveryMask(int $mask): void
    {
        $this->assertScansBack(
            'https://example.com/order/4471',
            Symbology::QrCode->value,
            self::FORMAT_NAMES['qrcode'],
            generatorOptions: new QrOptions(mask: $mask),
        );
    }

    #[DataProvider('maskProvider')]
    public function testAGs1QrScansBackAtEveryMask(int $mask): void
    {
        $this->assertScansBack(
            '(01)09501101020917(10)LOT0001',
            Symbology::Gs1Qr->value,
            self::FORMAT_NAMES['gs1-qr'],
            generatorOptions: new QrOptions(mask: $mask),
        );
    }

    #[DataProvider('code128Provider')]
    public function testCode128ScansBack(string $data): void
    {
        $this->assertScansBack($data, Symbology::Code128->value, self::FORMAT_NAMES['code128']);
    }

    /** @return iterable<string, array{string}> */
    public static function code39Provider(): iterable
    {
        yield 'letters' => ['SCANME'];
        yield 'single letter' => ['A'];
        yield 'single digit' => ['0'];
        yield 'digits' => ['1234567890'];
        yield 'the whole alphabet' => ['ABCDEFGHIJKLMNOPQRSTUVWXYZ'];
        yield 'space and punctuation' => ['A B-C.D'];
        // The four shift characters as ordinary data, which is what they are
        // in this mode — see testAStandardSymbolIsAmbiguousUntilTheReaderIsTold.
        yield 'shift characters as data' => ['A$B/C+D%E'];
        yield 'the whole character set' => [Charset::CHARACTERS];
        yield 'long payload' => [str_repeat('CODE39-', 8) . 'END'];
    }

    /**
     * Standard Code 39, read as standard Code 39.
     *
     * The decoder has to be told, and not for convenience: with every format
     * enabled zxing-cpp prefers the extended reading, and the payloads above
     * that contain '$', '/', '+' or '%' then come back as different strings.
     * That is a property of the symbology — the bars do not say which reading
     * is meant — and asking for Code39Std is asking the question the caller
     * asked, exactly as asking for UPCA is above.
     */
    #[DataProvider('code39Provider')]
    public function testCode39ScansBack(string $data): void
    {
        $this->assertScansBack(
            $data,
            Symbology::Code39->value,
            self::FORMAT_NAMES['code39'],
            null,
            null,
            'Code39Std'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function code39ExtendedProvider(): iterable
    {
        // Every case here has to contain at least one byte outside the 43,
        // because a payload that does not is byte for byte a standard symbol
        // and zxing-cpp reports no extended reading for it at all — see
        // testAnExtendedSymbolWithNothingToEscapeIsAStandardSymbol.
        yield 'lowercase' => ['hello'];
        yield 'mixed case' => ['Hello World'];
        yield 'underscore' => ['a-b_c'];
        yield 'punctuation' => ['{"id":42}'];
        yield 'shift characters as data' => ['A$B/C+D%E'];
        yield 'price' => ['$100 / 50% + tax'];
        yield 'at and backtick' => ['user@host `x`'];
        yield 'brackets' => ['[a](b){c}'];
        yield 'printable range' => [self::extendedPrintable()];
    }

    /** The printable bytes, minus the one Code 39 cannot carry at all. */
    private static function extendedPrintable(): string
    {
        $out = '';
        for ($byte = 0x20; $byte <= 0x7e; $byte++) {
            // '*' is the start and stop character. It has an escape ('/J') and
            // this library emits it, but zxing-cpp's reader stops at it, so a
            // sweep including it would be testing the decoder's tolerance
            // rather than our encoding — testAsteriskIsEncodableInExtendedMode
            // covers it on its own terms.
            if ($byte !== 0x2a) {
                $out .= \chr($byte);
            }
        }

        return $out;
    }

    #[DataProvider('code39ExtendedProvider')]
    public function testCode39ExtendedScansBack(string $data): void
    {
        $this->assertScansBack(
            $data,
            Symbology::Code39Extended->value,
            self::FORMAT_NAMES['code39ext'],
            null,
            null,
            'Code39Ext'
        );
    }

    /**
     * The Code 39 ambiguity, demonstrated rather than described.
     *
     * 'A$B' is three perfectly ordinary standard characters. Handed to a
     * decoder with nothing specified, it comes back as 'A' followed by STX,
     * because '$B' is the full-ASCII escape for byte 2. Nothing is wrong with
     * the bars; the reading mode is not in them.
     *
     * This is why Mode is a symbology rather than an option: a caller has to
     * decide which reading their scanner is configured for, and no amount of
     * encoding care can make the decision for them.
     */
    public function testAStandardSymbolIsAmbiguousUntilTheReaderIsTold(): void
    {
        $this->requireDecoder();

        $png = $this->renderForScanning('A$B', Symbology::Code39->value);

        $asExtended = Decoder::decode($png);
        self::assertCount(1, $asExtended);
        self::assertSame('Code 39 Extended', $asExtended[0]['format']);
        self::assertSame([65, 2], $asExtended[0]['bytes'], 'the decoder read "$B" as an escape');

        $asStandard = Decoder::decode($png, 'Code39Std');
        self::assertCount(1, $asStandard);
        self::assertSame('Code 39', $asStandard[0]['format']);
        self::assertSame('A$B', $asStandard[0]['text']);
    }

    /**
     * The same fact from the other side: an extended payload that needs no
     * escape produces the standard symbol, and there is no extended reading of
     * it to be had.
     */
    public function testAnExtendedSymbolWithNothingToEscapeIsAStandardSymbol(): void
    {
        $this->requireDecoder();

        $scanme = Scanme::create();
        self::assertSame(
            $scanme->generate('HELLO', Symbology::Code39->value)->toModuleString(),
            $scanme->generate('HELLO', Symbology::Code39Extended->value)->toModuleString(),
            'a payload inside the 43 characters encodes identically in both modes'
        );

        $png = $this->renderForScanning('HELLO', Symbology::Code39Extended->value);

        self::assertSame([], Decoder::decode($png, 'Code39Ext'));
        self::assertSame('HELLO', Decoder::decode($png, 'Code39Std')[0]['text']);
    }

    /**
     * The check character is a data character to any reader not verifying it.
     *
     * zxing-cpp has no option to verify a Code 39 check character, so it
     * reports one as a trailing character of the payload. That is not a fault
     * in either encoder or decoder — it is why the option is off by default,
     * and why the check character is kept out of the human-readable line.
     */
    public function testTheCheckCharacterIsReadAsATrailingDataCharacter(): void
    {
        $this->requireDecoder();

        $symbol = Scanme::create()->generate(
            'SCANME-42',
            Symbology::Code39->value,
            new Code39Options(checkCharacter: true)
        );

        // Unweighted modulo 43 over S C A N M E - 4 2, which is 151.
        self::assertSame('M', $symbol->getMetadataValue('checkCharacter'));
        self::assertSame('SCANME-42', $symbol->getText(), 'the check character is not printed');

        $png = $this->renderForScanning(
            'SCANME-42',
            Symbology::Code39->value,
            new Code39Options(checkCharacter: true)
        );

        self::assertSame('SCANME-42M', Decoder::decode($png, 'Code39Std')[0]['text']);
    }

    /** A wide element of three modules is as legal as one of two, and as readable. */
    public function testAWiderRatioStillScans(): void
    {
        $this->assertScansBack(
            'RATIO-3',
            Symbology::Code39->value,
            self::FORMAT_NAMES['code39'],
            null,
            new Code39Options(wideRatio: 3),
            'Code39Std'
        );
    }

    /**
     * Control bytes, which the reference fixture proves we draw correctly and
     * this proves a scanner recovers.
     *
     * Two things make this its own test rather than a provider row. The
     * comparison is on bytes, because zxing-cpp puts a mnemonic like <STX> in
     * its text field. And the symbol has to be rendered without its
     * human-readable line: a control byte has no glyph, and the renderer
     * refuses rather than drawing a box — which is the right answer for a
     * label a person is meant to read, and is asserted here so the refusal is
     * not mistaken for a limit on what Code 39 can carry.
     */
    public function testControlBytesSurviveExtendedModeButCannotBePrinted(): void
    {
        $this->requireDecoder();

        $data = "\x01\x09\x0a\x1f\x7f";
        $scanme = Scanme::create();

        try {
            $scanme->render($data, Symbology::Code39Extended->value, 'png', new PngOptions(moduleSize: 6));
            self::fail('a control byte has no glyph, so the human-readable line cannot be drawn');
        } catch (IncompatibleRendererException $expected) {
            self::assertStringContainsString('no glyph', $expected->getMessage());
        }

        $png = $scanme->render(
            $data,
            Symbology::Code39Extended->value,
            'png',
            new PngOptions(moduleSize: 6, showText: false)
        );
        $symbols = Decoder::decode($png, 'Code39Ext');

        self::assertCount(1, $symbols);
        self::assertSame([1, 9, 10, 31, 127], $symbols[0]['bytes']);
    }

    /**
     * '*' is the one byte with an escape that no reader here will accept.
     *
     * The standard makes '/J' mean '*', and this library emits it, but a
     * decoder stops the symbol at the '*' pattern — so what can be gated is
     * that we encode it as the escape and not as the guard, which would end
     * the symbol early and truncate the payload.
     */
    public function testAsteriskIsEncodableInExtendedMode(): void
    {
        $symbol = Scanme::create()->generate('A*B', Symbology::Code39Extended->value);

        self::assertSame('A/JB', $symbol->getMetadataValue('characters'));
        self::assertSame(
            Charset::width(4, 2),
            $symbol->getWidth(),
            'four characters between the guards, not three'
        );
    }

    /** @return iterable<string, array{string, CodabarOptions}> */
    public static function codabarProvider(): iterable
    {
        $default = new CodabarOptions();

        yield 'digits' => ['123456', $default];
        yield 'a membership number' => ['4917234', $default];
        yield 'punctuation' => ['1-2$3', $default];
        yield 'the ratio punctuation' => ['12:34/56.78+90', $default];
        yield 'zeros' => ['00000000', $default];
        yield 'nines' => ['99999999', $default];
        yield 'every data character' => ['0123456789-$:/.+', $default];
        // The delimiters carry no data but a scanner reports them, so every
        // pair has to come back as the pair that was asked for.
        yield 'a to b' => ['123456', new CodabarOptions(stop: Delimiter::B)];
        yield 'b to c' => ['123456', new CodabarOptions(start: Delimiter::B, stop: Delimiter::C)];
        yield 'c to d' => ['123456', new CodabarOptions(start: Delimiter::C, stop: Delimiter::D)];
        yield 'd to d' => ['123456', new CodabarOptions(start: Delimiter::D, stop: Delimiter::D)];
        yield 'the wider ratio' => ['123456', new CodabarOptions(wideRatio: 3)];
    }

    /**
     * The payload here is the data alone, and the delimiters come from the
     * options — but a scanner reports them, so what comes back is the sequence
     * the symbol actually carries. That difference is the whole reason
     * getText() and the 'characters' metadata are two different things, so the
     * round trip asserts against the metadata.
     */
    #[DataProvider('codabarProvider')]
    public function testCodabarScansBack(string $data, CodabarOptions $options): void
    {
        $this->requireDecoder();

        $symbol = Scanme::create()->generate($data, Symbology::Codabar->value, $options);

        $this->assertScansBack(
            $data,
            Symbology::Codabar->value,
            self::FORMAT_NAMES['codabar'],
            (string) $symbol->getMetadataValue('characters'),
            $options
        );

        self::assertSame($data, $symbol->getText(), 'the delimiters are not printed');
    }

    /**
     * As with a very short ITF, a one-character Codabar is below what
     * zxing-cpp will report — its own writer produces the same unreadable
     * symbol. Pinned so the absence of such a case above is a decision.
     */
    public function testAOneCharacterCodabarIsBelowTheDecodersFloor(): void
    {
        $this->requireDecoder();

        self::assertSame([], Decoder::decode($this->renderForScanning('0', Symbology::Codabar->value)));
        self::assertNotSame(
            [],
            Decoder::decode($this->renderForScanning('123456', Symbology::Codabar->value))
        );
    }

    /** @return iterable<string, array{string}> */
    public static function code93Provider(): iterable
    {
        yield 'letters' => ['SCANME'];
        yield 'single letter' => ['A'];
        yield 'single digit' => ['0'];
        yield 'digits' => ['1234567890'];
        yield 'the whole data set' => [Code93Charset::CHARACTERS];
        yield 'lowercase' => ['hello'];
        yield 'mixed case' => ['Hello World'];
        // The four bytes Code 39 Extended has to escape and this symbology
        // does not — see testCode93HasOnlyOneReadingOfAShiftCharacter.
        yield 'shift characters as data' => ['A$B/C+D%E'];
        yield 'punctuation' => ['{"id":42}'];
        yield 'underscore' => ['a-b_c'];
        // The guard is its own pattern rather than a character, so unlike
        // Code 39 an asterisk in the payload cannot end the symbol early.
        yield 'asterisk' => ['A*B'];
        // Past the wrap of both check-character weight cycles, where a running
        // index that never starts over produces a symbol no scanner accepts.
        yield 'past both weight cycles' => [str_repeat('CODE93-', 5) . 'END'];
        yield 'printable range' => [self::printableAscii()];
    }

    #[DataProvider('code93Provider')]
    public function testCode93ScansBack(string $data): void
    {
        $this->assertScansBack($data, Symbology::Code93->value, self::FORMAT_NAMES['code93']);
    }

    /**
     * The one substantive difference from Code 39, from the decoder's side.
     *
     * Standard Code 39 printing 'A$B' comes back as two characters unless the
     * reader is told which mode to use, because there a shift is spelled with
     * a data character. Code 93's shifts have bars of their own, so the same
     * payload has one reading and needs no format hint at all.
     */
    public function testCode93HasOnlyOneReadingOfAShiftCharacter(): void
    {
        $this->requireDecoder();

        $symbols = Decoder::decode($this->renderForScanning('A$B', Symbology::Code93->value));

        self::assertCount(1, $symbols);
        self::assertSame('Code 93', $symbols[0]['format']);
        self::assertSame('A$B', $symbols[0]['text']);
        self::assertSame([65, 36, 66], $symbols[0]['bytes'], 'three characters, not two');
    }

    /**
     * Control bytes again, and again unprintable — the renderer refuses the
     * human-readable line for the same reason it does in Code 39 Extended.
     */
    public function testCode93CarriesControlBytes(): void
    {
        $this->requireDecoder();

        $data = "\x01\x09\x0a\x1f\x7f";
        $png = Scanme::create()->render(
            $data,
            Symbology::Code93->value,
            'png',
            new PngOptions(moduleSize: 6, showText: false)
        );
        $symbols = Decoder::decode($png, 'Code93');

        self::assertCount(1, $symbols);
        self::assertSame([1, 9, 10, 31, 127], $symbols[0]['bytes']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function ean13Provider(): iterable
    {
        // Real GTINs, plus the 12-digit form where we compute the check digit.
        yield 'polish gtin' => ['5901234123457', '5901234123457'];
        yield 'german gtin' => ['4006381333931', '4006381333931'];
        yield 'computed check digit' => ['590123412345', '5901234123457'];
        yield 'leading zeros' => ['0000000000000', '0000000000000'];
        yield 'nines' => ['9999999999994', '9999999999994'];
        yield 'book isbn' => ['9788375780642', '9788375780642'];
    }

    #[DataProvider('ean13Provider')]
    public function testEan13ScansBack(string $data, string $expected): void
    {
        $this->assertScansBack($data, Symbology::Ean13->value, self::FORMAT_NAMES['ean13'], $expected);
    }

    /** @return iterable<string, array{string, string}> */
    public static function ean8Provider(): iterable
    {
        yield 'gtin-8' => ['96385074', '96385074'];
        yield 'sequential' => ['12345670', '12345670'];
        yield 'computed check digit' => ['9638507', '96385074'];
        yield 'leading zeros' => ['00000000', '00000000'];
        yield 'nines' => ['99999995', '99999995'];
    }

    #[DataProvider('ean8Provider')]
    public function testEan8ScansBack(string $data, string $expected): void
    {
        $this->assertScansBack($data, Symbology::Ean8->value, self::FORMAT_NAMES['ean8'], $expected);
    }

    /** @return iterable<string, array{string, string}> */
    public static function upcAProvider(): iterable
    {
        // The decoder reports the family in its normalised thirteen-digit
        // form, so a UPC-A comes back with the leading zero an EAN-13 would
        // have had. That is what a scanner at a till hands the software.
        yield 'real upc' => ['036000291452', '0036000291452'];
        yield 'sequential' => ['012345678905', '0012345678905'];
        yield 'computed check digit' => ['03600029145', '0036000291452'];
        yield 'leading zeros' => ['000000000000', '0000000000000'];
        yield 'nines' => ['999999999993', '0999999999993'];
    }

    #[DataProvider('upcAProvider')]
    public function testUpcAScansBack(string $data, string $expected): void
    {
        $this->assertScansBack(
            $data,
            Symbology::UpcA->value,
            self::FORMAT_NAMES['upc-a'],
            $expected,
            null,
            // UPC-A is bit for bit the EAN-13 of the same number with a
            // leading zero, so with every format enabled the decoder reports
            // the EAN-13 reading — see testUpcAIsTheSameBarsAsAnEan13.
            'UPCA'
        );
    }

    public function testUpcAIsTheSameBarsAsAnEan13(): void
    {
        $this->requireDecoder();

        $symbols = Decoder::decode($this->renderForScanning('036000291452', Symbology::UpcA->value));

        self::assertCount(1, $symbols);
        self::assertSame('EAN-13', $symbols[0]['format']);
        self::assertSame('0036000291452', $symbols[0]['text']);
    }

    /**
     * One UPC-E per zero-suppression rule, given as the UPC-E itself and as
     * the UPC-A it stands for. Both must produce the same symbol, and the
     * decoder reports the expanded article number either way.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function upcEProvider(): iterable
    {
        yield 'rule 0-2, last digit 1' => ['04252614', '0042100005264'];
        yield 'rule 0-2, last digit 0' => ['01234000', '0012000003400'];
        yield 'rule 3' => ['00030037', '0000300000007'];
        yield 'rule 4' => ['00001144', '0000010000014'];
        yield 'rule 5-9' => ['01234565', '0012345000065'];
        yield 'all zeros' => ['00000000', '0000000000000'];
        // The decoder normalises to thirteen digits by prefixing a zero to
        // the twelve-digit UPC-A, so the number system digit ends up second.
        yield 'number system 1' => ['10000007', '0100000000007'];
        yield 'without check digit' => ['0425261', '0042100005264'];
        yield 'from its upc-a' => ['042100005264', '0042100005264'];
        yield 'from its upc-a, no check digit' => ['04210000526', '0042100005264'];
    }

    #[DataProvider('upcEProvider')]
    public function testUpcEScansBack(string $data, string $expected): void
    {
        $this->assertScansBack($data, Symbology::UpcE->value, self::FORMAT_NAMES['upc-e'], $expected);
    }

    /** @return iterable<string, array{string}> */
    public static function itfProvider(): iterable
    {
        // Two digits is below what zxing-cpp will report — see
        // testAVeryShortItfIsBelowTheDecodersFloor.
        yield 'two pairs' => ['4242'];
        yield 'zeros' => ['0000'];
        yield 'nines' => ['9999'];
        yield 'a run' => ['1234567890'];
        yield 'leading zero written out' => ['0123'];
        yield 'alternating' => ['0101010101'];
        yield 'long' => ['1234567890123456789012'];
    }

    #[DataProvider('itfProvider')]
    public function testItfScansBack(string $data): void
    {
        $this->assertScansBack($data, Symbology::Itf->value, self::FORMAT_NAMES['itf']);
    }

    /**
     * The check digit is a digit of the number, not an annotation on it.
     *
     * Turning it on makes an *odd* payload the encodable one, and the decoder
     * reports all fourteen — sorry, all of them, check digit included, because
     * nothing in the bars says the last one is special.
     */
    public function testAnItfCheckDigitIsPartOfWhatScansBack(): void
    {
        $this->assertScansBack(
            '123456789',
            Symbology::Itf->value,
            self::FORMAT_NAMES['itf'],
            '1234567895',
            new ItfOptions(checkDigit: true)
        );
    }

    /**
     * ITF's weakness, from the decoder's side.
     *
     * Nothing in the bars marks where a character begins, so a very short ITF
     * is indistinguishable from a fragment of a longer one — and zxing-cpp
     * refuses to report one rather than risk a false positive. Its own writer
     * produces the same unreadable symbol, so this is the symbology and not
     * our encoding: asserted here so the absence of a two-digit case from the
     * provider above is a decision and not an oversight.
     *
     * If a future zxing-cpp lowers its floor, this fails and the case can move
     * into the provider.
     */
    public function testAVeryShortItfIsBelowTheDecodersFloor(): void
    {
        $this->requireDecoder();

        self::assertSame([], Decoder::decode($this->renderForScanning('42', Symbology::Itf->value)));
        self::assertNotSame([], Decoder::decode($this->renderForScanning('4242', Symbology::Itf->value)));
    }

    /**
     * A wide element of two modules is legal and readable, and is the one
     * ratio no reference fixture can cover — zxing-cpp writes only threes, so
     * this is the sole check that a ratio-2 symbol is a symbol at all.
     */
    public function testANarrowerItfRatioStillScans(): void
    {
        $this->assertScansBack(
            '12345678',
            Symbology::Itf->value,
            self::FORMAT_NAMES['itf'],
            null,
            new ItfOptions(wideRatio: 2)
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function itf14Provider(): iterable
    {
        yield 'gtin-14' => ['12345678901231', '12345678901231'];
        yield 'computed check digit' => ['1234567890123', '12345678901231'];
        yield 'zeros' => ['0000000000000', '00000000000000'];
        yield 'nines' => ['9999999999999', '99999999999997'];
        yield 'a case gtin' => ['1000000000000', '10000000000007'];
    }

    #[DataProvider('itf14Provider')]
    public function testItf14ScansBack(string $data, string $expected): void
    {
        $this->assertScansBack($data, Symbology::Itf14->value, self::FORMAT_NAMES['itf14'], $expected);
    }

    /**
     * The bearer bar is a frame of dark modules touching the bars, which is
     * exactly the sort of thing that stops a decoder reading a symbol at all.
     * It does not — that is the point of the test, and of the frame.
     */
    public function testTheBearerBarDoesNotStopTheSymbolBeingRead(): void
    {
        $this->requireDecoder();

        $scanme = Scanme::create();
        $framed = $scanme->generate('1234567890123', Symbology::Itf14->value);
        $bare = $scanme->generate(
            '1234567890123',
            Symbology::Itf14->value,
            new Itf14Options(bearerBar: false)
        );

        self::assertSame(3, $framed->getHeight(), 'bearer bar above and below');
        self::assertSame(1, $bare->getHeight());

        // The frame adds its own thickness *and* the quiet zone it encloses.
        // GS1 measures the 10X zone from the bars and puts the bearer outside
        // it; a frame drawn flush against the bars leaves no quiet zone and the
        // symbol does not scan at all, which is what the loop below would
        // catch.
        self::assertSame(
            $bare->getWidth() + 2 * (Itf14Backend::BEARER + ItfPatterns::QUIET_ZONE),
            $framed->getWidth()
        );
        self::assertTrue($framed->getQuietZone()->isEmpty(), 'the quiet zone is inside the frame');

        foreach ([$framed, $bare] as $symbol) {
            $png = $scanme->renderSymbol($symbol, 'png', new PngOptions(moduleSize: 6));
            $symbols = Decoder::decode($png, 'ITF');

            self::assertCount(1, $symbols);
            self::assertSame('12345678901231', $symbols[0]['text']);
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function dataMatrixProvider(): iterable
    {
        yield 'digits' => ['123456', false];
        yield 'single digit pair' => ['42', false];
        yield 'letters' => ['SCANME', false];
        yield 'mixed' => ['ABC-123/456', false];
        yield 'odd digit count' => ['12345', false];
        yield 'long text' => [str_repeat('DATAMATRIX ', 12), false];
        yield 'rectangular' => ['RECT-42', true];
    }

    #[DataProvider('dataMatrixProvider')]
    public function testDataMatrixScansBack(string $data, bool $rectangular): void
    {
        $this->assertScansBack(
            $data,
            Symbology::DataMatrix->value,
            self::FORMAT_NAMES['data-matrix'],
            null,
            new DataMatrixOptions(rectangular: $rectangular)
        );
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function addOnProvider(): iterable
    {
        // One EAN-2 per parity pattern the modulo-4 table can select, and one
        // EAN-5 per weighted checksum that the standard's table indexes.
        foreach (['00', '01', '02', '03'] as $addOn) {
            yield "ean-2 {$addOn}" => [Symbology::Ean2->value, $addOn, Symbology::Ean13->value, '5901234123457'];
        }

        foreach (['00000', '00001', '00002', '51234', '90000', '99999'] as $addOn) {
            yield "ean-5 {$addOn}" => [Symbology::Ean5->value, $addOn, Symbology::Ean13->value, '9788375780642'];
        }

        // And each main symbology an add-on may be printed beside. UPC-A and
        // UPC-E normalise to thirteen digits on the reading side, so the
        // expected text is built from what the composite says, not from what
        // was asked for.
        yield 'upc-a with ean-2' => [Symbology::Ean2->value, '12', Symbology::UpcA->value, '036000291452'];
        yield 'upc-a with ean-5' => [Symbology::Ean5->value, '51299', Symbology::UpcA->value, '036000291452'];
        yield 'upc-e with ean-2' => [Symbology::Ean2->value, '12', Symbology::UpcE->value, '04252614'];
    }

    /**
     * The real gate on the add-on bars: a scanner reads them beside a main
     * symbol, in a composite the library now assembles.
     *
     * There is no way to ask zxing-cpp to read an add-on on its own, so the
     * symbol handed to it here is Composite::of()'s output and the decoder is
     * told to refuse anything without an add-on. A result at all proves the
     * add-on decoded, and the text proves it decoded as the digits asked for.
     *
     * This used to assemble the composite by hand, because Symbol could not
     * express the placement. It can now, so the test exercises the same code
     * a caller would.
     */
    #[DataProvider('addOnProvider')]
    public function testAnAddOnScansBackBesideAMainSymbol(
        string $symbology,
        string $addOn,
        string $mainSymbology,
        string $main
    ): void {
        $this->requireDecoder();

        $scanme = Scanme::create();
        $composite = Composite::of(
            $scanme->generate($main, $mainSymbology),
            $scanme->generate($addOn, $symbology)
        );

        $png = $scanme->renderSymbol($composite, 'png', new PngOptions(moduleSize: 6));
        $symbols = Decoder::decode($png, 'EAN13,UPCA,UPCE,EAN8', 'require');

        self::assertCount(
            1,
            $symbols,
            sprintf('no %s with an add-on was read for %s %s', $mainSymbology, $symbology, $addOn)
        );
        self::assertTrue($symbols[0]['valid']);
        // The add-on's digits are the tail of what a scanner reports. The
        // head is the main article number in the form the decoder normalises
        // to, which differs between UPC-A and EAN-13 and is pinned on its own
        // in testTheMainHalfOfACompositeIsUnchangedByTheAddOn().
        self::assertStringEndsWith(
            $addOn,
            $symbols[0]['text'],
            sprintf('%s %s beside %s', $symbology, $addOn, $main)
        );
    }

    /**
     * The main half of a composite reads back as the article number it always
     * was — the add-on does not change what the main symbol says, only what is
     * appended to it.
     */
    public function testTheMainHalfOfACompositeIsUnchangedByTheAddOn(): void
    {
        $this->requireDecoder();

        $scanme = Scanme::create();
        $main = $scanme->generate('9788375780642', Symbology::Ean13);
        $composite = Composite::of($main, $scanme->generate('51299', Symbology::Ean5));

        $alone = Decoder::decode($this->renderForScanning('9788375780642', Symbology::Ean13->value));
        $withAddOn = Decoder::decode(
            $scanme->renderSymbol($composite, 'png', new PngOptions(moduleSize: 6)),
            'EAN13',
            'require'
        );

        self::assertSame('9788375780642', $alone[0]['text']);
        self::assertSame('978837578064251299', $withAddOn[0]['text']);
    }

    /**
     * The exemption above, held to its own terms.
     *
     * If zxing-cpp ever reports a lone add-on, this fails and says so — which
     * is the point: an exemption that cannot expire quietly becomes a habit.
     */
    public function testALoneAddOnIsStillUnreadableByTheDecoder(): void
    {
        $this->requireDecoder();

        $png = $this->renderForScanning('12345', Symbology::Ean5->value);

        self::assertSame(
            [],
            Decoder::decode($png, null, 'read'),
            'zxing-cpp now reads a standalone add-on; NO_STANDALONE_READER can shrink'
        );
    }
}
