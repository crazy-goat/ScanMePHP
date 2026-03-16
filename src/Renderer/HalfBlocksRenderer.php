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
        $invert = $options->invert;
        $bgChar = $invert ? '█' : ' ';
        $result = '';

        for ($i = 0; $i < $margin; $i++) {
            $result .= $this->createMarginLine($size, $sideMargin, $bgChar) . "\n";
        }

        for ($y = 0; $y < $size; $y += 2) {
            $line = str_repeat($bgChar, $sideMargin);
            for ($x = 0; $x < $size; $x++) {
                $top = $matrix->fastGet($x, $y);
                $bottom = ($y + 1 < $size) ? $matrix->fastGet($x, $y + 1) : false;
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
            $result .= $line . "\n";
        }

        $totalWidth = $size + (2 * $sideMargin);
        if ($options->label !== null && $options->label !== '') {
            $result .= str_repeat($bgChar, $totalWidth) . "\n";
            $result .= $this->centerText(' ' . $options->label . ' ', $totalWidth, $bgChar) . "\n";
        }

        return rtrim($result, "\n");
    }
}
