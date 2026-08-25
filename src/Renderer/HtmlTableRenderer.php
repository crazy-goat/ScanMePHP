<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Matrix;
use CrazyGoat\ScanMePHP\RendererInterface;
use CrazyGoat\ScanMePHP\RenderOptions;

class HtmlTableRenderer implements RendererInterface
{
    public function __construct(
        private readonly int $moduleSize = 10,
        private readonly bool $fullHtml = false,
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
        $escBg = $this->esc($bgColor);
        $escFg = $this->esc($fgColor);

        $html = '<table style="border-collapse:collapse;border-spacing:0;background:' . $escBg . '">';

        // Same scheme as HtmlDivRenderer.
        $cellHead = '<td style="width:' . $mod . 'px;height:' . $mod . 'px;padding:0;border:0;background:';
        $cells = ['0' => $cellHead . $escBg . '"></td>', '1' => $cellHead . $escFg . '"></td>'];
        $side = str_repeat($cells['0'], $margin);
        $marginRow = str_repeat('<tr>' . str_repeat($cells['0'], $totalModules) . '</tr>', $margin);
        // The whole symbol in one strtr(): module bytes become cells and the
        // row separator becomes "close row, open row" plus both side margins.
        $html .= $marginRow . '<tr>' . $side . strtr(
            implode("\n", str_split($matrix->toModuleString(), $size)),
            $cells + ["\n" => $side . '</tr><tr>' . $side]
        ) . $side . '</tr>' . $marginRow;

        $html .= '</table>';

        if ($options->label !== null && $options->label !== '') {
            $totalPx = $totalModules * $mod;
            $fontSize = (int) ($mod * 1.5);
            $html .= '<div style="width:' . $totalPx . 'px;text-align:center;font-family:Arial,sans-serif;font-size:' . $fontSize . 'px;padding:' . (int) ($mod * 0.5) . 'px 0;background:' . $escBg . ';color:' . $escFg . '">' . htmlspecialchars($options->label, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div>';
        }

        if ($this->fullHtml) {
            return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>QR Code</title></head><body style="margin:0;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f0f0f0">' . $html . '</body></html>';
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
