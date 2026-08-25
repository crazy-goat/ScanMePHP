<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Matrix;
use CrazyGoat\ScanMePHP\RendererInterface;
use CrazyGoat\ScanMePHP\RenderOptions;

abstract class AbstractAsciiRenderer implements RendererInterface
{
    public function __construct(
        private readonly int $sideMargin = 0,
    ) {
    }

    public function getContentType(): string
    {
        return 'text/plain';
    }

    abstract public function render(Matrix $matrix, RenderOptions $options): string;

    /**
     * Render the symbol as one text block (rows joined by "\n") by replacing
     * every module byte with its glyph in one or two passes over the whole
     * matrix instead of one method call per module.
     */
    protected function renderRows(Matrix $matrix, RenderOptions $options, string $lightGlyph, string $darkGlyph, string $bgChar): string
    {
        $size = $matrix->getSize();
        $block = implode("\n", str_split($matrix->toModuleString(), $size));
        // A single-byte light glyph (the usual space) goes through the
        // byte-table strtr(), which is ~100× cheaper than a str_replace() pass.
        $block = \strlen($lightGlyph) === 1
            ? str_replace('1', $darkGlyph, strtr($block, '0', $lightGlyph))
            : str_replace(['1', '0'], [$darkGlyph, $lightGlyph], $block);

        return $this->assemble($block, $size, $options, $bgChar);
    }

    /**
     * Wrap the rendered symbol block with the side margin, top/bottom quiet
     * zone (issue #35: both sides, also when inverted) and the optional label.
     *
     * @param string $block Symbol rows joined by "\n", without margins
     */
    protected function assemble(string $block, int $size, RenderOptions $options, string $bgChar): string
    {
        $sideMargin = $this->sideMargin;
        if ($sideMargin > 0) {
            $side = str_repeat($bgChar, $sideMargin);
            $block = $side . str_replace("\n", $side . "\n" . $side, $block) . $side;
        }

        $totalWidth = $size + (2 * $sideMargin);
        $marginLines = $options->margin > 0
            ? array_fill(0, $options->margin, str_repeat($bgChar, $totalWidth))
            : [];

        $out = $marginLines;
        $out[] = $block;
        $this->appendLabel($out, $options->label, $totalWidth, $bgChar);
        foreach ($marginLines as $line) {
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    protected function getSideMargin(): int
    {
        return $this->sideMargin;
    }

    protected function createMarginLine(int $qrSize, int $sideMargin, string $fillChar = ' '): string
    {
        return str_repeat($fillChar, $qrSize + (2 * $sideMargin));
    }

    protected function centerText(string $text, int $width, string $fillChar = ' '): string
    {
        $textLength = mb_strlen($text);
        if ($textLength >= $width) {
            return $text;
        }

        $leftPad = (int) (($width - $textLength) / 2);
        $rightPad = $width - $textLength - $leftPad;
        return str_repeat($fillChar, $leftPad) . $text . str_repeat($fillChar, $rightPad);
    }

    protected function appendLabel(array &$lines, ?string $label, int $totalWidth, string $bgChar = ' '): void
    {
        if ($label !== null && $label !== '') {
            $lines[] = str_repeat($bgChar, $totalWidth);
            $lines[] = $this->centerText(' ' . $label . ' ', $totalWidth, $bgChar);
        }
    }
}
