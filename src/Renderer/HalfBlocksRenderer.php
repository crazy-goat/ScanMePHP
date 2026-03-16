<?php

declare(strict_types=1);

namespace ScanMePHP\Renderer;

use ScanMePHP\Matrix;
use ScanMePHP\RenderOptions;

class HalfBlocksRenderer extends AbstractAsciiRenderer
{
    public function render(Matrix $matrix, RenderOptions $options): string
    {
        $size = $matrix->getSize();
        $margin = $options->margin;
        $sideMargin = $this->getSideMargin();
        $lines = [];

        $invert = $options->invert;
        $bgChar = $invert ? '█' : ' ';

        for ($i = 0; $i < $margin; $i++) {
            $lines[] = $this->createMarginLine($size, $sideMargin, $bgChar);
        }

        for ($y = 0; $y < $size; $y += 2) {
            $line = str_repeat($bgChar, $sideMargin);
            for ($x = 0; $x < $size; $x++) {
                $top = $matrix->get($x, $y);
                $bottom = ($y + 1 < $size) ? $matrix->get($x, $y + 1) : false;
                $top = $invert ? !$top : $top;
                $bottom = $invert ? !$bottom : $bottom;
                $line .= match ([$top, $bottom]) {
                    [false, false] => ' ',
                    [false, true] => '▄',
                    [true, false] => '▀',
                    [true, true] => '█',
                };
            }
            $line .= str_repeat($bgChar, $sideMargin);
            $lines[] = $line;
        }

        $totalWidth = $size + (2 * $sideMargin);
        $this->appendLabel($lines, $options->label, $totalWidth, $bgChar);

        return implode("\n", $lines);
    }
}
