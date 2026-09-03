<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Region;
use CrazyGoat\ScanMePHP\RegionRole;

/**
 * What a renderer can actually draw.
 *
 * Renderers are swappable, including ones written outside this library, so the
 * facade cannot assume every renderer copes with every symbol. A renderer that
 * paints character cells has no way to draw MaxiCode's hexagons; one that draws
 * only what the module grid holds cannot supply a bullseye, which is not made
 * of modules at all; one with no font engine — the pure-PHP PNG writer — cannot
 * print the human-readable digits an EAN symbol supplies. Declaring the limits
 * here lets Compatibility report the mismatch by name instead of quietly
 * emitting a symbol that is wrong, unscannable, or missing its text.
 *
 * A declaration says what a renderer *does*, not what its format could be made
 * to do. The HTML renderers report no hexagons because they draw a grid of
 * cells, not because CSS could not clip one.
 */
final class RendererCapabilities
{
    /**
     * @param list<ModuleShape> $moduleShapes Geometries this renderer can draw
     * @param bool $text Can print a symbol's human-readable interpretation
     * @param bool $color Honours foreground/background colours
     * @param bool $nonUniformRows Can draw rows of differing heights, as the
     *        four-state postal symbologies require
     * @param bool $positionedText Can place a line of text over part of the
     *        symbol rather than centred under all of it, which an EAN-13 with
     *        an add-on needs: the main digits go under the main bars and the
     *        add-on's over its own. Defaults to false, so a renderer written
     *        before this existed is reported as unable rather than assumed
     *        able — the failure of the assumption would be a label with the
     *        price printed under the wrong half of it.
     * @param bool $drawnRegions Can draw a finder region the module grid does
     *        not hold — MaxiCode's bullseye is three concentric rings, and a
     *        renderer that paints only what the grid says would leave a hole
     *        where the finder belongs. Defaults to false, so a renderer written
     *        before this existed is reported as unable rather than assumed
     *        able; the failure of that assumption is an unscannable symbol.
     * @param string|null $textCharacters When this renderer can print text but
     *        only from a fixed repertoire, the characters it has. Null means
     *        any text, which is the case for every renderer that delegates
     *        typography to a browser or a terminal.
     * @param class-string|null $optionsClass The RenderOptionsInterface
     *        implementation this renderer reads. Passing a different bag is an
     *        error rather than a silent partial application, since the options
     *        that do not fit are exactly the ones the caller cared about.
     */
    public function __construct(
        public readonly array $moduleShapes = [ModuleShape::Square],
        public readonly bool $text = true,
        public readonly bool $color = true,
        public readonly bool $nonUniformRows = true,
        public readonly bool $positionedText = false,
        public readonly bool $drawnRegions = false,
        public readonly ?string $textCharacters = null,
        public readonly ?string $optionsClass = null,
    ) {
        if ($this->moduleShapes === []) {
            throw new \InvalidArgumentException('A renderer must support at least one module shape');
        }

        if (!$this->text && $this->textCharacters !== null) {
            throw new \InvalidArgumentException(
                'A renderer that cannot print text must not declare a character repertoire'
            );
        }

        if (!$this->text && $this->positionedText) {
            throw new \InvalidArgumentException(
                'A renderer that cannot print text cannot place it either'
            );
        }
    }

    /**
     * The characters of $text this renderer cannot print, each reported once.
     *
     * @return list<string>
     */
    public function unprintableCharacters(string $text): array
    {
        if ($this->textCharacters === null) {
            return [];
        }

        $unprintable = [];
        for ($i = 0, $length = \strlen($text); $i < $length; $i++) {
            $character = $text[$i];
            if (!str_contains($this->textCharacters, $character)
                && !\in_array($character, $unprintable, true)
            ) {
                $unprintable[] = $character;
            }
        }

        return $unprintable;
    }

    public function supportsShape(ModuleShape $shape): bool
    {
        return \in_array($shape, $this->moduleShapes, true);
    }

    /**
     * Whether this renderer can draw every region of $regions.
     *
     * @param list<Region> $regions
     */
    public function supportsRegions(array $regions): bool
    {
        if ($this->drawnRegions) {
            return true;
        }

        foreach ($regions as $region) {
            if ($region->role === RegionRole::RendererDrawn) {
                return false;
            }
        }

        return true;
    }
}
