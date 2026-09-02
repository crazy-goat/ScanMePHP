<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\RenderOptionsInterface;
use CrazyGoat\ScanMePHP\Renderer\Options\HtmlOptions;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * Renders a symbol as an HTML grid, one element per module.
 *
 * The quiet zone is drawn as a handful of sized blocks rather than one element
 * per module: it carries no information, and for a QR symbol with a 4-module
 * zone the per-module form is several hundred elements of pure padding.
 */
final class HtmlRenderer implements RendererInterface
{
    public function __construct(
        private readonly HtmlMode $mode = HtmlMode::Div,
    ) {
    }

    public function getFormat(): string
    {
        return $this->mode->value;
    }

    public function getContentType(): string
    {
        return 'text/html';
    }

    public function getCapabilities(): RendererCapabilities
    {
        return new RendererCapabilities(
            moduleShapes: [ModuleShape::Square],
            text: true,
            color: true,
            nonUniformRows: true,
            optionsClass: HtmlOptions::class,
        );
    }

    public function render(Symbol $symbol, ?RenderOptionsInterface $options = null): string
    {
        $options = $options instanceof HtmlOptions ? $options : new HtmlOptions();
        $layout = Layout::of($symbol, $options);
        $mod = $options->moduleSize;

        $background = $this->escapeColor($options->getEffectiveBackgroundColor());
        $foreground = $this->escapeColor($options->getEffectiveForegroundColor());
        $table = $this->mode === HtmlMode::Table;

        $pixelWidth = $layout->totalWidth * $mod;
        $left = $this->spacer($layout->quietZone->left, $mod, $background);
        $right = $this->spacer($layout->quietZone->right, $mod, $background);

        $markup = $table
            ? '<table style="border-collapse:collapse;border-spacing:0;background:' . $background . '">'
            : '<div style="display:inline-block;background:' . $background . ';padding:0;line-height:0">';

        $markup .= $this->quietRow($layout, $mod, $layout->quietZone->top, $background);

        $rows = $symbol->rows();
        if ($layout->uniformRows) {
            // One strtr() over the whole symbol: module bytes become cells and
            // the row separator becomes "close row, open row" plus both side
            // spacers. Far cheaper than concatenating per module.
            $cells = $this->cells($mod, $mod, $foreground, $background);
            $open = $table ? '<tr>' : '<div style="display:flex">';
            $close = $table ? '</tr>' : '</div>';
            $markup .= $open . $left . strtr(
                implode("\n", $rows),
                $cells + ["\n" => $right . $close . $open . $left]
            ) . $right . $close;
        } else {
            foreach ($rows as $index => $row) {
                $cells = $this->cells($mod, $layout->rowHeights[$index] * $mod, $foreground, $background);
                $markup .= $this->wrapRow($left . strtr($row, $cells) . $right);
            }
        }

        $markup .= $this->quietRow($layout, $mod, $layout->quietZone->bottom, $background);
        $markup .= $table ? '</table>' : '</div>';

        foreach ([$symbol->getText(), $options->label] as $line) {
            if ($line !== null && $line !== '') {
                $markup .= $this->caption($line, $pixelWidth, $mod, $foreground, $background);
            }
        }

        if (!$options->fullDocument) {
            return $markup;
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'
            . htmlspecialchars($options->title, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</title></head><body style="margin:0;display:flex;justify-content:center;'
            . 'align-items:center;min-height:100vh;background:#f0f0f0">' . $markup . '</body></html>';
    }

    /** @return array<string, string> Module byte => cell markup */
    private function cells(int $widthPx, int $heightPx, string $foreground, string $background): array
    {
        $head = $this->mode === HtmlMode::Table
            ? '<td style="padding:0;border:0;width:' . $widthPx . 'px;height:' . $heightPx . 'px;background:'
            : '<div style="width:' . $widthPx . 'px;height:' . $heightPx . 'px;background:';
        $tail = $this->mode === HtmlMode::Table ? '"></td>' : '"></div>';

        return [
            '0' => $head . $background . $tail,
            '1' => $head . $foreground . $tail,
        ];
    }

    private function wrapRow(string $inner): string
    {
        return $this->mode === HtmlMode::Table
            ? '<tr>' . $inner . '</tr>'
            : '<div style="display:flex">' . $inner . '</div>';
    }

    /**
     * The left or right quiet zone as one sized block spanning $modules columns.
     *
     * The colspan matters: a lone <td> of a fixed width in a border-collapsed
     * table competes with the module cells for column widths and skews the
     * grid, which on a barcode means unscannable.
     */
    private function spacer(int $modules, int $mod, string $background): string
    {
        if ($modules === 0) {
            return '';
        }

        $widthPx = $modules * $mod;

        return $this->mode === HtmlMode::Table
            ? '<td colspan="' . $modules . '" style="padding:0;border:0;width:' . $widthPx
                . 'px;background:' . $background . '"></td>'
            : '<div style="width:' . $widthPx . 'px;background:' . $background . '"></div>';
    }

    private function quietRow(Layout $layout, int $mod, int $modules, string $background): string
    {
        if ($modules === 0) {
            return '';
        }

        $style = 'width:' . ($layout->totalWidth * $mod) . 'px;height:' . ($modules * $mod)
            . 'px;background:' . $background;

        return $this->mode === HtmlMode::Table
            ? '<tr><td colspan="' . $layout->totalWidth . '" style="padding:0;border:0;' . $style . '"></td></tr>'
            : '<div style="' . $style . '"></div>';
    }

    private function caption(string $text, int $widthPx, int $mod, string $foreground, string $background): string
    {
        return '<div style="width:' . $widthPx . 'px;text-align:center;font-family:Arial,sans-serif;font-size:'
            . (int) ($mod * 1.5) . 'px;padding:' . (int) ($mod * 0.5) . 'px 0;background:' . $background
            . ';color:' . $foreground . '">'
            . htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</div>';
    }

    private function escapeColor(string $color): string
    {
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : '#000000';
    }
}
