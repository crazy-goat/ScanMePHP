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
        if ($wantsText && $symbol->getText() !== null && !$capabilities->text) {
            $reasons[] = 'the symbology supplies a human-readable interpretation that this renderer '
                . 'cannot print (pass showText: false to render without it)';
        }

        if (!$symbol->hasUniformRows() && !$capabilities->nonUniformRows) {
            $reasons[] = 'the symbology needs rows of differing heights, which this renderer draws uniformly';
        }

        return $reasons;
    }

    public static function isCompatible(
        Symbol $symbol,
        RendererInterface $renderer,
        ?RenderOptionsInterface $options = null
    ): bool {
        return self::check($symbol, $renderer, $options) === [];
    }
}
