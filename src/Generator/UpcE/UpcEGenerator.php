<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\UpcE;

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
 * UPC-E, the compressed UPC-A for small packages.
 *
 * Accepts either form: the UPC-E itself (number system plus six digits, with
 * or without the check digit) or the UPC-A it stands for, which is compressed.
 * Only some UPC-A numbers have a UPC-E form at all, so canEncode() is doing
 * real work here rather than checking a length.
 */
final class UpcEGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::UpcE->value,
            title: 'UPC-E',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['upce'],
            dataDescription: '7 or 8 UPC-E digits, or a UPC-A that compresses to one',
            errorCorrectionLevels: [],
            providesText: true,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        if (preg_match('/^\d{7,8}$|^\d{11,12}$/', $data) !== 1) {
            return false;
        }

        try {
            Backend\PhpBackend::normalise($data);

            return true;
        } catch (\InvalidArgumentException) {
            // Whether a number has a UPC-E form is the zero-suppression rules
            // and the check digit, not the length; the cheapest honest test is
            // to try. Nothing here is expensive enough to warrant a second
            // implementation of the same rules for probing.
            return false;
        }
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
