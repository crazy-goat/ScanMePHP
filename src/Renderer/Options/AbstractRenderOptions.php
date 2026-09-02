<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer\Options;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Exception\InvalidConfigurationException;
use CrazyGoat\ScanMePHP\Options\RenderOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * The appearance knobs every renderer shares.
 *
 * Each renderer subclasses this to add what only it can do — zlib level for
 * PNG, module shape for SVG, glyphs for ASCII — so a caller passes one bag
 * that is specific to the format they chose and cannot silently carry options
 * that format ignores.
 */
abstract class AbstractRenderOptions implements RenderOptionsInterface
{
    /**
     * @param int $moduleSize Pixels (or cells) per module
     * @param int|null $quietZone Blank margin in modules, overriding the width
     *        the symbology asks for. Null keeps the symbology's own value,
     *        which is what keeps symbols scannable by default.
     * @param int|null $barHeight Height of a linear symbol's bars in modules,
     *        overriding the symbology's default. Ignored by matrix symbologies,
     *        whose rows are always one module tall.
     * @param string|null $label Caption drawn beneath the symbol. Distinct from
     *        a symbol's own human-readable interpretation, which the symbology
     *        supplies and the renderer must print if it can.
     */
    public function __construct(
        public readonly int $moduleSize = 10,
        public readonly ?int $quietZone = null,
        public readonly ?int $barHeight = null,
        public readonly string $foregroundColor = '#000000',
        public readonly string $backgroundColor = '#FFFFFF',
        public readonly bool $invert = false,
        public readonly ?string $label = null,
        public readonly bool $showText = true,
    ) {
        if ($this->moduleSize <= 0) {
            throw InvalidConfigurationException::invalidModuleSize($this->moduleSize);
        }

        if ($this->quietZone !== null && $this->quietZone < 0) {
            throw new \InvalidArgumentException('Quiet zone cannot be negative, got ' . $this->quietZone);
        }

        if ($this->barHeight !== null && $this->barHeight < 1) {
            throw new \InvalidArgumentException('Bar height must be at least 1 module, got ' . $this->barHeight);
        }
    }

    /**
     * The quiet zone to actually draw.
     *
     * Defaults to what the symbology requires — 4 modules for QR, 11 left and
     * 7 right for EAN-13 — because those widths are part of being scannable,
     * not a matter of taste. An explicit value wins, including a smaller or
     * zero one: a caller rendering a preview into a tight layout is entitled to
     * that, and it is their call to make, not this library's.
     */
    final public function resolveQuietZone(Symbol $symbol): QuietZone
    {
        return $this->quietZone === null
            ? $symbol->getQuietZone()
            : QuietZone::uniform($this->quietZone);
    }

    /**
     * Height of each module row, in modules, after any override.
     *
     * @return list<int>
     */
    final public function resolveRowHeights(Symbol $symbol): array
    {
        $rowHeights = $symbol->getRowHeights();

        // Matrix symbologies encode in both axes, so a row is never anything
        // but one module tall and an override would corrupt the symbol.
        if ($symbol->getDimension() === Dimension::Matrix || $this->barHeight === null) {
            return $rowHeights;
        }

        // Scale the symbology's proportions to the requested total rather than
        // flattening them: a four-state postal code's meaning is the ratio
        // between ascender, tracker and descender, so an override must stretch
        // all three, not overwrite them with one height.
        $total = array_sum($rowHeights);
        $scaled = [];
        foreach ($rowHeights as $rowHeight) {
            $scaled[] = max(1, (int) round($rowHeight * $this->barHeight / $total));
        }

        return $scaled;
    }

    /** The human-readable interpretation to print, if any and if wanted. */
    final public function resolveText(Symbol $symbol): ?string
    {
        return $this->showText ? $symbol->getText() : null;
    }

    final public function getEffectiveForegroundColor(): string
    {
        return $this->invert ? $this->backgroundColor : $this->foregroundColor;
    }

    final public function getEffectiveBackgroundColor(): string
    {
        return $this->invert ? $this->foregroundColor : $this->backgroundColor;
    }
}
