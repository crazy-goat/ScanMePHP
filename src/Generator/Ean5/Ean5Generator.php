<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Ean5;

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
 * EAN-5, the 5-digit add-on: a book's list price, in the currency its first digit names.
 *
 * Accepts exactly 5 digits. There is no check digit to supply, so
 * unlike the rest of the family there is nothing here to verify against — the
 * digits are the payload and the parity is derived from them.
 */
final class Ean5Generator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Ean5->value,
            title: 'EAN-5',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['ean-5'],
            dataDescription: 'exactly 5 digits, no check digit',
            errorCorrectionLevels: [],
            providesText: true,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        return preg_match('/^\d{' . Backend\PhpBackend::DIGITS . '}$/', $data) === 1;
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
