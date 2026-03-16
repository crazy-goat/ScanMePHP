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

        $bitmap = $this->buildBitmap($matrix, $size, $margin, $totalModules);

        return $this->encoder->encode($bitmap, $totalPixels, $totalPixels);
    }

    /**
     * Build a 2D boolean pixel grid from the QR matrix.
     *
     * Each QR module is expanded to moduleSize x moduleSize pixels.
     * true = dark (black module), false = light (white / margin).
     *
     * @return bool[][] Pixel grid [y][x]
     */
    private function buildBitmap(Matrix $matrix, int $size, int $margin, int $totalModules): array
    {
        $mod = $this->moduleSize;
        $bitmap = [];

        for ($moduleY = 0; $moduleY < $totalModules; $moduleY++) {
            $dataY = $moduleY - $margin;

            // Build one row of module values
            $moduleRow = [];
            for ($moduleX = 0; $moduleX < $totalModules; $moduleX++) {
                $dataX = $moduleX - $margin;
                $isDark = ($dataX >= 0 && $dataX < $size && $dataY >= 0 && $dataY < $size)
                    ? $matrix->get($dataX, $dataY)
                    : false;
                $moduleRow[] = $isDark;
            }

            // Expand module row into moduleSize pixel rows
            $pixelRow = [];
            foreach ($moduleRow as $isDark) {
                for ($px = 0; $px < $mod; $px++) {
                    $pixelRow[] = $isDark;
                }
            }

            // Duplicate the pixel row moduleSize times
            for ($py = 0; $py < $mod; $py++) {
                $bitmap[] = $pixelRow;
            }
        }

        return $bitmap;
    }
}
