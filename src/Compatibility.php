<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

use CrazyGoat\ScanMePHP\Options\RenderOptionsInterface;
use CrazyGoat\ScanMePHP\Renderer\Options\AbstractRenderOptions;
use CrazyGoat\ScanMePHP\Renderer\RendererInterface;

/**
 * Matches what a symbol needs against what a renderer can draw.
 *
 * Kept separate from both sides so a caller can ask the question before
 * committing — building a format picker that greys out the impossible
 * combinations, say — and so the facade's refusal and a caller's own check use
 * exactly the same rules.
 */
final class Compatibility
{
    /**
     * Reasons this renderer cannot faithfully draw this symbol.
     *
     * The options matter: a caller who has explicitly turned the
     * human-readable text off is not asking for something the renderer cannot
     * deliver, so a fontless renderer stops being incompatible.
     *
     * @return list<string> Empty when the pair is compatible
     */
    public static function check(
        Symbol $symbol,
        RendererInterface $renderer,
        ?RenderOptionsInterface $options = null
    ): array {
        $capabilities = $renderer->getCapabilities();
        $reasons = [];

        $shape = $symbol->getModuleShape();
        if (!$capabilities->supportsShape($shape)) {
            $reasons[] = sprintf(
                'it cannot draw %s modules (it draws: %s)',
                $shape->value,
                implode(', ', array_map(
                    static fn (ModuleShape $supported): string => $supported->value,
                    $capabilities->moduleShapes
                ))
            );
        }

        $wantsText = !$options instanceof AbstractRenderOptions || $options->showText;
        $regions = $symbol->getTextRegions();
        if ($wantsText && $regions !== []) {
            if (!$capabilities->text) {
                $reasons[] = 'the symbology supplies a human-readable interpretation that this renderer '
                    . 'cannot print (pass showText: false to render without it)';
            } else {
                // Every line, not just the first: a composite's add-on digits
                // are as much part of the label as the article number, and a
                // renderer that cannot draw one of them cannot draw the symbol.
                $unprintable = [];
                foreach ($regions as $region) {
                    $unprintable = [...$unprintable, ...$capabilities->unprintableCharacters($region->text)];
                }

                $unprintable = array_values(array_unique($unprintable));
                if ($unprintable !== []) {
                    $reasons[] = sprintf(
                        'its font has no glyph for %s in the human-readable interpretation '
                        . '(pass showText: false to render without it)',
                        implode(' ', array_map(
                            static fn (string $character): string
                                => sprintf('%s (0x%02X)', $character, \ord($character)),
                            $unprintable
                        ))
                    );
                }

                if (!$capabilities->positionedText && self::needsPositioning($symbol, $regions)) {
                    $reasons[] = 'the symbol places its human-readable text over particular columns — an '
                        . 'add-on prints its digits above its own bars — and this renderer only centres '
                        . 'one line under the whole symbol (pass showText: false to render without it)';
                }
            }
        }

        if (!$symbol->hasUniformRows() && !$capabilities->nonUniformRows) {
            $reasons[] = 'the symbology needs rows of differing heights, which this renderer draws uniformly';
        }

        return $reasons;
    }

    /**
     * Whether the text is anything other than one line centred underneath,
     * which is all a renderer without positioning can draw.
     *
     * @param list<TextRegion> $regions
     */
    private static function needsPositioning(Symbol $symbol, array $regions): bool
    {
        if (\count($regions) !== 1) {
            return true;
        }

        return $regions[0]->placement !== TextPlacement::Below
            || $regions[0]->x !== 0
            || $regions[0]->width !== $symbol->getWidth();
    }

    public static function isCompatible(
        Symbol $symbol,
        RendererInterface $renderer,
        ?RenderOptionsInterface $options = null
    ): bool {
        return self::check($symbol, $renderer, $options) === [];
    }
}
