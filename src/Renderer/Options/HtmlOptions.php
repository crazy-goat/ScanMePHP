<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer\Options;

final class HtmlOptions extends AbstractRenderOptions
{
    /**
     * @param bool $fullDocument Wrap the symbol in a complete HTML document
     *        instead of returning the bare element for embedding.
     * @param string $title <title> of that document; ignored when embedding.
     */
    public function __construct(
        int $moduleSize = 10,
        ?int $quietZone = null,
        ?int $barHeight = null,
        string $foregroundColor = '#000000',
        string $backgroundColor = '#FFFFFF',
        bool $invert = false,
        ?string $label = null,
        public readonly bool $fullDocument = false,
        public readonly string $title = 'Barcode',
    ) {
        parent::__construct(
            $moduleSize,
            $quietZone,
            $barHeight,
            $foregroundColor,
            $backgroundColor,
            $invert,
            $label,
        );
    }
}
