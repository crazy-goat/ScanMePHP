<?php

declare(strict_types=1);

use CrazyGoat\ScanMePHP\FfiEncoder;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Qr\Backend\NativeBackend;
use CrazyGoat\ScanMePHP\Generator\Qr\QrBackendInterface;
use CrazyGoat\ScanMePHP\Generator\Qr\QrGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Guards for GitHub issues #39 and #44: the extension name and the FFI library
 * path must have a single spelling and a single source of truth.
 *
 * The v1 versions of these guards pointed at src/QRCode.php, which chose the
 * encoder inline. That decision now lives in the QR generator's backends, so
 * the guards follow it there — the bug they caught (a misspelled extension
 * name silently demoting every install to a slower encoder) is just as
 * invisible in the new structure.
 */
class ExtensionNameConsistencyTest extends TestCase
{
    private const EXTENSION_NAME = 'scanmeqr';

    /** Every place that decides whether the extension is present. */
    private const SELECTION_PATHS = [
        'src/Generator/Qr/Backend/NativeBackend.php',
        'src/NativeEncoder.php',
        'src/Composer/Plugin.php',
    ];

    private function source(string $file): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/' . $file);
    }

    public function testAllBackendSelectionPathsUseTheSameExtensionName(): void
    {
        $expected = sprintf("extension_loaded('%s')", self::EXTENSION_NAME);

        foreach (self::SELECTION_PATHS as $file) {
            $source = $this->source($file);

            $this->assertStringContainsString(
                $expected,
                $source,
                sprintf('%s must reference the same extension name as the other selection paths', $file)
            );
            $this->assertStringNotContainsString(
                "extension_loaded('scanme_qr')",
                $source,
                sprintf('%s must not reference the misspelled extension name', $file)
            );
        }
    }

    /**
     * Pin the C-side zend_module_entry name, which is the source of truth for
     * what PHP actually registers. A naive substring check for "scanme_qr"
     * would false-fail on includes like "scanme_qr.h", so match the
     * module-entry block instead. See GitHub issue #44.
     */
    public function testCExtensionModuleNameMatchesPhpExtensionName(): void
    {
        $source = $this->source('php-ext/scanme_qr.c');

        $this->assertMatchesRegularExpression(
            '/zend_module_entry\s+\w+\s*=\s*\{[^}]*"scanmeqr"/s',
            $source,
            'php-ext/scanme_qr.c zend_module_entry name must be "scanmeqr"'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/zend_module_entry\s+\w+\s*=\s*\{[^}]*"scanme_qr"/s',
            $source,
            'php-ext/scanme_qr.c must not register zend_module_entry name "scanme_qr"'
        );
    }

    /**
     * R1-1 guard: FFI library path resolution must have one source of truth.
     * Every caller routes through FfiEncoder::resolveLibraryPath() rather than
     * duplicating the candidate-path logic, so the two can never silently
     * diverge. Pinned by source-string contract.
     */
    public function testFfiLibraryPathResolutionIsCentralized(): void
    {
        $callSites = [
            'src/Generator/Qr/Backend/FfiBackend.php',
            'src/NativeEncoder.php',
        ];

        foreach ($callSites as $file) {
            $source = $this->source($file);

            $this->assertStringContainsString(
                'FfiEncoder::resolveLibraryPath()',
                $source,
                sprintf('%s must resolve the FFI library path via FfiEncoder::resolveLibraryPath()', $file)
            );
            $this->assertStringNotContainsString(
                "'/clib/build/libscanme_qr.so'",
                $source,
                sprintf('%s must not duplicate the local-build path literal', $file)
            );
        }
    }

    public function testResolveLibraryPathReturnsUsableOrNull(): void
    {
        $path = FfiEncoder::resolveLibraryPath();

        $this->assertTrue(
            $path === null || FfiEncoder::isAvailable($path),
            'FfiEncoder::resolveLibraryPath() must return null or a path that isAvailable() confirms'
        );
    }

    /**
     * Future-guard, not the #39 regression test: it passes both before and
     * after that fix, because the misspelled 'scanme_qr' also evaluated to
     * false. The actual regression guard is
     * testAllBackendSelectionPathsUseTheSameExtensionName above.
     */
    public function testNativeBackendIsNotSelectedWhenTheExtensionIsMissing(): void
    {
        if (\extension_loaded(self::EXTENSION_NAME)) {
            $this->markTestSkipped('scanmeqr extension loaded; the native backend is the expected choice');
        }

        $backend = (new QrGenerator())->getActiveBackend();

        $this->assertInstanceOf(QrBackendInterface::class, $backend);
        $this->assertNotInstanceOf(NativeBackend::class, $backend);
        $this->assertFalse((new NativeBackend())->isAvailable());
    }

    /**
     * A backend that cannot run must never be picked, and the ranking must be
     * a strict speed order — otherwise a host with the extension installed
     * could still silently land on the pure-PHP encoder.
     */
    public function testOnlyAvailableBackendsAreSelectedAndRankingIsStrict(): void
    {
        $selector = (new QrGenerator())->getBackendSelector();
        $active = $selector->select();

        $this->assertNotNull($active, 'the portable backend always works, so something must be selected');
        $this->assertTrue($active->isAvailable());

        foreach ($selector->available() as $candidate) {
            $this->assertLessThanOrEqual(
                $active->getPriority(),
                $candidate->getPriority(),
                sprintf('%s outranks the selected %s', $candidate->getName(), $active->getName())
            );
        }

        $priorities = array_map(
            static fn (BackendInterface $backend): int => $backend->getPriority(),
            $selector->all()
        );
        $this->assertSame($priorities, array_unique($priorities), 'two backends must not tie');
        $this->assertSame(['native', 'ffi', 'bitset', 'portable'], $selector->names());
    }

    public function testForcingABackendPinsItAndResetRestoresTheRanking(): void
    {
        $generator = new QrGenerator();
        $selector = $generator->getBackendSelector();
        $automatic = $selector->select();

        $reference = $generator->generate('https://example.com')->toModuleString();

        // The per-backend numbers in BENCHMARK.md are meaningless without this.
        $selector->force('portable');
        $this->assertSame('portable', $selector->select()?->getName());

        // Every backend is the same encoder at a different speed, so pinning
        // one must not change a single module.
        $this->assertSame(
            $reference,
            $generator->generate('https://example.com')->toModuleString(),
            'the portable backend must agree with ' . ($automatic?->getName() ?? 'none') . ' module for module'
        );

        $selector->reset();
        $this->assertSame($automatic?->getName(), $selector->select()?->getName());
    }

    public function testForcingAnUnknownBackendFailsLoudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown backend "gpu"');

        (new QrGenerator())->getBackendSelector()->force('gpu');
    }
}
