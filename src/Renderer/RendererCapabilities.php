<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\ModuleShape;

/**
 * What a renderer can actually draw.
 *
 * Renderers are swappable, including ones written outside this library, so the
 * facade cannot assume every renderer copes with every symbol. A renderer that
 * paints character cells has no way to draw MaxiCode's hexagons; one with no
 * font engine — the pure-PHP PNG writer — cannot print the human-readable
 * digits an EAN symbol supplies. Declaring the limits here lets Compatibility
 * report the mismatch by name instead of quietly emitting a symbol that is
 * wrong, unscannable, or missing its text.
 */
final class RendererCapabilities
{
    /**
     * @param list<ModuleShape> $moduleShapes Geometries this renderer can draw
     * @param bool $text Can print a symbol's human-readable interpretation
     * @param bool $color Honours foreground/background colours
     * @param bool $nonUniformRows Can draw rows of differing heights, as the
     *        four-state postal symbologies require
     */
    public function __construct(
        public readonly array $moduleShapes = [ModuleShape::Square],
        public readonly bool $text = true,
        public readonly bool $color = true,
        public readonly bool $nonUniformRows = true,
    ) {
        if ($this->moduleShapes === []) {
            throw new \InvalidArgumentException('A renderer must support at least one module shape');
        }
    }

    public function supportsShape(ModuleShape $shape): bool
    {
        return \in_array($shape, $this->moduleShapes, true);
    }
}
