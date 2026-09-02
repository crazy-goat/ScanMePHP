<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataMatrix;

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
 * Data Matrix ECC200: a dense square (or rectangular) matrix symbology, the
 * usual choice for marking small parts and circuit boards.
 *
 * Unlike QR it offers no error correction level to choose — ECC200 fixes the
 * recovery data per symbol size — so its capabilities report no levels, and
 * what a caller can influence is the shape.
 */
final class DataMatrixGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::DataMatrix->value,
            title: 'Data Matrix',
            dimension: Dimension::Matrix,
            moduleShape: ModuleShape::Square,
            aliases: ['datamatrix', 'dm', 'ecc200'],
            dataDescription: 'any byte string, up to 1556 bytes of ASCII or 778 digit pairs',
            errorCorrectionLevels: [],
            providesText: false,
            optionsClass: DataMatrixOptions::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        if ($data === '') {
            return false;
        }

        $needed = \count(AsciiEncodation::encode($data));
        $options = $options instanceof DataMatrixOptions ? $options : new DataMatrixOptions();

        if ($options->size !== null) {
            $spec = Specs::byName($options->size);

            return $spec instanceof \CrazyGoat\ScanMePHP\Generator\DataMatrix\SymbolSpec && $spec->dataWords >= $needed;
        }

        return Specs::smallestFor($needed, $options->rectangular) instanceof \CrazyGoat\ScanMePHP\Generator\DataMatrix\SymbolSpec;
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
