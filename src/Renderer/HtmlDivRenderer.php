<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Matrix;
use CrazyGoat\ScanMePHP\RendererInterface;
use CrazyGoat\ScanMePHP\RenderOptions;

class HtmlDivRenderer implements RendererInterface
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

        $html = '<div style="display:inline-block;background:' . $escBg . ';padding:0;line-height:0">';

        // One cell string per module colour; quiet-zone rows are built once.
        $cellHead = '<div style="width:' . $mod . 'px;height:' . $mod . 'px;background:';
        $cells = ['0' => $cellHead . $escBg . '"></div>', '1' => $cellHead . $escFg . '"></div>'];
        $side = str_repeat($cells['0'], $margin);
        $marginRow = str_repeat('<div style="display:flex">' . str_repeat($cells['0'], $totalModules) . '</div>', $margin);
        // The whole symbol in one strtr(): module bytes become cells and the
        // row separator becomes "close row, open row" plus both side margins.
        $html .= $marginRow . '<div style="display:flex">' . $side . strtr(
            implode("\n", str_split($matrix->toModuleString(), $size)),
            $cells + ["\n" => $side . '</div><div style="display:flex">' . $side]
        ) . $side . '</div>' . $marginRow;

        if ($options->label !== null && $options->label !== '') {
            $fontSize = (int) ($mod * 1.5);
            $html .= '<div style="text-align:center;font-family:Arial,sans-serif;font-size:' . $fontSize . 'px;padding:' . (int) ($mod * 0.5) . 'px 0;background:' . $escBg . ';color:' . $escFg . '">' . htmlspecialchars($options->label, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div>';
        }

        $html .= '</div>';

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
