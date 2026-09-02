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
            positionedText: true,
            textCharacters: implode('', BitmapFont::characters()),
            optionsClass: PngOptions::class,
        );
    }

    public function render(Symbol $symbol, ?RenderOptionsInterface $options = null): string
    {
        $options = $options instanceof PngOptions ? $options : new PngOptions();
        $layout = Layout::of($symbol, $options);
        $mod = $options->moduleSize;

        $bands = $this->bands($symbol, $layout, $options);

        // The canvas grows sideways if a text line runs past the symbol,
        // rather than clipping it: silently losing part of an article number
        // would be worse than an image a few modules wider than expected. A
        // line centred on part of the symbol — an add-on's digits — can run
        // past either edge, so both are measured.
        $start = 0;
        $end = $layout->totalWidth;
        foreach ([...$bands['above'], ...$bands['below']] as $band) {
            foreach ($band as [$line, $centre]) {
                $measure = BitmapFont::measure($line);
                $at = $centre - intdiv($measure, 2);
                $start = min($start, $at);
                $end = max($end, $at + $measure);
            }
        }

        $canvasWidth = $end - $start;
        $textRows = \count($bands['above']) + \count($bands['below']);
        $canvasHeight = $layout->totalHeight + $textRows * (BitmapFont::HEIGHT + self::TEXT_GAP);

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

        // Where the symbol sits once the canvas has grown to fit the text.
        $symbolIndent = -$start;

        $raw = '';
        foreach ($bands['above'] as $band) {
            $raw .= $this->band($band, $symbolIndent, $canvasWidth, $mod, $bytesPerRow, $pixels, $padding);
            $raw .= $this->scanlines($blank, self::TEXT_GAP, $mod, $bytesPerRow, $pixels, $padding);
        }

        $raw .= $this->scanlines($blank, $layout->quietZone->top, $mod, $bytesPerRow, $pixels, $padding);

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

        foreach ($bands['below'] as $band) {
            $raw .= $this->scanlines($blank, self::TEXT_GAP, $mod, $bytesPerRow, $pixels, $padding);
            $raw .= $this->band($band, $symbolIndent, $canvasWidth, $mod, $bytesPerRow, $pixels, $padding);
        }

        return $this->encoder->encodeScanlines($raw, $pixelWidth, $pixelHeight, $options->compressionLevel);
    }

    /**
     * One band of text as scanlines: every line on it, each centred on its own
     * columns, rasterised into the same seven glyph rows.
     *
     * @param list<array{string, int}> $band Text, and the module column to centre it on
     * @param array<string, string> $pixels
     */
    private function band(
        array $band,
        int $symbolIndent,
        int $canvasWidth,
        int $mod,
        int $bytesPerRow,
        array $pixels,
        string $padding
    ): string {
        $rows = array_fill(0, BitmapFont::HEIGHT, str_repeat('0', $canvasWidth));

        foreach ($band as [$line, $centre]) {
            $at = $symbolIndent + $centre - intdiv(BitmapFont::measure($line), 2);

            foreach (BitmapFont::rasterise($line) as $index => $glyphRow) {
                $rows[$index] = substr_replace($rows[$index], $glyphRow, $at, \strlen($glyphRow));
            }
        }

        $raw = '';
        foreach ($rows as $row) {
            $raw .= $this->scanlines($row, 1, $mod, $bytesPerRow, $pixels, $padding);
        }

        return $raw;
    }

    /**
     * The text bands to print, each a list of lines with the module column to
     * centre them on.
     *
     * @return array{above: list<list<array{string, int}>>, below: list<list<array{string, int}>>}
     */
    private function bands(Symbol $symbol, Layout $layout, PngOptions $options): array
    {
        $bands = ['above' => [], 'below' => []];

        foreach ($options->resolveTextLines($symbol) as $placement => $lines) {
            foreach ($lines as $regions) {
                $band = [];
                foreach ($regions as $region) {
                    $this->requireGlyphs($region->text);
                    $band[] = [$region->text, $layout->columnOffset($region->centre())];
                }

                $bands[$placement][] = $band;
            }
        }

        return $bands;
    }

    /**
     * A symbol's own text is vetted by Compatibility before render() is
     * reached; a caption is not, since it comes straight from the options, so
     * an unprintable one is refused here rather than drawn with gaps.
     *
     * @throws RenderException when the built-in font cannot draw $line
     */
    private function requireGlyphs(string $line): void
    {
        $missing = BitmapFont::missing($line);
        if ($missing === []) {
            return;
        }

        throw RenderException::unsupportedOperation(sprintf(
            'the built-in font has no glyph for %s — the PNG renderer draws text from a fixed '
            . 'repertoire (digits, A-Z and common punctuation)',
            implode(' ', array_map(
                static fn (string $character): string => sprintf('%s (0x%02X)', $character, \ord($character)),
                $missing
            ))
        ));
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
