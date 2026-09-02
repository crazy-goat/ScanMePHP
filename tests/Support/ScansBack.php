<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests\Support;

use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Scanme;

/**
 * Assertions that put a real scanner between us and our own tables.
 *
 * Rendering goes through PNG at a generous module size, because that is what
 * a caller actually produces: a fault in the PNG encoder or in the quiet zone
 * is as much a reason for a symbol not to scan as a wrong codeword.
 */
trait ScansBack
{
    /** Comfortably above any decoder's minimum, so a failure means a real fault. */
    private const SCAN_MODULE_SIZE = 6;

    /**
     * Skip when the decoder is absent, unless CI has declared it mandatory.
     */
    protected function requireDecoder(): void
    {
        if (Decoder::isAvailable()) {
            return;
        }

        if (getenv('SCANME_REQUIRE_DECODER') === '1') {
            self::fail(Decoder::unavailableReason());
        }

        self::markTestSkipped(Decoder::unavailableReason());
    }

    protected function renderForScanning(string $data, string $generator, ?object ...$options): string
    {
        return Scanme::create()->render(
            $data,
            $generator,
            'png',
            new PngOptions(moduleSize: self::SCAN_MODULE_SIZE),
            ...array_filter($options)
        );
    }

    /**
     * The core gate: encode $data, hand the PNG to zxing-cpp, and require both
     * the payload and the symbology back.
     */
    protected function assertScansBack(
        string $data,
        string $generator,
        string $expectedFormat,
        ?string $expectedText = null,
        ?object $generatorOptions = null,
        ?string $decoderFormats = null,
    ): void {
        $this->requireDecoder();

        $png = $this->renderForScanning($data, $generator, $generatorOptions);
        $symbols = Decoder::decode($png, $decoderFormats);

        self::assertCount(
            1,
            $symbols,
            sprintf(
                'expected exactly one %s symbol for %s, got %d',
                $generator,
                self::describe($data),
                \count($symbols)
            )
        );

        $symbol = $symbols[0];

        self::assertSame(
            $expectedFormat,
            $symbol['format'],
            sprintf('%s produced a symbol the decoder read as %s', $generator, $symbol['format'])
        );
        self::assertTrue($symbol['valid'], 'the decoder reported the symbol as invalid');
        self::assertSame(
            $expectedText ?? $data,
            $symbol['text'],
            sprintf('%s round-trip for %s', $generator, self::describe($data))
        );
    }

    /** Payloads may be binary or long; keep failure messages readable. */
    private static function describe(string $data): string
    {
        $printable = preg_match('/[^\x20-\x7e]/', $data) !== 1;
        $shown = $printable ? $data : bin2hex($data);

        if (\strlen($shown) > 48) {
            $shown = substr($shown, 0, 45) . '...';
        }

        return sprintf('%s(%d bytes)', $printable ? "\"{$shown}\" " : "hex {$shown} ", \strlen($data));
    }
}
