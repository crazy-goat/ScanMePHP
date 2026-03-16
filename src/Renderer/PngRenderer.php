<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Exception\RenderException;
use CrazyGoat\ScanMePHP\Matrix;
use CrazyGoat\ScanMePHP\RenderOptions;
use CrazyGoat\ScanMePHP\RendererInterface;

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

        return $this->encoder->encodeStreaming(
            function (int $y) use ($matrix, $size, $margin, $totalModules): array {
                return $this->buildScanline($matrix, $size, $margin, $totalModules, $y);
            },
            $totalPixels,
            $totalPixels
        );
    }

    /**
     * Build a single scanline (row of pixels) for the given Y coordinate.
     * Uses streaming approach to avoid storing full bitmap in memory.
     *
     * @return bool[] Array of boolean pixel values for this row
     */
    private function buildScanline(Matrix $matrix, int $size, int $margin, int $totalModules, int $pixelY): array
    {
        $mod = $this->moduleSize;
        $moduleY = (int) ($pixelY / $mod);
        $dataY = $moduleY - $margin;
        $scanline = [];

        for ($moduleX = 0; $moduleX < $totalModules; $moduleX++) {
            $dataX = $moduleX - $margin;
            $isDark = ($dataX >= 0 && $dataX < $size && $dataY >= 0 && $dataY < $size)
                ? $matrix->get($dataX, $dataY)
                : false;

            // Expand this module to moduleSize pixels
            for ($px = 0; $px < $mod; $px++) {
                $scanline[] = $isDark;
            }
        }

        return $scanline;
    }
}
