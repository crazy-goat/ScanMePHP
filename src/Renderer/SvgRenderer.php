<?php

declare(strict_types=1);

namespace ScanMePHP\Renderer;

use ScanMePHP\Exception\InvalidConfigurationException;
use ScanMePHP\Matrix;
use ScanMePHP\ModuleStyle;
use ScanMePHP\RenderOptions;
use ScanMePHP\RendererInterface;

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
        $svg .= $this->generateModules($matrix, $margin, $fgColor, $options->moduleStyle);

        if ($options->label !== null && $options->label !== '') {
            $svg .= $this->generateLabel($options->label, $totalSize, $size, $margin);
        }

        $svg .= '</svg>';

        return $svg;
    }

    private function generateSvgHeader(int $size): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" ' .
            'viewBox="0 0 %d %d" width="%d" height="%d">' . "\n",
            $size, $size, $size, $size
        );
    }

    private function generateBackground(int $size, string $color): string
    {
        return sprintf(
            '  <rect width="%d" height="%d" fill="%s"/>' . "\n",
            $size, $size, $this->escapeColor($color)
        );
    }

    private function generateModules(Matrix $matrix, int $margin, string $color, ModuleStyle $style): string
    {
        $size = $matrix->getSize();
        $elements = [];

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix->get($x, $y)) {
                    $elements[] = $this->generateModule(
                        $x + $margin,
                        $y + $margin,
                        $color,
                        $style,
                        $this->isFinderPattern($matrix, $x, $y)
                    );
                }
            }
        }

        return implode("\n", $elements) . "\n";
    }

    private function generateModule(int $x, int $y, string $color, ModuleStyle $style, bool $isFinder): string
    {
        $px = $x * $this->moduleSize;
        $py = $y * $this->moduleSize;
        $size = $this->moduleSize;

        // Finder patterns always use rounded corners for better visual
        if ($isFinder) {
            $radius = $size * 0.15;
            return sprintf(
                '  <rect x="%d" y="%d" width="%d" height="%d" fill="%s" rx="%.1f" ry="%.1f"/>',
                $px, $py, $size, $size, $this->escapeColor($color), $radius, $radius
            );
        }

        return match ($style) {
            ModuleStyle::Square => sprintf(
                '  <rect x="%d" y="%d" width="%d" height="%d" fill="%s"/>',
                $px, $py, $size, $size, $this->escapeColor($color)
            ),
            ModuleStyle::Rounded => sprintf(
                '  <rect x="%d" y="%d" width="%d" height="%d" fill="%s" rx="%.1f" ry="%.1f"/>',
                $px, $py, $size, $size, $this->escapeColor($color), $size * 0.3, $size * 0.3
            ),
            ModuleStyle::Dot => sprintf(
                '  <circle cx="%d" cy="%d" r="%.1f" fill="%s"/>',
                $px + $size / 2, $py + $size / 2, $size * 0.4, $this->escapeColor($color)
            ),
        };
    }

    private function isFinderPattern(Matrix $matrix, int $x, int $y): bool
    {
        $size = $matrix->getSize();
        $finderSize = 7;

        // Top-left finder pattern
        if ($x < $finderSize && $y < $finderSize) {
            return true;
        }

        // Top-right finder pattern
        if ($x >= $size - $finderSize && $y < $finderSize) {
            return true;
        }

        // Bottom-left finder pattern
        if ($x < $finderSize && $y >= $size - $finderSize) {
            return true;
        }

        return false;
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
