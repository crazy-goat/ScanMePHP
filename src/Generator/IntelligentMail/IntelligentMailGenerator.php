<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\IntelligentMail;

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
 * The Intelligent Mail barcode, on the front of United States mail.
 *
 * Sixty-five four-state bars carrying a twenty digit tracking code and up to
 * eleven digits of delivery point — the symbology that replaced POSTNET and
 * PLANET, and the only one in this family that is not read a character at a
 * time. What it carries is spread across the whole symbol rather than laid out
 * in order; {@see Backend\PhpBackend} says why.
 *
 * No options. The bar height a caller wants is a render option, and it scales
 * the ascender, tracker and descender together because their ratio is what the
 * symbology means.
 */
final class IntelligentMailGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::IntelligentMail->value,
            title: 'Intelligent Mail',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['imb', 'usps-imb', 'onecode', 'usps4cb'],
            dataDescription: '20 digits of tracking code, then 0, 5, 9 or 11 digits of routing code',
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
