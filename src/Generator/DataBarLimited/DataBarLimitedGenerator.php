<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBarLimited;

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
 * GS1 DataBar Limited — the same GTIN, narrower, on one condition.
 *
 * 74 modules instead of Omnidirectional's 96, for small items where the extra
 * quarter of width is the difference between fitting and not. The condition is
 * the indicator digit: Limited encodes GTIN-14s beginning 0 or 1 and no others,
 * which covers a retail item and its inner pack and stops there.
 *
 * It also gives up omnidirectional scanning, so it belongs where a scanner is
 * passed across the label rather than where goods are swept past a window.
 *
 * Accepts 13 digits and computes the check digit, or 14 and verifies it, with
 * or without a leading '(01)'.
 */
final class DataBarLimitedGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::DataBarLimited->value,
            title: 'GS1 DataBar Limited',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['gs1-databar-limited', 'rss-limited', 'rss-ltd'],
            dataDescription: '13 digits starting 0 or 1, or 14 with a correct check digit, optionally prefixed (01)',
            errorCorrectionLevels: [],
            providesText: true,
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
