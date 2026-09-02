<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Itf;

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
 * Interleaved 2 of 5, for pure-digit payloads where density matters.
 *
 * The digit count must be even, and an odd one is refused rather than padded.
 * Padding is what most encoders do and it is a data change: a caller who asks
 * for '123' and gets a symbol reading '0123' has been handed a different
 * number, and this library refuses that for the same reason it refuses to
 * correct a wrong EAN check digit. Prepend the zero yourself, or turn on the
 * check digit — which makes an odd payload the encodable one.
 */
final class ItfGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Itf->value,
            title: 'ITF',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['interleaved-2-of-5', 'i25'],
            dataDescription: 'an even number of digits, or an odd number with the check digit option',
            errorCorrectionLevels: [],
            providesText: true,
            optionsClass: ItfOptions::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        if (preg_match('/^\d+$/', $data) !== 1) {
            return false;
        }

        // The check digit changes which parity encodes, so canEncode() has to
        // read the options to answer at all.
        $withCheck = $options instanceof ItfOptions && $options->checkDigit;

        return \strlen($data) % 2 === ($withCheck ? 1 : 0);
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
