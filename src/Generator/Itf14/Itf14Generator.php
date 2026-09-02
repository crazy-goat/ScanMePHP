<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Itf14;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\Ean\Patterns as CheckDigit;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * ITF-14, the GTIN-14 barcode printed on shipping cases.
 *
 * Registered separately from ITF rather than being a fourteen-digit ITF,
 * because three things a caller must not have to remember are fixed here: the
 * digit count, the mandatory check digit, and the bearer bar. Ask for an
 * ITF-14 and you get a symbol GS1 would accept; ask for an ITF of fourteen
 * digits and you get bars in a quiet zone, which is a different product.
 *
 * Accepts 13 digits and computes the check digit, or 14 and verifies it.
 */
final class Itf14Generator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Itf14->value,
            title: 'ITF-14',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['itf-14', 'gtin-14'],
            dataDescription: '13 digits, or 14 with a correct check digit',
            errorCorrectionLevels: [],
            providesText: true,
            optionsClass: Itf14Options::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        return CheckDigit::accepts($data, Backend\PhpBackend::PAYLOAD);
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
