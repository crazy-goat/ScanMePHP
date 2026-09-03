<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBarExpandedStacked;

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
 * GS1 DataBar Expanded Stacked — the same data folded to fit the label.
 *
 * Expanded grows sideways with its data, and at twenty-two characters it is
 * 543 modules wide. A shelf-edge label is not. This is the same symbology cut
 * into rows: two character pairs per row by default, up to eleven, with three
 * module rows of separator between them.
 *
 * The payload is identical to `databar-expanded` and reads back the same. What
 * differs is the shape and, occasionally, one character: a row may not be left
 * holding a single character, so some payloads take one more character of
 * padding here than they would in a line.
 */
final class DataBarExpandedStackedGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::DataBarExpandedStacked->value,
            title: 'GS1 DataBar Expanded Stacked',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['gs1-databar-expanded-stacked', 'rss-expanded-stacked', 'rss-exp-stack'],
            dataDescription: 'GS1 element strings in parenthesised form, up to 22 symbol characters',
            errorCorrectionLevels: [],
            providesText: true,
            optionsClass: DataBarExpandedStackedOptions::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        return Backend\PhpBackend::accepts(
            $data,
            $options instanceof DataBarExpandedStackedOptions ? $options : null
        );
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
