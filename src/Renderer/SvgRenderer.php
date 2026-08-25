<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Exception\InvalidConfigurationException;
use CrazyGoat\ScanMePHP\Matrix;
use CrazyGoat\ScanMePHP\ModuleStyle;
use CrazyGoat\ScanMePHP\RendererInterface;
use CrazyGoat\ScanMePHP\RenderOptions;

class SvgRenderer implements RendererInterface
{
    public function __construct(
        private readonly int $moduleSize = 10,
    ) {
        if ($this->moduleSize <= 0) {
            throw InvalidConfigurationException::invalidModuleSize($this->moduleSize);
        }
    }

    public function getContentType(): string
    {
        return 'image/svg+xml';
    }

    public function render(Matrix $matrix, RenderOptions $options): string
    {
        $size = $matrix->getSize();
        $margin = $options->margin;
        $totalModules = $size + (2 * $margin);
        $totalSize = $totalModules * $this->moduleSize;

        $fgColor = $options->getEffectiveForegroundColor();
        $bgColor = $options->getEffectiveBackgroundColor();

        $svg = $this->generateSvgHeader($totalSize);
        $svg .= $this->generateBackground($totalSize, $bgColor);
        $svg .= $this->generateModules($matrix, $margin, $fgColor, $options->moduleStyle, $options->invert);

        if ($options->label !== null && $options->label !== '') {
            $svg .= $this->generateLabel($options->label, $totalSize, $size, $margin);
        }

        return $svg . '</svg>';
    }

    private function generateSvgHeader(int $size): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" ' .
            'viewBox="0 0 %d %d" width="%d" height="%d">' . "\n",
            $size,
            $size,
            $size,
            $size
        );
    }

    private function generateBackground(int $size, string $color): string
    {
        return sprintf(
            '  <rect width="%d" height="%d" fill="%s"/>' . "\n",
            $size,
            $size,
            $this->escapeColor($color)
        );
    }

    /**
     * Emit the dark modules (light ones when inverted).
     *
     * Works on the matrix module string: rows are joined with "\n" so one
     * preg_match_all() over the whole symbol yields every run of dark modules
     * with its offset, and offset → (x, y) is a division by size + 1.
     * Square style draws all runs as one <path> (each run a closed sub-path,
     * so abutting modules cannot show anti-aliasing seams and the output is
     * ~5× smaller than one <rect> per module); Rounded/Dot stay one element
     * per module. Finder-pattern modules keep per-module rounded rects.
     */
    private function generateModules(Matrix $matrix, int $margin, string $color, ModuleStyle $style, bool $invert): string
    {
        $size = $matrix->getSize();
        $stride = $size + 1;
        $mod = $this->moduleSize;
        $escapedColor = $this->escapeColor($color);

        $modules = $matrix->toModuleString();
        if ($invert) {
            $modules = strtr($modules, '01', '10');
        }

        // Pixel coordinates as strings, looked up instead of converted per module.
        $coord = [];
        for ($i = 0; $i < $size; $i++) {
            $coord[$i] = (string) (($i + $margin) * $mod);
        }

        // Finder patterns (7×7 corners) are drawn separately with rounded corners.
        $finderRadius = sprintf('%.1f', $mod * 0.15);
        $finderTail = '" width="' . $mod . '" height="' . $mod . '" fill="' . $escapedColor
            . '" rx="' . $finderRadius . '" ry="' . $finderRadius . '"/>' . "\n";
        $result = '';
        $blank = '0000000';
        $rows = [];
        for ($y = 0, $offset = 0; $y < $size; $y++, $offset += $size) {
            $row = substr($modules, $offset, $size);
            if ($y < 7 || $y >= $size - 7) {
                $result .= $this->finderModules($row, 0, $coord[$y], $coord, $finderTail);
                $row = substr_replace($row, $blank, 0, 7);
                if ($y < 7) {
                    $result .= $this->finderModules($row, $size - 7, $coord[$y], $coord, $finderTail);
                    $row = substr_replace($row, $blank, $size - 7, 7);
                }
            }
            $rows[] = $row;
        }
        $joined = implode("\n", $rows);

        preg_match_all($style === ModuleStyle::Square ? '/1+/' : '/1/', $joined, $matches, PREG_OFFSET_CAPTURE);
        if ($matches[0] === []) {
            return $result;
        }

        switch ($style) {
            case ModuleStyle::Square:
                // "h<w>v<mod>h-<w>z" per run length, computed once.
                $segment = [];
                for ($w = 1; $w <= $size; $w++) {
                    $segment[$w] = 'h' . ($w * $mod) . 'v' . $mod . 'h-' . ($w * $mod) . 'z';
                }
                $d = '';
                foreach ($matches[0] as [$run, $offset]) {
                    $d .= 'M' . $coord[$offset % $stride] . ' ' . $coord[intdiv($offset, $stride)] . $segment[\strlen($run)];
                }
                $result .= '  <path fill="' . $escapedColor . '" d="' . $d . '"/>' . "\n";
                break;

            case ModuleStyle::Rounded:
                $radius = sprintf('%.1f', $mod * 0.3);
                $tail = '" width="' . $mod . '" height="' . $mod . '" fill="' . $escapedColor
                    . '" rx="' . $radius . '" ry="' . $radius . '"/>' . "\n";
                foreach ($matches[0] as [, $offset]) {
                    $result .= '  <rect x="' . $coord[$offset % $stride] . '" y="' . $coord[intdiv($offset, $stride)] . $tail;
                }
                break;

            case ModuleStyle::Dot:
                $centre = [];
                $half = intdiv($mod, 2);
                foreach ($coord as $i => $px) {
                    $centre[$i] = (string) ((int) $px + $half);
                }
                $tail = '" r="' . sprintf('%.1f', $mod * 0.4) . '" fill="' . $escapedColor . '"/>' . "\n";
                foreach ($matches[0] as [, $offset]) {
                    $result .= '  <circle cx="' . $centre[$offset % $stride] . '" cy="' . $centre[intdiv($offset, $stride)] . $tail;
                }
                break;
        }

        return $result;
    }

    /**
     * Rounded rects for the seven finder-pattern columns starting at $x0 of one row.
     *
     * @param list<string> $coord Pixel coordinate per module index
     */
    private function finderModules(string $row, int $x0, string $py, array $coord, string $tail): string
    {
        $out = '';
        for ($x = $x0, $end = $x0 + 7; $x < $end; $x++) {
            if ($row[$x] === '1') {
                $out .= '  <rect x="' . $coord[$x] . '" y="' . $py . $tail;
            }
        }

        return $out;
    }

    private function generateLabel(string $label, int $totalSize, int $matrixSize, int $margin): string
    {
        $labelY = ($matrixSize + 2 * $margin + 2) * $this->moduleSize;
        $fontSize = $this->moduleSize * 1.5;

        return sprintf(
            '  <text x="%d" y="%d" text-anchor="middle" font-family="Arial, sans-serif" ' .
            'font-size="%.1f" fill="#000000">%s</text>' . "\n",
            $totalSize / 2,
            $labelY,
            $fontSize,
            htmlspecialchars($label, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        );
    }

    private function escapeColor(string $color): string
    {
        // Basic validation - only allow hex colors
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return $color;
        }

        // Default to black if invalid
        return '#000000';
    }
}
