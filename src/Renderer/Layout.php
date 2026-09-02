<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Renderer\Options\AbstractRenderOptions;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * The symbol's geometry in module units, after options are applied.
 *
 * Every renderer needs the same handful of derived numbers — quiet zone,
 * per-row heights, totals, and where each module row starts — and getting any
 * of them subtly wrong produces a symbol that looks right and does not scan.
 * Computing them once here keeps that arithmetic in one place instead of
 * repeated across seven renderers.
 *
 * Everything is in modules, not pixels: scaling by module size is the
 * renderer's business and differs between an SVG path, a PNG scanline and a
 * terminal character cell.
 */
final class Layout
{
    /**
     * @param list<int> $rowHeights Height of each module row, in modules
     * @param list<int> $rowOffsets Module-space y of each row, quiet zone included
     */
    private function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly QuietZone $quietZone,
        public readonly array $rowHeights,
        public readonly array $rowOffsets,
        public readonly int $totalWidth,
        public readonly int $totalHeight,
        public readonly bool $uniformRows,
    ) {
    }

    public static function of(Symbol $symbol, AbstractRenderOptions $options): self
    {
        $quietZone = $options->resolveQuietZone($symbol);
        $rowHeights = $options->resolveRowHeights($symbol);

        $offsets = [];
        $y = $quietZone->top;
        foreach ($rowHeights as $rowHeight) {
            $offsets[] = $y;
            $y += $rowHeight;
        }

        return new self(
            width: $symbol->getWidth(),
            height: $symbol->getHeight(),
            quietZone: $quietZone,
            rowHeights: $rowHeights,
            rowOffsets: $offsets,
            totalWidth: $quietZone->left + $symbol->getWidth() + $quietZone->right,
            totalHeight: $y + $quietZone->bottom,
            uniformRows: array_sum($rowHeights) === \count($rowHeights),
        );
    }

    /** Module-space x of a symbol column, quiet zone included. */
    public function columnOffset(int $x): int
    {
        return $this->quietZone->left + $x;
    }
}
