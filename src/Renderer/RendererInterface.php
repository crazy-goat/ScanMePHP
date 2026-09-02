<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Options\RenderOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * Turns a Symbol into bytes — SVG, PNG, HTML, ASCII, or anything a caller
 * registers itself.
 *
 * A renderer sees only the generic Symbol, never a symbology-specific type, so
 * a renderer written for QR works on EAN-13 and on a symbology added later
 * without changes. What it cannot draw it declares in getCapabilities()
 * instead of discovering at render time.
 */
interface RendererInterface
{
    /** Name this renderer is registered and requested under, e.g. 'svg'. */
    public function getFormat(): string;

    /** MIME type of the returned bytes. */
    public function getContentType(): string;

    public function getCapabilities(): RendererCapabilities;

    public function render(Symbol $symbol, ?RenderOptionsInterface $options = null): string;
}
