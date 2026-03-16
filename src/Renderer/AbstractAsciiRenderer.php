<?php

declare(strict_types=1);

namespace ScanMePHP\Renderer;

use ScanMePHP\Matrix;
use ScanMePHP\RenderOptions;
use ScanMePHP\RendererInterface;

abstract class AbstractAsciiRenderer implements RendererInterface
{
    public function __construct(
        private int $sideMargin = 0,
    ) {
    }

    public function getContentType(): string
    {
        return 'text/plain';
    }

    abstract public function render(Matrix $matrix, RenderOptions $options): string;

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
