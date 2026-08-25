<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Exception\RenderException;
use CrazyGoat\ScanMePHP\Matrix;
use CrazyGoat\ScanMePHP\RendererInterface;
use CrazyGoat\ScanMePHP\RenderOptions;

class PngRenderer implements RendererInterface
{
    private readonly PngEncoder $encoder;

    /**
     * @param int $moduleSize Pixels per module
     * @param int $compressionLevel zlib level 0–9 for the IDAT stream. Scanlines
     *        are stored with the PNG "Up" filter so every repeated pixel row is
     *        all zeros; level 1 already shrinks that to a few KB and is ~7×
     *        faster than zlib's default 6 (v10: 31 vs 206 µs for 2.4 vs 1.5 KB).
     */
    public function __construct(
        private readonly int $moduleSize = 10,
        private readonly int $compressionLevel = 1,
    ) {
        if ($this->compressionLevel < 0 || $this->compressionLevel > 9) {
            throw new \InvalidArgumentException('PNG compression level must be between 0 and 9, got ' . $this->compressionLevel);
        }
        $this->encoder = new PngEncoder();
    }

    public function getContentType(): string
    {
        return 'image/png';
    }

    public function render(Matrix $matrix, RenderOptions $options): string
    {
        if ($options->label !== null && $options->label !== '') {
            throw RenderException::unsupportedOperation(
                'PNG renderer does not support labels — text rendering requires a font engine'
            );
        }

        $size = $matrix->getSize();
        $margin = $options->margin;
        $mod = $this->moduleSize;
        $totalModules = $size + (2 * $margin);
        $totalPixels = $totalModules * $mod;
        $bytesPerRow = intdiv($totalPixels + 7, 8);
        $invert = $options->invert;

        // Each module row becomes one real scanline (filter 0 = None) followed
        // by $mod - 1 scanlines using filter 2 = Up with all-zero bytes, which
        // PNG decodes as "same as the row above". Pixels are first written as
        // text ('0' = black, '1' = white — 1-bit grayscale), then packed.
        $dark = str_repeat($invert ? '1' : '0', $mod);
        $light = str_repeat($invert ? '0' : '1', $mod);
        $pixels = ['0' => $light, '1' => $dark];
        $side = str_repeat($light, $margin);
        $padding = str_repeat('1', $bytesPerRow * 8 - $totalPixels); // spare bits stay white
        $repeat = str_repeat("\x02" . str_repeat("\0", $bytesPerRow), $mod - 1);

        $quietRows = $margin > 0
            ? "\x00" . PngEncoder::packBits(str_repeat($light, $totalModules) . $padding, $bytesPerRow)
                . str_repeat("\x02" . str_repeat("\0", $bytesPerRow), $margin * $mod - 1)
            : '';

        $modules = $matrix->toModuleString();
        $raw = $quietRows;
        for ($y = 0, $offset = 0; $y < $size; $y++, $offset += $size) {
            $bits = $side . strtr(substr($modules, $offset, $size), $pixels) . $side . $padding;
            $raw .= "\x00" . PngEncoder::packBits($bits, $bytesPerRow) . $repeat;
        }
        $raw .= $quietRows;

        return $this->encoder->encodeScanlines($raw, $totalPixels, $totalPixels, $this->compressionLevel);
    }
}
