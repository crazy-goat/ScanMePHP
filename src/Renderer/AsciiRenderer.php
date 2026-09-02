<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\RenderOptionsInterface;
use CrazyGoat\ScanMePHP\Renderer\Options\AsciiOptions;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * Renders a symbol as a block of text for terminals and logs.
 *
 * Colours are ignored — a character cell is the module and the terminal owns
 * the palette — but inversion is not: a symbol printed dark-on-light in a dark
 * terminal is unscannable, so the quiet zone flips with it.
 */
final class AsciiRenderer implements RendererInterface
{
    /** Indexed by top bit | bottom bit << 1, i.e. the digits '0'..'3'. */
    private const HALF_GLYPHS = [' ', '▀', '▄', '█'];

    private const HALF_GLYPHS_INVERTED = ['█', '▄', '▀', ' '];

    public function __construct(
        private readonly AsciiStyle $style = AsciiStyle::Blocks,
    ) {
    }

    public function getFormat(): string
    {
        return $this->style->value;
    }

    public function getContentType(): string
    {
        return 'text/plain';
    }

    public function getCapabilities(): RendererCapabilities
    {
        return new RendererCapabilities(
            moduleShapes: [ModuleShape::Square],
            text: true,
            color: false,
            nonUniformRows: true,
            optionsClass: AsciiOptions::class,
        );
    }

    public function render(Symbol $symbol, ?RenderOptionsInterface $options = null): string
    {
        $options = $options instanceof AsciiOptions ? $options : new AsciiOptions();
        $layout = Layout::of($symbol, $options);
        $invert = $options->invert;

        $rows = $this->expandRows($symbol, $layout);
        $block = $this->style === AsciiStyle::HalfBlocks
            ? $this->halfBlocks($rows, $layout->width, $invert)
            : $this->fullRows($rows);

        $background = $this->backgroundChar($invert);
        $lineHeight = $this->style === AsciiStyle::HalfBlocks ? 2 : 1;

        return $this->assemble($block, $layout, $options, $symbol->getText(), $background, $lineHeight);
    }

    /**
     * The symbol's module rows, each repeated to its rendered height.
     *
     * Linear symbologies carry their bar height in the row heights, so a
     * Code128 symbol is one module row that becomes dozens of text lines.
     *
     * @return list<string>
     */
    private function expandRows(Symbol $symbol, Layout $layout): array
    {
        $rows = $symbol->rows();
        if ($layout->uniformRows) {
            return $rows;
        }

        $expanded = [];
        foreach ($rows as $index => $row) {
            for ($repeat = 0, $height = $layout->rowHeights[$index]; $repeat < $height; $repeat++) {
                $expanded[] = $row;
            }
        }

        return $expanded;
    }

    /**
     * Replace every module byte with its glyph in one or two passes over the
     * whole symbol instead of one method call per module.
     *
     * @param list<string> $rows
     */
    private function fullRows(array $rows): string
    {
        $dark = $this->style === AsciiStyle::Dots ? '●' : '█';
        $block = implode("\n", $rows);

        // Module glyphs do not change with inversion — dark stays dark — so
        // only the quiet zone flips. A single-byte light glyph goes through the
        // byte-table strtr(), which is ~100× cheaper than a str_replace() pass.
        return str_replace('1', $dark, strtr($block, '0', ' '));
    }

    /**
     * Merge row pairs into half-height blocks.
     *
     * @param list<string> $rows
     */
    private function halfBlocks(array $rows, int $width, bool $invert): string
    {
        $blank = str_repeat('0', $width);
        $top = [];
        $bottom = [];
        for ($index = 0, $count = \count($rows); $index < $count; $index += 2) {
            $top[] = $rows[$index];
            $bottom[] = $rows[$index + 1] ?? $blank;
        }

        // Byte-wise OR merges both halves into one digit per column:
        // '0'|'0'='0', '1'|'0'='1', '0'|'2'='2', '1'|'2'='3' ("\n"|"\n"="\n").
        $pairs = implode("\n", $top) | strtr(implode("\n", $bottom), '1', '2');

        $glyphs = $invert ? self::HALF_GLYPHS_INVERTED : self::HALF_GLYPHS;
        // The space glyph is one byte, so its pass can use the byte-table strtr().
        $space = $invert ? '3' : '0';
        $pairs = strtr($pairs, $space, ' ');
        $digits = ['0', '1', '2', '3'];
        unset($digits[(int) $space], $glyphs[(int) $space]);

        return str_replace(array_values($digits), array_values($glyphs), $pairs);
    }

    /**
     * Wrap the symbol block in its quiet zone, side margin and text lines.
     *
     * @param int $lineHeight Module rows represented by one text line
     */
    private function assemble(
        string $block,
        Layout $layout,
        AsciiOptions $options,
        ?string $symbolText,
        string $background,
        int $lineHeight
    ): string {
        $left = str_repeat($background, $layout->quietZone->left + $options->sideMargin);
        $right = str_repeat($background, $layout->quietZone->right + $options->sideMargin);
        if ($left !== '' || $right !== '') {
            $block = $left . str_replace("\n", $right . "\n" . $left, $block) . $right;
        }

        $totalWidth = $layout->totalWidth + (2 * $options->sideMargin);
        $blankLine = str_repeat($background, $totalWidth);

        $lines = [];
        for ($row = 0; $row < $layout->quietZone->top; $row += $lineHeight) {
            $lines[] = $blankLine;
        }
        $lines[] = $block;

        foreach ([$symbolText, $options->label] as $text) {
            if ($text !== null && $text !== '') {
                $lines[] = $blankLine;
                $lines[] = $this->centre(' ' . $text . ' ', $totalWidth, $background);
            }
        }

        for ($row = 0; $row < $layout->quietZone->bottom; $row += $lineHeight) {
            $lines[] = $blankLine;
        }

        return implode("\n", $lines);
    }

    private function centre(string $text, int $width, string $fill): string
    {
        $length = mb_strlen($text);
        if ($length >= $width) {
            return $text;
        }

        $leftPad = intdiv($width - $length, 2);

        return str_repeat($fill, $leftPad) . $text . str_repeat($fill, $width - $length - $leftPad);
    }

    private function backgroundChar(bool $invert): string
    {
        if (!$invert) {
            return ' ';
        }

        return $this->style === AsciiStyle::Dots ? '●' : '█';
    }
}
