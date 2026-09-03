<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Exception\NoBackendAvailableException;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\Qr\Backend\BitsetBackend;
use CrazyGoat\ScanMePHP\Generator\Qr\Backend\NativeBackend;
use CrazyGoat\ScanMePHP\Generator\Qr\Backend\PortableBackend;
use CrazyGoat\ScanMePHP\Generator\Qr\QrBackendInterface;
use CrazyGoat\ScanMePHP\Generator\Qr\QrGenerator;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The mask pattern as something a caller may choose.
 *
 * Which of the eight to use is a preference, not a requirement — all eight
 * carry identical data and all of them scan — so it belongs in the option bag
 * rather than in the encoder. What these check is that pinning it actually
 * changes the symbol, that the automatic choice is always one of the eight,
 * and that a backend which cannot honour the request is reported by name
 * instead of quietly ignoring it.
 */
final class QrMaskOptionTest extends TestCase
{
    private const PAYLOAD = 'https://example.com/order/4471';

    private const GS1 = '(01)09501101020917(10)LOT0001';

    /** @return iterable<string, array{int}> */
    public static function maskProvider(): iterable
    {
        for ($mask = QrOptions::MIN_MASK; $mask <= QrOptions::MAX_MASK; $mask++) {
            yield sprintf('mask %d', $mask) => [$mask];
        }
    }

    #[DataProvider('maskProvider')]
    public function testEveryMaskProducesADistinctSymbolOfTheSameSize(int $mask): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::QrCode->value);

        $automatic = $generator->generate(self::PAYLOAD);
        $pinned = $generator->generate(self::PAYLOAD, new QrOptions(mask: $mask));

        $this->assertSame($automatic->getWidth(), $pinned->getWidth(), 'A mask changes modules, not size');
        $this->assertSame($automatic->getMetadata()['version'], $pinned->getMetadata()['version']);
    }

    public function testTheEightMasksAreEightDifferentSymbols(): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::QrCode->value);

        $symbols = [];
        for ($mask = QrOptions::MIN_MASK; $mask <= QrOptions::MAX_MASK; $mask++) {
            $symbols[] = $generator->generate(self::PAYLOAD, new QrOptions(mask: $mask))->toModuleString();
        }

        $this->assertCount(8, array_unique($symbols), 'Pinning a mask must actually pin something');
    }

    public function testTheAutomaticChoiceIsOneOfTheEight(): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::QrCode->value);

        $symbols = [];
        for ($mask = QrOptions::MIN_MASK; $mask <= QrOptions::MAX_MASK; $mask++) {
            $symbols[] = $generator->generate(self::PAYLOAD, new QrOptions(mask: $mask))->toModuleString();
        }

        $this->assertContains($generator->generate(self::PAYLOAD)->toModuleString(), $symbols);
    }

    public function testAGs1QrTakesTheSameOption(): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::Gs1Qr->value);

        $symbols = [];
        for ($mask = QrOptions::MIN_MASK; $mask <= QrOptions::MAX_MASK; $mask++) {
            $symbols[] = $generator->generate(self::GS1, new QrOptions(mask: $mask))->toModuleString();
        }

        $this->assertCount(8, array_unique($symbols));
        $this->assertContains($generator->generate(self::GS1)->toModuleString(), $symbols);
    }

    public function testPinningAMaskAndAVersionTogetherIsHonoured(): void
    {
        $generator = Defaults::registry()->getGenerator(Symbology::QrCode->value);

        $symbol = $generator->generate(self::PAYLOAD, new QrOptions(version: 10, mask: 5));

        $this->assertSame(10, $symbol->getMetadata()['version']);
        $this->assertSame(
            $symbol->toModuleString(),
            $generator->generate(self::PAYLOAD, new QrOptions(version: 10, mask: 5))->toModuleString(),
        );
    }

    /** @return iterable<string, array{int}> */
    public static function outOfRangeProvider(): iterable
    {
        yield 'below the first' => [-1];
        yield 'past the last' => [8];
    }

    #[DataProvider('outOfRangeProvider')]
    public function testAMaskOutsideTheEightIsRefused(int $mask): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('QR mask pattern must be between 0 and 7');

        new QrOptions(mask: $mask);
    }

    /**
     * A backend that cannot be told a mask is reported, not silently used.
     *
     * The C++ core exposes `encode(data, len, ecl)` and the bitset encoder
     * scores its masks inside an inlined hot path, so neither can honour the
     * request. Ignoring it would hand back a symbol that scans and is not the
     * one asked for, which is the failure this whole option exists to avoid.
     */
    public function testABackendThatCannotBeToldAMaskIsReportedByName(): void
    {
        $generator = new QrGenerator(new BackendSelector(new NativeBackend(), new BitsetBackend()));

        $this->expectException(NoBackendAvailableException::class);
        $this->expectExceptionMessage('QR Code pinned to mask 3');

        $generator->generate(self::PAYLOAD, new QrOptions(mask: 3));
    }

    public function testTheMessageNamesBothPinsWhenBothAreSet(): void
    {
        $generator = new QrGenerator(new BackendSelector(new BitsetBackend()));

        $this->expectException(NoBackendAvailableException::class);
        $this->expectExceptionMessage('QR Code pinned to version 5 and mask 3');

        $generator->generate(self::PAYLOAD, new QrOptions(version: 5, mask: 3));
    }

    public function testThePortableBackendIsWhatKeepsAPinnedMaskAvailable(): void
    {
        $generator = new QrGenerator(new BackendSelector(new NativeBackend(), new PortableBackend()));

        $this->assertSame(
            'portable',
            $generator->getBackendSelector()->bestMatching(
                static fn ($candidate): bool => $candidate instanceof QrBackendInterface
                    && $candidate->supportsForcedMask()
            )?->getName(),
            'A pinned mask must not become an availability question on any machine'
        );
    }
}
