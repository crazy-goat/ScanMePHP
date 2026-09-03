<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Aztec;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Encoding\Aztec\HighLevelEncoder;
use CrazyGoat\ScanMePHP\Encoding\Aztec\Specs;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Aztec Code: a matrix symbology with its finder in the middle and no quiet
 * zone, which is why it turns up on transport tickets and boarding passes
 * where there is no room to waste around the edge.
 *
 * Like Data Matrix it has no named error correction levels — the amount is a
 * percentage — so its capabilities report none and {@see AztecOptions} carries
 * the number instead.
 */
final class AztecGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Aztec->value,
            title: 'Aztec Code',
            dimension: Dimension::Matrix,
            moduleShape: ModuleShape::Square,
            aliases: ['aztec-code', 'azteccode'],
            dataDescription: 'any byte string, up to roughly 3000 characters of text or 1900 bytes of binary',
            errorCorrectionLevels: [],
            providesText: false,
            optionsClass: AztecOptions::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        if ($data === '') {
            return false;
        }

        $options = $options instanceof AztecOptions ? $options : new AztecOptions();
        $bits = \count((new HighLevelEncoder())->encode($data));

        if ($options->size !== null) {
            [$layers, $compact] = Specs::fromSize($options->size);
            $wordBits = Specs::wordBits($layers, $compact);

            // Stuffing can only add bits, so this is a lower bound on what the
            // data needs and an answer of true is not a promise. Running the
            // encoder to be sure would cost as much as encoding.
            return (int) ceil($bits / $wordBits) * $wordBits <= Specs::totalBits($layers, $compact);
        }

        return $bits <= Specs::totalBits(Specs::MAX_FULL_LAYERS, false);
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
