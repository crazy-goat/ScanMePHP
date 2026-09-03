<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBarExpanded;

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
 * GS1 DataBar Expanded — the one in the family that is not just a GTIN.
 *
 * Omnidirectional and Limited encode a number. Expanded encodes GS1 element
 * strings: a batch number, a use-by date, a net weight, a price, any of them
 * alongside the item's GTIN, in up to 74 numeric or 41 alphanumeric characters.
 * The symbol grows with the data, four to twenty-two characters, and stays
 * omnidirectional the whole way.
 *
 * The payload is written the way GS1 writes it — '(01)09501101020917(10)LOT0001'
 * — and the separators a scanner needs between variable-length elements are
 * placed from the identifier table, not by the caller.
 *
 * Not implemented: the compaction methods for variable-measure trade items,
 * where the standard pairs AI 01 with a weight, price or date field and saves a
 * character or two. Those payloads encode through the general AI 01 method
 * instead, which is a wider symbol saying exactly the same thing.
 */
final class DataBarExpandedGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::DataBarExpanded->value,
            title: 'GS1 DataBar Expanded',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['gs1-databar-expanded', 'rss-expanded', 'rss-exp'],
            dataDescription: 'GS1 element strings in parenthesised form, up to 22 symbol characters',
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
