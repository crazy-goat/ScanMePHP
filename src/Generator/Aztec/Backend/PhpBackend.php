<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Aztec\Backend;

use CrazyGoat\ScanMePHP\Encoding\Aztec\AztecEncoder;
use CrazyGoat\ScanMePHP\Generator\Aztec\AztecOptions;
use CrazyGoat\ScanMePHP\Generator\Aztec\AztecSymbols;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * Aztec in pure PHP, which is the only way it is implemented.
 *
 * The C++ core and the extension stay QR-only on purpose: they exist because
 * QR is the symbology generated in bulk, and the profiling that justified them
 * says nothing about Aztec.
 */
final class PhpBackend implements BackendInterface
{
    private ?AztecEncoder $encoder = null;

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
        $this->encoder ??= new AztecEncoder();
        $options = $options instanceof AztecOptions ? $options : new AztecOptions();

        $result = $this->encoder->encode($data, $options->errorCorrectionPercent, $options->size);

        return AztecSymbols::fromModules(
            $result['matrix'],
            $result['layers'],
            $result['compact'],
            [
                'dataWords' => $result['dataWords'],
                'totalWords' => $result['totalWords'],
            ],
        );
    }
}
