<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Ean13;

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
 * EAN-13, the retail article number barcode.
 *
 * Accepts 12 digits and computes the check digit, or 13 and verifies it — a
 * wrong check digit is rejected rather than silently corrected, because a
 * caller passing 13 digits is asserting a specific article number and quietly
 * encoding a different one would be worse than failing.
 */
final class Ean13Generator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Ean13->value,
            title: 'EAN-13',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['ean', 'ean-13'],
            dataDescription: '12 digits, or 13 with a correct check digit',
            errorCorrectionLevels: [],
            providesText: true,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        if (preg_match('/^\d{12,13}$/', $data) !== 1) {
            return false;
        }

        return \strlen($data) === 12
            || (int) $data[12] === Backend\PhpBackend::checkDigit(substr($data, 0, 12));
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
