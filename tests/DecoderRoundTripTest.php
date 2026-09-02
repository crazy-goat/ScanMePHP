<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\DataMatrixOptions;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
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
        Symbology::Ean13->value => 'EAN-13',
        Symbology::Ean8->value => 'EAN-8',
        Symbology::UpcA->value => 'UPC-A',
        Symbology::UpcE->value => 'UPC-E',
        Symbology::DataMatrix->value => 'Data Matrix',
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
        $covered = array_keys(self::FORMAT_NAMES);
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

    #[DataProvider('code128Provider')]
    public function testCode128ScansBack(string $data): void
    {
        $this->assertScansBack($data, Symbology::Code128->value, self::FORMAT_NAMES['code128']);
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
}
