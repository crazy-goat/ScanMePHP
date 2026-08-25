<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Matrix;
use CrazyGoat\ScanMePHP\RenderOptions;

class HalfBlocksRenderer extends AbstractAsciiRenderer
{
    /** Indexed by top bit | bottom bit << 1, i.e. the digits '0'..'3'. */
    private const GLYPHS = [' ', '▀', '▄', '█'];
    private const GLYPHS_INVERTED = ['█', '▄', '▀', ' '];

    public function render(Matrix $matrix, RenderOptions $options): string
    {
        $size = $matrix->getSize();
        $modules = $matrix->toModuleString();
        $invert = $options->invert;

        // Split the symbol into the even rows (top half of each text line) and
        // the odd rows (bottom half). size is always odd, so the last line has
        // an implicit light bottom row — which renders dark when inverted,
        // exactly like a real light module would.
        $top = [];
        $bottom = [];
        for ($y = 0, $offset = 0; $y < $size; $y += 2, $offset += 2 * $size) {
            $top[] = substr($modules, $offset, $size);
            $bottom[] = $y + 1 < $size ? substr($modules, $offset + $size, $size) : str_repeat('0', $size);
        }

        // Byte-wise OR merges both halves into one digit per column:
        // '0'|'0'='0', '1'|'0'='1', '0'|'2'='2', '1'|'2'='3' ("\n"|"\n"="\n").
        $pairs = implode("\n", $top) | strtr(implode("\n", $bottom), '1', '2');
        $glyphs = $invert ? self::GLYPHS_INVERTED : self::GLYPHS;
        // The space glyph is one byte, so its pass can use the byte-table strtr().
        $space = $invert ? '3' : '0';
        $pairs = strtr($pairs, $space, ' ');
        $digits = ['0', '1', '2', '3'];
        unset($digits[(int) $space], $glyphs[(int) $space]);
        $block = str_replace(array_values($digits), array_values($glyphs), $pairs);

        return $this->assemble($block, $size, $options, $invert ? '█' : ' ');
    }
}
