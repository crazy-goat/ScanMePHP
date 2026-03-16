<?php

declare(strict_types=1);

namespace ScanMePHP\Renderer;

use ScanMePHP\Matrix;
use ScanMePHP\RenderOptions;
use ScanMePHP\RendererInterface;

class HtmlTableRenderer implements RendererInterface
{
    public function __construct(
        private int $moduleSize = 10,
        private bool $fullHtml = false,
    ) {
    }

    public function getContentType(): string
    {
        return 'text/html';
    }

    public function render(Matrix $matrix, RenderOptions $options): string
    {
        $size = $matrix->getSize();
        $margin = $options->margin;
        $fgColor = $options->getEffectiveForegroundColor();
        $bgColor = $options->getEffectiveBackgroundColor();
        $mod = $this->moduleSize;
        $totalModules = $size + (2 * $margin);

        $html = sprintf(
            '<table style="border-collapse:collapse;border-spacing:0;background:%s">',
            $this->esc($bgColor)
        );

        for ($y = 0; $y < $totalModules; $y++) {
            $html .= '<tr>';
            for ($x = 0; $x < $totalModules; $x++) {
                $dataX = $x - $margin;
                $dataY = $y - $margin;
                $isDark = ($dataX >= 0 && $dataX < $size && $dataY >= 0 && $dataY < $size)
                    ? $matrix->get($dataX, $dataY)
                    : false;
                $color = $isDark ? $fgColor : $bgColor;
                $html .= sprintf(
                    '<td style="width:%dpx;height:%dpx;padding:0;border:0;background:%s"></td>',
                    $mod, $mod, $this->esc($color)
                );
            }
            $html .= '</tr>';
        }

        $html .= '</table>';

        if ($options->label !== null && $options->label !== '') {
            $totalPx = $totalModules * $mod;
            $fontSize = (int) ($mod * 1.5);
            $html .= sprintf(
                '<div style="width:%dpx;text-align:center;font-family:Arial,sans-serif;font-size:%dpx;padding:%dpx 0;background:%s;color:%s">%s</div>',
                $totalPx, $fontSize, (int) ($mod * 0.5), $this->esc($bgColor), $this->esc($fgColor),
                htmlspecialchars($options->label, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            );
        }

        if ($this->fullHtml) {
            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>QR Code</title></head><body style="margin:0;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f0f0f0">' . $html . '</body></html>';
        }

        return $html;
    }

    private function esc(string $color): string
    {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return $color;
        }
        return '#000000';
    }
}
