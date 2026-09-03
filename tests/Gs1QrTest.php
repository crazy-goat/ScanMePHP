<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;
use CrazyGoat\ScanMePHP\Generator\Gs1Qr\Gs1QrGenerator;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Gs1QrTest extends TestCase
{
    private function generator(): Gs1QrGenerator
    {
        $generator = Defaults::registry()->getGenerator(Symbology::Gs1Qr->value);
        self::assertInstanceOf(Gs1QrGenerator::class, $generator);

        return $generator;
    }

    public function testItIsRegisteredUnderItsNameAndItsAliases(): void
    {
        $registry = Defaults::registry();

        foreach ([Symbology::Gs1Qr->value, 'gs1-qrcode', 'gs1qr'] as $name) {
            $this->assertSame(
                'GS1 QR Code',
                $registry->getGenerator($name)->getCapabilities()->title,
                sprintf('%s should resolve to the GS1 QR generator', $name)
            );
        }
    }

    public function testTheMetadataSaysWhatAScannerWillReport(): void
    {
        $symbol = $this->generator()->generate('(01)09501101020917(10)LOT0001');

        $this->assertSame(Symbology::Gs1Qr->value, $symbol->getMetadata()['symbology']);
        $this->assertSame(2, $symbol->getMetadata()['elements']);
        $this->assertSame('010950110102091710LOT0001', $symbol->getMetadata()['payload']);
    }

    public function testBothGs1MatrixSymbologiesCarryTheSamePayload(): void
    {
        $elements = '(01)09501101020917(10)LOT0001(11)260101';
        $registry = Defaults::registry();

        $this->assertSame(
            $registry->getGenerator(Symbology::Gs1DataMatrix->value)->generate($elements)->getMetadata()['payload'],
            $registry->getGenerator(Symbology::Gs1Qr->value)->generate($elements)->getMetadata()['payload'],
            'The payload is a GS1 fact, not a QR one; only the spelling of FNC1 differs'
        );
    }

    public function testAGs1QrIsNotAPlainQrWithParentheses(): void
    {
        $elements = '(01)09501101020917';
        $registry = Defaults::registry();

        $gs1 = $registry->getGenerator(Symbology::Gs1Qr->value)->generate($elements);
        $plain = $registry->getGenerator(Symbology::QrCode->value)->generate($elements);

        $this->assertNotSame(
            $plain->toModuleString(),
            $gs1->toModuleString(),
            'Encoding the parentheses literally would scan and mean nothing to a GS1 system'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function refusalProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'no parentheses' => ['0109501101020917'];
        yield 'not an identifier' => ['(99999)1'];
        yield 'data too long for the identifier' => ['(11)2601010'];
        yield 'data too short for the identifier' => ['(01)0950110102091'];
        yield 'no data' => ['(01)'];
    }

    #[DataProvider('refusalProvider')]
    public function testItRefusesWhatIsNotGs1(string $data): void
    {
        $this->assertFalse($this->generator()->canEncode($data));
    }

    public function testAVersionTooSmallForThePayloadIsRefused(): void
    {
        $elements = '(240)' . str_repeat('X', 30) . '(10)LOT0001';
        $generator = $this->generator();

        $this->assertFalse($generator->canEncode($elements, new QrOptions(version: 1)));
        $this->assertTrue($generator->canEncode($elements, new QrOptions(version: 40)));
    }

    public function testAHigherErrorCorrectionLevelCanCostAVersion(): void
    {
        $elements = '(01)09501101020917(10)LOT0001(11)260101';
        $generator = $this->generator();

        $low = $generator->generate($elements, new QrOptions(ErrorCorrectionLevel::Low));
        $high = $generator->generate($elements, new QrOptions(ErrorCorrectionLevel::High));

        $this->assertGreaterThan($low->getMetadata()['version'], $high->getMetadata()['version']);
    }

    /**
     * The mask is where the reference fixture stops, and why.
     *
     * ISO/IEC 18004 clause 7.8.3 says to score all eight masks and take the
     * lowest, but the scoring rules — chiefly rule 3, the 1:1:3:1:1 pattern —
     * are read differently in practice, and ties are ordinary. Independent
     * encoders therefore emit different masks for the same data: over sixty
     * random byte payloads, zxing-cpp and Nayuki's qrcodegen produced the same
     * modules eight times. All eight maskings carry identical data and all of
     * them scan, so this is a preference rather than a fact, and
     * Gs1QrReferenceTest holds it fixed rather than asserting it.
     *
     * What is a fact is that our own choice is stable and is one of the eight.
     * If that ever stops being true, the fixture is comparing something the
     * caller does not get.
     */
    public function testOurMaskIsOneOfTheEightAndIsStable(): void
    {
        $encoder = new Encoder();
        $payload = ElementString::parse('(01)09501101020917(10)LOT0001')->payload();
        $level = ErrorCorrectionLevel::Medium;
        $version = $encoder->getMinimumGs1Version($payload, $level);

        $shipped = $encoder->encodeGs1($payload, $level)->toModuleString();

        $candidates = [];
        for ($mask = 0; $mask < 8; $mask++) {
            $candidates[] = $encoder->encodeGs1AtMask($payload, $level, $version, $mask)->toModuleString();
        }

        $this->assertContains($shipped, $candidates);
        $this->assertSame($shipped, $encoder->encodeGs1($payload, $level)->toModuleString());
    }

    /**
     * Digit runs are where our symbol grows and the fixture cannot follow.
     *
     * QR can encode digits three to ten bits instead of eight, and an encoder
     * that segments will do so. This one encodes every payload as one byte
     * segment — a pre-existing limit of the QR pipeline, not something GS1
     * introduced — so a numeric payload takes more room than it has to. The
     * symbol is correct and scans; it is simply larger. Pinning the size here
     * means adding segmentation later has to delete this test rather than
     * quietly leave a fixture asserting the old shape.
     */
    public function testDigitRunsAreEncodedAsBytes(): void
    {
        $payload = ElementString::parse('(00)123456789012345678')->payload();

        $this->assertSame(20, \strlen($payload));

        // 4 bits of FNC1, 4 of mode, 8 of character count and 160 of data is
        // 176 bits — 22 codewords. A numeric segment would spend 4 + 10 + 67
        // for the digits after the AI, and fit where this does not.
        $this->assertSame(2, (new Encoder())->getMinimumGs1Version($payload, ErrorCorrectionLevel::Quartile));
    }
}
