<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Kix;

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
 * KIX — the Klantindex, on the front of Dutch mail.
 *
 * RM4SCC's four-state bars with the envelope taken off: the same thirty-six
 * characters drawn the same way, and then nothing around them. No start bar,
 * no stop bar, no check character — see {@see Backend\PhpBackend} for what
 * that costs a reader.
 *
 * Carries a postcode, a house number and its additions, up to eighteen
 * characters. It has no options: the bar height a caller wants is a render
 * option, and it scales the ascender, tracker and descender together because
 * their ratio is what the symbology means.
 */
final class KixGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Kix->value,
            title: 'KIX',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['kix-code', 'klantindex', 'postnl'],
            dataDescription: '1 to 18 digits and capital letters, typically a postcode, house number and additions',
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
