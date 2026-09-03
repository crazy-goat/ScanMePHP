<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Gs1DataMatrix\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\AsciiEncodation;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\DataMatrixOptions;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\SymbolBuilder;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * GS1 Data Matrix in pure PHP.
 *
 * Parses the element strings, turns them into codewords with FNC1 where the
 * application identifier table says a separator goes, and hands them to the
 * same builder plain Data Matrix uses. Nothing here knows about symbol sizes
 * or Reed–Solomon, which is the point.
 */
final class PhpBackend implements BackendInterface
{
    private readonly SymbolBuilder $builder;

    public function __construct(?SymbolBuilder $builder = null)
    {
        $this->builder = $builder ?? new SymbolBuilder();
    }

    public function getName(): string
    {
        return 'php';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function encode(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        $elements = ElementString::parse($data);
        $payload = $elements->payload();

        return $this->builder->build(
            AsciiEncodation::encodeGs1($payload),
            $options instanceof DataMatrixOptions ? $options : new DataMatrixOptions(),
            Symbology::Gs1DataMatrix->value,
            [
                'elements' => \count($elements->elements),
                // What a scanner hands back, FNC1 separators included.
                'payload' => $payload,
            ],
        );
    }
}
