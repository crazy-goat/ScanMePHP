<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\MaxiCode\Backend;

use CrazyGoat\ScanMePHP\Encoding\MaxiCode\MaxiCodeEncoder;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\MaxiCode\MaxiCodeOptions;
use CrazyGoat\ScanMePHP\Generator\MaxiCode\MaxiCodeSymbols;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * MaxiCode in pure PHP, which is the only way it is implemented.
 *
 * The C++ core and the extension stay QR-only on purpose: they exist because
 * QR is the symbology generated in bulk, and the profiling that justified them
 * says nothing about MaxiCode.
 */
final class PhpBackend implements BackendInterface
{
    private ?MaxiCodeEncoder $encoder = null;

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
        $this->encoder ??= new MaxiCodeEncoder();
        $options = $options instanceof MaxiCodeOptions ? $options : new MaxiCodeOptions();

        $result = $this->encoder->encode($data, $options->mode, $options->primaryMessage());

        return MaxiCodeSymbols::fromModules($result['matrix'], [
            'mode' => $result['mode'],
            'dataCodewords' => $result['dataCodewords'],
            'padCodewords' => $result['padCodewords'],
        ]);
    }
}
