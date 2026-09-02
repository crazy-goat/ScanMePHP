<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests\Support;

use CrazyGoat\ScanMePHP\Options\RenderOptionsInterface;
use CrazyGoat\ScanMePHP\Renderer\RendererCapabilities;
use CrazyGoat\ScanMePHP\Renderer\RendererInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * A renderer with the default capabilities and nothing else.
 *
 * It stands in for one written outside this library, against defaults rather
 * than against what the built-in renderers happen to declare — which is the
 * only way to test that a capability defaults the safe way round.
 */
class NullRenderer implements RendererInterface
{
    public function getFormat(): string
    {
        return 'null';
    }

    public function getContentType(): string
    {
        return 'application/octet-stream';
    }

    public function getCapabilities(): RendererCapabilities
    {
        return new RendererCapabilities();
    }

    public function render(Symbol $symbol, ?RenderOptionsInterface $options = null): string
    {
        return '';
    }
}
