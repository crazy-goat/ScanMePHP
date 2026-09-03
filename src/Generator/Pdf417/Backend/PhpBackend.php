<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Pdf417\Backend;

use CrazyGoat\ScanMePHP\Encoding\Pdf417\Pdf417Encoder;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Options;
use CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Symbols;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * PDF417 in pure PHP, which is the only way it is implemented.
 *
 * The C++ core and the extension stay QR-only on purpose: they exist because
 * QR is the symbology generated in bulk, and the profiling that justified them
 * says nothing about PDF417.
 */
final class PhpBackend implements BackendInterface
{
    private ?Pdf417Encoder $encoder = null;

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
        $this->encoder ??= new Pdf417Encoder();
        $options = $options instanceof Pdf417Options ? $options : new Pdf417Options();

        $result = $this->encoder->encode(
            $data,
            $options->errorCorrectionLevel,
            $options->columns,
            $options->rows,
        );

        return Pdf417Symbols::fromModules($result['matrix'], $options->rowHeight, [
            'rows' => $result['rows'],
            'columns' => $result['columns'],
            'errorCorrectionLevel' => $result['level'],
            'dataCodewords' => $result['dataCodewords'],
            'padCodewords' => $result['padCodewords'],
        ]);
    }
}
