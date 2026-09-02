<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Exception\RenderException;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\RenderOptionsInterface;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * A 1-bit PNG, written here rather than through GD so the library keeps its
 * zero-extension promise.
 *
 * Text is drawn from BitmapFont: enough to print an EAN-13's digits or a
 * Code 128's SKU, which is what the standards require of a printed symbol, but
 * a fixed repertoire — so the renderer reports which characters it has instead
 * of drawing holes where a glyph is missing.
 */
final class PngRenderer implements RendererInterface
{
    /** Blank module rows between the symbol and each text line. */
    private const TEXT_GAP = 1;

    private readonly PngEncoder $encoder;

    public function __construct()
    {
        $this->encoder = new PngEncoder();
    }

    public function getFormat(): string
    {
        return 'png';
    }

    public function getContentType(): string
    {
        return 'image/png';
    }

    public function getCapabilities(): RendererCapabilities
    {
        return new RendererCapabilities(
            moduleShapes: [ModuleShape::Square],
            text: true,
            // 1-bit grayscale: a module is on or off, and so is a glyph pixel.
            color: false,
            nonUniformRows: true,
            textCharacters: implode('', BitmapFont::characters()),
            optionsClass: PngOptions::class,
        );
    }

    public function render(Symbol $symbol, ?RenderOptionsInterface $options = null): string
    {
        $options = $options instanceof PngOptions ? $options : new PngOptions();
        $layout = Layout::of($symbol, $options);
        $mod = $options->moduleSize;

        $textLines = $this->textLines($symbol, $options);

        // The canvas grows sideways if a text line is wider than the symbol,
        // rather than clipping it: silently losing part of an article number
        // would be worse than an image a few modules wider than expected.
        $canvasWidth = $layout->totalWidth;
        foreach ($textLines as $line) {
            $canvasWidth = max($canvasWidth, BitmapFont::measure($line));
        }

        $canvasHeight = $layout->totalHeight
            + \count($textLines) * (BitmapFont::HEIGHT + self::TEXT_GAP);

        $pixelWidth = $canvasWidth * $mod;
        $pixelHeight = $canvasHeight * $mod;
        $bytesPerRow = intdiv($pixelWidth + 7, 8);

        // Pixels are written as text ('0' = black, '1' = white — 1-bit
        // grayscale), then packed. Each module row becomes one real scanline
        // (filter 0 = None) followed by repeats using filter 2 = Up with
        // all-zero bytes, which PNG decodes as "same as the row above".
        $invert = $options->invert;
        $pixels = [
            '0' => str_repeat($invert ? '0' : '1', $mod),
            '1' => str_repeat($invert ? '1' : '0', $mod),
        ];
        // Spare bits past the image edge are never displayed; matching the
        // background keeps the packed bytes uniform and compressible.
        $padding = str_repeat($invert ? '0' : '1', $bytesPerRow * 8 - $pixelWidth);
        $blank = str_repeat('0', $canvasWidth);

        $raw = $this->scanlines($blank, $layout->quietZone->top, $mod, $bytesPerRow, $pixels, $padding);

        $symbolIndent = intdiv($canvasWidth - $layout->totalWidth, 2);
        $left = str_repeat('0', $symbolIndent + $layout->quietZone->left);
        foreach ($symbol->rows() as $index => $row) {
            $moduleRow = str_pad($left . $row, $canvasWidth, '0');
            $raw .= $this->scanlines(
                $moduleRow,
                $layout->rowHeights[$index],
                $mod,
                $bytesPerRow,
                $pixels,
                $padding
            );
        }

        $raw .= $this->scanlines($blank, $layout->quietZone->bottom, $mod, $bytesPerRow, $pixels, $padding);

        foreach ($textLines as $line) {
            $raw .= $this->scanlines($blank, self::TEXT_GAP, $mod, $bytesPerRow, $pixels, $padding);
            $indent = intdiv($canvasWidth - BitmapFont::measure($line), 2);
            foreach (BitmapFont::rasterise($line) as $glyphRow) {
                $moduleRow = str_pad(str_repeat('0', $indent) . $glyphRow, $canvasWidth, '0');
                $raw .= $this->scanlines($moduleRow, 1, $mod, $bytesPerRow, $pixels, $padding);
            }
        }

        return $this->encoder->encodeScanlines($raw, $pixelWidth, $pixelHeight, $options->compressionLevel);
    }

    /**
     * The text lines to print: the symbol's own human-readable interpretation,
     * then the caller's caption.
     *
     * The symbol's text is already vetted by Compatibility before render() is
     * reached; a caption is not, since it comes straight from the options, so
     * an unprintable one is refused here rather than drawn with gaps.
     *
     * @return list<string>
     */
    private function textLines(Symbol $symbol, PngOptions $options): array
    {
        $lines = [];

        foreach ([$options->resolveText($symbol), $options->label] as $line) {
            if ($line === null || $line === '') {
                continue;
            }

            $missing = BitmapFont::missing($line);
            if ($missing !== []) {
                throw RenderException::unsupportedOperation(sprintf(
                    'the built-in font has no glyph for %s — the PNG renderer draws text from a fixed '
                    . 'repertoire (digits, A-Z and common punctuation)',
                    implode(' ', array_map(
                        static fn (string $character): string
                            => sprintf('%s (0x%02X)', $character, \ord($character)),
                        $missing
                    ))
                ));
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * One module row as PNG scanlines: a real one, then "same as above"
     * repeats for the remaining pixel rows.
     *
     * @param array<string, string> $pixels
     */
    private function scanlines(
        string $moduleRow,
        int $rowHeight,
        int $moduleSize,
        int $bytesPerRow,
        array $pixels,
        string $padding
    ): string {
        $pixelRows = $rowHeight * $moduleSize;
        if ($pixelRows <= 0) {
            return '';
        }

        $bits = strtr($moduleRow, $pixels) . $padding;

        return "\x00" . PngEncoder::packBits($bits, $bytesPerRow)
            . ($pixelRows > 1 ? str_repeat("\x02" . str_repeat("\0", $bytesPerRow), $pixelRows - 1) : '');
    }
}
