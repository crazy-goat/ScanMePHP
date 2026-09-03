<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\MaxiCode;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Encoding\MaxiCode\HighLevelEncoder;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * MaxiCode: hexagons around a bullseye, one fixed size, made for parcels.
 *
 * It is the odd one out of everything here. The modules are hexagons on offset
 * rows rather than squares on a grid, the finder is three concentric rings in
 * the middle rather than corner patterns, and the symbol is always the same
 * size — so there is no version, no layer count and no error correction level
 * to choose. What a caller chooses instead is the mode, which decides whether
 * the codewords nearest the bullseye carry a parcel's destination or more
 * payload; see {@see MaxiCodeOptions}.
 *
 * Because of the hexagons this is the one symbology whose module shape is not
 * Square, and therefore the one that renderers can refuse: the ASCII and HTML
 * renderers draw character and table cells and there is no honest way for them
 * to approximate a hexagonal lattice, so they say so instead of producing
 * something that looks like a symbol and does not scan.
 */
final class MaxiCodeGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::MaxiCode->value,
            title: 'MaxiCode',
            dimension: Dimension::Matrix,
            moduleShape: ModuleShape::Hexagon,
            aliases: ['maxi-code', 'ups-code'],
            dataDescription: 'any byte string, up to 93 codewords — about 93 characters of upper case '
                . 'text or 138 digits, and 84 codewords in the two structured modes',
            errorCorrectionLevels: [],
            providesText: false,
            optionsClass: MaxiCodeOptions::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        if ($data === '') {
            return false;
        }

        $options = $options instanceof MaxiCodeOptions ? $options : new MaxiCodeOptions();
        $codewords = (new HighLevelEncoder())->encode($data)['codewords'];

        return \count($codewords) <= $options->mode->capacity();
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
