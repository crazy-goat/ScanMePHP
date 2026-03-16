<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Matrix;
use CrazyGoat\ScanMePHP\RenderOptions;

class SimpleRenderer extends AbstractAsciiRenderer
{
    public function render(Matrix $matrix, RenderOptions $options): string
    {
        $size = $matrix->getSize();
        $margin = $options->margin;
        $sideMargin = $this->getSideMargin();
        $invert = $options->invert;
        $bgChar = $invert ? '●' : ' ';
        $darkChar = $invert ? ' ' : '●';
        $result = '';

        for ($i = 0; $i < $margin; $i++) {
            $result .= $this->createMarginLine($size, $sideMargin, $bgChar) . "\n";
        }

        for ($y = 0; $y < $size; $y++) {
            $line = str_repeat($bgChar, $sideMargin);
            for ($x = 0; $x < $size; $x++) {
                $isDark = $matrix->get($x, $y);
                $line .= ($invert ? !$isDark : $isDark) ? $darkChar : $bgChar;
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
