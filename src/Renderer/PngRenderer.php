<?php

declare(strict_types=1);

namespace ScanMePHP\Renderer;

use ScanMePHP\Exception\RenderException;
use ScanMePHP\Matrix;
use ScanMePHP\RenderOptions;
use ScanMePHP\RendererInterface;

class PngRenderer implements RendererInterface
{
    private readonly PngEncoder $encoder;

    public function __construct(
        private readonly int $moduleSize = 10,
    ) {
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
        $totalModules = $size + (2 * $margin);
        $totalPixels = $totalModules * $this->moduleSize;
        $mod = $this->moduleSize;
        $bytesPerScanline = (int) ceil($totalPixels / 8);

        // Pack each unique module row into a binary scanline string ONCE,
        // then repeat it moduleSize times. Avoids redundant bit-packing
        // for the moduleSize identical pixel rows within each module row.
        $packedRows = [];
        for ($moduleY = 0; $moduleY < $totalModules; $moduleY++) {
            $dataY = $moduleY - $margin;
            $inDataY = $dataY >= 0 && $dataY < $size;

            $scanline = "\x00"; // PNG filter byte: None
            for ($byteIndex = 0; $byteIndex < $bytesPerScanline; $byteIndex++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $pixelX = $byteIndex * 8 + $bit;
                    if ($pixelX < $totalPixels) {
                        $moduleX = (int) ($pixelX / $mod);
                        $dataX = $moduleX - $margin;
                        $isDark = ($inDataY && $dataX >= 0 && $dataX < $size)
                            ? $matrix->fastGet($dataX, $dataY)
                            : false;
                        if (!$isDark) {
                            $byte |= (0x80 >> $bit);
                        }
                    } else {
                        $byte |= (0x80 >> $bit);
                    }
                }
                $scanline .= chr($byte);
            }
            $packedRows[$moduleY] = $scanline;
        }

        // Build raw IDAT data: repeat each packed row moduleSize times
        $rawData = '';
        for ($moduleY = 0; $moduleY < $totalModules; $moduleY++) {
            $rawData .= str_repeat($packedRows[$moduleY], $mod);
        }

        return $this->encoder->encodeFromRaw($rawData, $totalPixels, $totalPixels);
    }
}
