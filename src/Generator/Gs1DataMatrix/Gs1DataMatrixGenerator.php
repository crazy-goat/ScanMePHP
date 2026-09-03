<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Gs1DataMatrix;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\AsciiEncodation;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\DataMatrixOptions;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\Specs;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\SymbolSpec;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * GS1 Data Matrix: an ECC200 symbol carrying GS1 application identifiers.
 *
 * The same element strings as GS1-128 and the same table behind them; what
 * changes is only how FNC1 is spelled. In Code 128 it is a symbol character in
 * either character set. Here it is codeword 232 — one in front, which is what
 * makes a reader announce ']d2', and one wherever a variable-length element
 * string ends. Everything after encodation is shared with plain Data Matrix,
 * so size selection, block interleaving and the finder frame cannot drift.
 *
 * It is registered separately for the reason GS1-128 is: canEncode() answers a
 * different question. Data Matrix takes any byte string, so it would encode
 * '(01)09501101020917' as literal parentheses — a symbol that scans, carrying
 * data no GS1 system expects.
 *
 * The rectangular sizes are available here as they are for plain Data Matrix,
 * though GS1 specifies the square ones for most applications.
 */
final class Gs1DataMatrixGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Gs1DataMatrix->value,
            title: 'GS1 Data Matrix',
            dimension: Dimension::Matrix,
            moduleShape: ModuleShape::Square,
            aliases: ['gs1-datamatrix', 'gs1dm'],
            dataDescription: 'GS1 element strings, as (AI)data — e.g. (01)09501101020917(10)LOT0001',
            errorCorrectionLevels: [],
            providesText: false,
            optionsClass: DataMatrixOptions::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        if (!ElementString::isParsable($data)) {
            return false;
        }

        $needed = \count(AsciiEncodation::encodeGs1(ElementString::parse($data)->payload()));
        $options = $options instanceof DataMatrixOptions ? $options : new DataMatrixOptions();

        if ($options->size !== null) {
            $spec = Specs::byName($options->size);

            return $spec instanceof SymbolSpec && $spec->dataWords >= $needed;
        }

        return Specs::smallestFor($needed, $options->rectangular) instanceof SymbolSpec;
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
