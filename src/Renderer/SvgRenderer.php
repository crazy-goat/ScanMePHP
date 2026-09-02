<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\ModuleStyle;
use CrazyGoat\ScanMePHP\Options\RenderOptionsInterface;
use CrazyGoat\ScanMePHP\Region;
use CrazyGoat\ScanMePHP\Renderer\Options\SvgOptions;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\TextRegion;

final class SvgRenderer implements RendererInterface
{
    /** Font size for text lines, as a multiple of the module size. */
    private const TEXT_SCALE = 1.5;

    /** Module rows of vertical space reserved per text line. */
    private const TEXT_ROWS = 2;

    public function getFormat(): string
    {
        return 'svg';
    }

    public function getContentType(): string
    {
        return 'image/svg+xml';
    }

    public function getCapabilities(): RendererCapabilities
    {
        return new RendererCapabilities(
            moduleShapes: [ModuleShape::Square],
            text: true,
            color: true,
            nonUniformRows: true,
            positionedText: true,
            optionsClass: SvgOptions::class,
        );
    }

    public function render(Symbol $symbol, ?RenderOptionsInterface $options = null): string
    {
        $options = $options instanceof SvgOptions ? $options : new SvgOptions();
        $layout = Layout::of($symbol, $options);
        $mod = $options->moduleSize;

        $lines = $options->resolveTextLines($symbol);

        $canvasWidth = $layout->totalWidth * $mod;
        // Text sits outside the quiet zone rather than inside it: drawing it
        // into the symbol's own box would either overlap the quiet zone or fall
        // outside the viewBox and silently not render at all. An add-on's
        // digits therefore go above the whole symbol, not into the gap over its
        // shorter bars — see Ean\Composite.
        $aboveRows = \count($lines['above']) * self::TEXT_ROWS;
        $canvasHeight = ($layout->totalHeight + $aboveRows + (\count($lines['below']) * self::TEXT_ROWS)) * $mod;

        $svg = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" '
            . 'viewBox="0 0 %d %d" width="%d" height="%d">' . "\n",
            $canvasWidth,
            $canvasHeight,
            $canvasWidth,
            $canvasHeight
        );

        $background = $this->escapeColor($options->getEffectiveBackgroundColor());
        $svg .= sprintf(
            '  <rect width="%d" height="%d" fill="%s"/>' . "\n",
            $canvasWidth,
            $canvasHeight,
            $background
        );

        $svg .= $this->modules($symbol, $layout, $options, $aboveRows);

        $foreground = $this->escapeColor($options->getEffectiveForegroundColor());

        $baseline = 0;
        foreach ($lines['above'] as $line) {
            $baseline += self::TEXT_ROWS;
            $svg .= $this->line($line, $layout, $baseline, $mod, $foreground);
        }

        $baseline = $aboveRows + $layout->totalHeight;
        foreach ($lines['below'] as $line) {
            $baseline += self::TEXT_ROWS;
            $svg .= $this->line($line, $layout, $baseline, $mod, $foreground);
        }

        return $svg . '</svg>';
    }

    /**
     * One band of text: every region on it, each centred on its own columns.
     *
     * @param list<TextRegion> $regions
     */
    private function line(array $regions, Layout $layout, int $baseline, int $mod, string $colour): string
    {
        $svg = '';
        foreach ($regions as $region) {
            $svg .= sprintf(
                '  <text x="%d" y="%d" text-anchor="middle" font-family="Arial, sans-serif" '
                . 'font-size="%.1f" fill="%s">%s</text>' . "\n",
                $layout->columnOffset($region->centre()) * $mod,
                ($baseline - 1) * $mod + intdiv($mod, 2),
                $mod * self::TEXT_SCALE,
                $colour,
                htmlspecialchars($region->text, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            );
        }

        return $svg;
    }

    /**
     * Emit the dark modules (light ones when inverted).
     *
     * Works on the module string with rows joined by "\n", so one
     * preg_match_all() over the whole symbol yields every run of dark modules
     * with its offset, and offset → (x, y) is a division by the row stride.
     * Square style draws all runs as one <path> — each run a closed sub-path,
     * so abutting modules cannot show anti-aliasing seams and the output is
     * ~5× smaller than one <rect> per module; Rounded and Dot stay one element
     * per module.
     */
    private function modules(Symbol $symbol, Layout $layout, SvgOptions $options, int $topOffset): string
    {
        $width = $layout->width;
        $stride = $width + 1;
        $mod = $options->moduleSize;
        $color = $this->escapeColor($options->getEffectiveForegroundColor());

        $rows = $symbol->rows();
        if ($options->invert) {
            foreach ($rows as $index => $row) {
                $rows[$index] = strtr($row, '01', '10');
            }
        }

        // Pixel coordinates as strings, looked up instead of recomputed per module.
        $x = [];
        for ($i = 0; $i < $width; $i++) {
            $x[$i] = (string) ($layout->columnOffset($i) * $mod);
        }
        $y = [];
        $rowPixelHeight = [];
        foreach ($layout->rowOffsets as $index => $offset) {
            // $topOffset is the band reserved for text drawn above the symbol,
            // which the layout knows nothing about: it measures the symbol.
            $y[$index] = (string) (($offset + $topOffset) * $mod);
            $rowPixelHeight[$index] = $layout->rowHeights[$index] * $mod;
        }

        $result = '';
        $regions = $options->roundFinderRegions ? $symbol->getFinderRegions() : [];
        if ($regions !== []) {
            $result = $this->finderRegions($regions, $rows, $x, $y, $rowPixelHeight, $mod, $color);
            // Blanked so the run matcher below cannot draw them a second time.
            $rows = $this->withoutRegions($regions, $rows);
        }

        $joined = implode("\n", $rows);
        preg_match_all(
            $options->moduleStyle === ModuleStyle::Square ? '/1+/' : '/1/',
            $joined,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        if ($matches[0] === []) {
            return $result;
        }

        switch ($options->moduleStyle) {
            case ModuleStyle::Square:
                // "h<w>v<h>h-<w>z" per run length and row height, computed once
                // each. Uniform-row symbols only ever populate one height key.
                $segment = [];
                $path = '';
                foreach ($matches[0] as [$run, $offset]) {
                    $row = intdiv($offset, $stride);
                    $runWidth = \strlen($run) * $mod;
                    $height = $rowPixelHeight[$row];
                    $segment[$height][$runWidth] ??= 'h' . $runWidth . 'v' . $height . 'h-' . $runWidth . 'z';
                    $path .= 'M' . $x[$offset % $stride] . ' ' . $y[$row] . $segment[$height][$runWidth];
                }
                $result .= '  <path fill="' . $color . '" d="' . $path . '"/>' . "\n";
                break;

            case ModuleStyle::Rounded:
                $radius = sprintf('%.1f', $mod * 0.3);
                foreach ($matches[0] as [, $offset]) {
                    $row = intdiv($offset, $stride);
                    $result .= '  <rect x="' . $x[$offset % $stride] . '" y="' . $y[$row]
                        . '" width="' . $mod . '" height="' . $rowPixelHeight[$row]
                        . '" fill="' . $color . '" rx="' . $radius . '" ry="' . $radius . '"/>' . "\n";
                }
                break;

            case ModuleStyle::Dot:
                $half = intdiv($mod, 2);
                foreach ($matches[0] as [, $offset]) {
                    $row = intdiv($offset, $stride);
                    $result .= '  <circle cx="' . ((int) $x[$offset % $stride] + $half)
                        . '" cy="' . ((int) $y[$row] + intdiv($rowPixelHeight[$row], 2))
                        . '" r="' . sprintf('%.1f', $mod * 0.4) . '" fill="' . $color . '"/>' . "\n";
                }
                break;
        }

        return $result;
    }

    /**
     * Draw the symbology's structurally special regions with rounded corners.
     *
     * The renderer does not know what these regions mean — for QR they are the
     * three finder patterns, another symbology may report none or something
     * else entirely.
     *
     * @param non-empty-list<Region> $regions
     * @param list<string> $rows
     * @param array<int, string> $x
     * @param array<int, string> $y
     * @param array<int, int> $rowPixelHeight
     */
    private function finderRegions(
        array $regions,
        array $rows,
        array $x,
        array $y,
        array $rowPixelHeight,
        int $mod,
        string $color
    ): string {
        $radius = sprintf('%.1f', $mod * 0.15);
        $out = '';

        foreach ($regions as $region) {
            $lastRow = min($region->y + $region->height, \count($rows));
            for ($row = $region->y; $row < $lastRow; $row++) {
                $lastColumn = min($region->x + $region->width, \strlen($rows[$row]));
                for ($column = $region->x; $column < $lastColumn; $column++) {
                    if ($rows[$row][$column] === '1') {
                        $out .= '  <rect x="' . $x[$column] . '" y="' . $y[$row]
                            . '" width="' . $mod . '" height="' . $rowPixelHeight[$row]
                            . '" fill="' . $color . '" rx="' . $radius . '" ry="' . $radius . '"/>' . "\n";
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The module rows with every given region cleared to light.
     *
     * @param non-empty-list<Region> $regions
     * @param list<string> $rows
     * @return list<string>
     */
    private function withoutRegions(array $regions, array $rows): array
    {
        $cleared = [];

        foreach ($rows as $index => $modules) {
            foreach ($regions as $region) {
                if ($index < $region->y || $index >= $region->y + $region->height) {
                    continue;
                }
                $lastColumn = min($region->x + $region->width, \strlen($modules));
                for ($column = $region->x; $column < $lastColumn; $column++) {
                    $modules[$column] = '0';
                }
            }
            $cleared[] = $modules;
        }

        return $cleared;
    }

    private function escapeColor(string $color): string
    {
        // Only literal hex colours reach the document; anything else would let
        // an option value inject markup or a url() reference into the SVG.
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : '#000000';
    }
}
