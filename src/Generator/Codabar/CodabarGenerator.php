<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Codabar;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Codabar, still in use where a symbology outlived its replacements: library
 * cards, blood bank labels, photo finishing envelopes.
 *
 * The payload here is the data alone. Most implementations make the caller
 * write the delimiters into it — 'A123456A' rather than '123456' — which puts a
 * detail of the symbology into the caller's data and makes canEncode() answer
 * no to every number a caller actually holds. They are options instead, and
 * default to A at both ends.
 *
 * A scanner does report them, so `$symbol->getMetadataValue('characters')` is
 * what a scan will read back and `getText()` is what belongs under the bars.
 */
final class CodabarGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Codabar->value,
            title: 'Codabar',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['coda-bar', 'nw-7', 'code-2-of-7'],
            dataDescription: 'digits and "-$:/.+", the delimiters being an option rather than data',
            errorCorrectionLevels: [],
            providesText: true,
            optionsClass: CodabarOptions::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        return Patterns::isEncodable($data);
    }

    public function generate(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        return $this->selector->require($this->getCapabilities()->title)->encode($data, $options);
    }

    public function getActiveBackend(): ?BackendInterface
    {
        return $this->selector->select();
    }

    public function getBackendSelector(): BackendSelector
    {
        return $this->selector;
    }
}
