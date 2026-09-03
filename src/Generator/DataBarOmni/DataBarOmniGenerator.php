<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBarOmni;

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
 * GS1 DataBar Omnidirectional — a GTIN in a quarter of an EAN-13's width.
 *
 * This is the symbol GS1 defined for the things an EAN-13 is too wide for:
 * loose produce, small pharmacy packs, coupons. It carries a GTIN-14 and only
 * a GTIN-14 — the application identifier 01 is not in the bars, it is what the
 * symbology *means*, which is why a scanner reports '(01)' in front of digits
 * that were never encoded.
 *
 * Accepts 13 digits and computes the check digit, or 14 and verifies it, with
 * or without a leading '(01)'.
 */
final class DataBarOmniGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::DataBarOmni->value,
            title: 'GS1 DataBar Omnidirectional',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['databar', 'gs1-databar', 'databar-omnidirectional', 'rss14', 'rss-14'],
            dataDescription: '13 digits, or 14 with a correct check digit, optionally prefixed (01)',
            errorCorrectionLevels: [],
            providesText: true,
            optionsClass: DataBarOmniOptions::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        if (str_starts_with($data, '(01)')) {
            $data = substr($data, 4);
        }

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
