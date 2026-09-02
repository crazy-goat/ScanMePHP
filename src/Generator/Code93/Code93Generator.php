<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code93;

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
 * Code 93, designed as a denser and better-checked replacement for Code 39.
 *
 * It takes no generator options, and the two things a caller might expect to
 * choose are exactly the two Code 39 makes optional. The check characters are
 * mandatory here, so there is nothing to opt into. And full ASCII is part of
 * the symbology rather than a second reading of it: the shift characters have
 * bars of their own instead of borrowing a data character's, so 'A$B' has one
 * reading where in Code 39 it has two. That is why this is one registry entry
 * where Code 39 is two.
 *
 * There is also no wide-to-narrow ratio: every character is nine modules of
 * three bars and three spaces, which is where the density comes from.
 */
final class Code93Generator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Code93->value,
            title: 'Code 93',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['code-93', 'c93'],
            dataDescription: 'any ASCII (byte values 0 to 127)',
            errorCorrectionLevels: [],
            providesText: true,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        return $data !== '' && Charset::isEncodable($data);
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
