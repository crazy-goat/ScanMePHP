<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\DataMatrixOptions;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\Symbology;
use CrazyGoat\ScanMePHP\Tests\Support\Decoder;
use CrazyGoat\ScanMePHP\Tests\Support\ScansBack;
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

    /** @dataProvider qrProvider */
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
     *
     * @dataProvider eclProvider
     */
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

    /** @dataProvider qrVersionProvider */
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

    /** @dataProvider code128Provider */
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

    /** @dataProvider ean13Provider */
    public function testEan13ScansBack(string $data, string $expected): void
    {
        $this->assertScansBack($data, Symbology::Ean13->value, self::FORMAT_NAMES['ean13'], $expected);
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

    /** @dataProvider dataMatrixProvider */
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
