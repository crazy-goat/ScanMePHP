<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\FastEncoder;
use CrazyGoat\ScanMePHP\FfiEncoder;
use CrazyGoat\ScanMePHP\Generator\Qr\Backend\NativeBackend;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The four QR backends must be one encoder wearing four hats.
 *
 * QrReferenceTest checks the portable Encoder against an outsider — Nayuki's
 * qrcodegen, module for module, 443 payloads at four error correction levels.
 * That is the oracle. This is what carries it: every other backend has to
 * produce byte-identical modules for the same input, so a bug in the bitset
 * fast path, in the FFI bridge or in the C++ core is a failure here rather
 * than a symbol that scans as something else on machines with the extension
 * loaded.
 *
 * Comparing them against each other rather than against the fixture is
 * deliberate. The fixture pins a mask, which the fast path and the C++ core
 * cannot be told; what matters about them is not that they reach an
 * independently chosen mask but that they reach the same symbol the verified
 * encoder does, mask included.
 */
final class QrBackendAgreementTest extends TestCase
{
    private const LEVELS = [
        'L' => ErrorCorrectionLevel::Low,
        'M' => ErrorCorrectionLevel::Medium,
        'Q' => ErrorCorrectionLevel::Quartile,
        'H' => ErrorCorrectionLevel::High,
    ];

    /** @return \Generator<string, array{string, ErrorCorrectionLevel}> */
    public static function payloadProvider(): \Generator
    {
        $payloads = file(
            \dirname(__DIR__) . '/tools/qr_reference_payloads.txt',
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        );
        self::assertIsArray($payloads);

        foreach ($payloads as $payload) {
            foreach (self::LEVELS as $name => $level) {
                yield sprintf('%s ECL=%s', substr($payload, 0, 60), $name) => [$payload, $level];
            }
        }
    }

    #[DataProvider('payloadProvider')]
    public function testTheBitsetFastPathAgreesWithTheVerifiedEncoder(
        string $payload,
        ErrorCorrectionLevel $level
    ): void {
        $expected = (new Encoder())->encode($payload, $level);

        // Above version 27 the bitset encoder has no fast path of its own and
        // the portable one is what runs, so there is nothing to compare.
        if ($expected->getVersion() > FastEncoder::MAX_VERSION) {
            $this->markTestSkipped('Beyond the bitset encoder\'s reach; the portable one already ran');
        }

        $this->assertSame(
            $expected->toModuleString(),
            (new FastEncoder())->encode($payload, $level)->toModuleString()
        );
    }

    #[DataProvider('payloadProvider')]
    public function testTheCxxCoreThroughFfiAgreesWithTheVerifiedEncoder(
        string $payload,
        ErrorCorrectionLevel $level
    ): void {
        $libraryPath = FfiEncoder::localBuildPath();
        if (!file_exists($libraryPath)) {
            $this->markTestSkipped('libscanme_qr native library not found');
        }

        $this->assertSame(
            (new Encoder())->encode($payload, $level)->toModuleString(),
            (new FfiEncoder($libraryPath))->encode($payload, $level)->toModuleString()
        );
    }

    #[DataProvider('payloadProvider')]
    public function testTheCxxCoreThroughTheExtensionAgreesWithTheVerifiedEncoder(
        string $payload,
        ErrorCorrectionLevel $level
    ): void {
        $backend = new NativeBackend();
        if (!$backend->isAvailable()) {
            $this->markTestSkipped('The scanmeqr extension is not loaded');
        }

        $this->assertSame(
            (new Encoder())->encode($payload, $level)->toModuleString(),
            $backend->encode($payload, new QrOptions($level))->toModuleString()
        );
    }
}
