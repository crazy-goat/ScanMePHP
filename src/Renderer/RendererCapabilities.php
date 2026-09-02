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
}
