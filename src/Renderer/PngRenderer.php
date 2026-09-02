<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Exception\RenderException;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\RenderOptionsInterface;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Symbol;

final class PngRenderer implements RendererInterface
{
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
            // Drawing text needs glyph outlines, and this encoder deliberately
            // writes 1-bit PNGs with no font engine and no GD dependency. A
            // symbology that supplies a human-readable interpretation — EAN,
            // Code128 — is therefore reported as incompatible rather than
            // rendered without the digits it is required to carry.
            text: false,
            color: false,
            nonUniformRows: true,
            optionsClass: PngOptions::class,
        );
    }

    public function render(Symbol $symbol, ?RenderOptionsInterface $options = null): string
    {
        $options = $options instanceof PngOptions ? $options : new PngOptions();

        if ($options->label !== null && $options->label !== '') {
            throw RenderException::unsupportedOperation(
                'PNG renderer does not support labels — text rendering requires a font engine'
            );
        }

        $layout = Layout::of($symbol, $options);
        $mod = $options->moduleSize;
        $pixelWidth = $layout->totalWidth * $mod;
        $pixelHeight = $layout->totalHeight * $mod;
        $bytesPerRow = intdiv($pixelWidth + 7, 8);

        // Each module row becomes one real scanline (filter 0 = None) followed
        // by repeats using filter 2 = Up with all-zero bytes, which PNG decodes
        // as "same as the row above". Pixels are first written as text
        // ('0' = black, '1' = white — 1-bit grayscale), then packed.
        $invert = $options->invert;
        $pixels = [
            '0' => str_repeat($invert ? '0' : '1', $mod),
            '1' => str_repeat($invert ? '1' : '0', $mod),
        ];
        $left = str_repeat($pixels['0'], $layout->quietZone->left);
        $right = str_repeat($pixels['0'], $layout->quietZone->right);
        $padding = str_repeat('1', $bytesPerRow * 8 - $pixelWidth); // spare bits stay white
        $blankRow = str_repeat($pixels['0'], $layout->totalWidth) . $padding;

        $raw = $this->repeatedRows($blankRow, $bytesPerRow, $layout->quietZone->top * $mod);

        foreach ($symbol->rows() as $index => $row) {
            $bits = $left . strtr($row, $pixels) . $right . $padding;
            $raw .= "\x00" . PngEncoder::packBits($bits, $bytesPerRow)
                . $this->upFilterRows($bytesPerRow, $layout->rowHeights[$index] * $mod - 1);
        }

        $raw .= $this->repeatedRows($blankRow, $bytesPerRow, $layout->quietZone->bottom * $mod);

        return $this->encoder->encodeScanlines($raw, $pixelWidth, $pixelHeight, $options->compressionLevel);
    }

    /** One real scanline plus $count - 1 "same as above" rows; empty when $count is 0. */
    private function repeatedRows(string $bits, int $bytesPerRow, int $count): string
    {
        if ($count <= 0) {
            return '';
        }

        return "\x00" . PngEncoder::packBits($bits, $bytesPerRow)
            . $this->upFilterRows($bytesPerRow, $count - 1);
    }

    private function upFilterRows(int $bytesPerRow, int $count): string
    {
        return $count <= 0 ? '' : str_repeat("\x02" . str_repeat("\0", $bytesPerRow), $count);
    }
}
