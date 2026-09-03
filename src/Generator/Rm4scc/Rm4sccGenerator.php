<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Rm4scc;

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
 * RM4SCC — the Royal Mail 4-State Customer Code, on the front of the envelope.
 *
 * The first four-state symbology here: the data is in how far each bar reaches
 * above and below a central tracker band, not in how wide anything is. That is
 * a postal decision rather than an aesthetic one — the bars survive an
 * envelope going through a sorting machine at speed in a way a linear symbol's
 * narrow spaces do not.
 *
 * Carries a postcode and a delivery point suffix in practice, and digits and
 * capitals in general. It has no options: the bar height a caller wants is a
 * render option, and it scales the ascender, tracker and descender together
 * because their ratio is what the symbology means.
 */
final class Rm4sccGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Rm4scc->value,
            title: 'RM4SCC',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['royal-mail', 'royal-mail-4state', 'rm4scc-cbc'],
            dataDescription: '1 to 50 digits and capital letters, typically a postcode and delivery point suffix',
            errorCorrectionLevels: [],
            providesText: false,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        return Backend\PhpBackend::accepts($data);
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
