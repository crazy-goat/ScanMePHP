<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\ModuleShape;

/**
 * What a symbology generator produces and accepts.
 *
 * The registry publishes these so a caller — or a UI, or a test — can ask what
 * is installed and what each entry needs, without instantiating anything or
 * hardcoding a list. The renderer-facing half (dimension, module shape,
 * whether human-readable text comes with the symbol) is what Compatibility
 * matches against RendererCapabilities.
 */
final class GeneratorCapabilities
{
    /**
     * @param string $name Canonical registration name, e.g. 'qrcode'
     * @param list<string> $aliases Additional names resolving to this generator
     * @param string $title Human-readable symbology name, e.g. 'QR Code'
     * @param string $dataDescription What this symbology accepts, for error
     *        messages and introspection — canEncode() is the real check
     * @param list<string> $errorCorrectionLevels Level names this symbology
     *        offers; empty for symbologies without error correction
     * @param bool $providesText Whether generated symbols carry a
     *        human-readable interpretation a renderer is expected to print
     * @param class-string|null $optionsClass The GeneratorOptionsInterface
     *        implementation this generator accepts, if it takes options
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly Dimension $dimension,
        public readonly ModuleShape $moduleShape = ModuleShape::Square,
        public readonly array $aliases = [],
        public readonly string $dataDescription = '',
        public readonly array $errorCorrectionLevels = [],
        public readonly bool $providesText = false,
        public readonly ?string $optionsClass = null,
    ) {
        if ($this->name === '') {
            throw new \InvalidArgumentException('A generator must declare a non-empty name');
        }
    }

    /** Canonical name plus every alias. @return list<string> */
    public function allNames(): array
    {
        return [$this->name, ...$this->aliases];
    }

    public function hasErrorCorrection(): bool
    {
        return $this->errorCorrectionLevels !== [];
    }
}
