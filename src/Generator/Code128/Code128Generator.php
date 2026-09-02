<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code128;

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
 * Code 128: printable ASCII in a linear symbol, with automatic switching
 * between the character sets so digit runs cost half the width.
 *
 * It takes no generator options. There is nothing to choose: the check
 * character is mandatory, the character set switching is a width optimisation
 * with no visible trade-off, and bar height and quiet zone are the renderer's.
 * GS1-128 — the same symbology plus FNC1 and application identifier parsing —
 * is a data-layer concern and would arrive as its own generator.
 */
final class Code128Generator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Code128->value,
            title: 'Code 128',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['code-128', 'c128'],
            dataDescription: 'printable ASCII (byte values 32 to 126)',
            errorCorrectionLevels: [],
            providesText: true,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        if ($data === '') {
            return false;
        }

        // Character set A would be needed for control characters, and this
        // implementation does not ship it; see CodeSet.
        for ($i = 0, $length = \strlen($data); $i < $length; $i++) {
            $byte = \ord($data[$i]);
            if ($byte < 32 || $byte > 126) {
                return false;
            }
        }

        return true;
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
