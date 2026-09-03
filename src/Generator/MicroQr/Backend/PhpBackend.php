<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\MicroQr\Backend;

use CrazyGoat\ScanMePHP\Encoding\MicroQr\MicroQrEncoder;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\MicroQr\MicroQrOptions;
use CrazyGoat\ScanMePHP\Generator\MicroQr\MicroQrSymbols;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * Micro QR in pure PHP, which is the only way it is implemented.
 *
 * The C++ core and the extension stay QR-only. They exist because QR is the
 * symbology generated in bulk, and a symbol with at most thirty-five digits in
 * it is not what anyone generates in bulk.
 */
final class PhpBackend implements BackendInterface
{
    private ?MicroQrEncoder $encoder = null;

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
        $this->encoder ??= new MicroQrEncoder();
        $options = $options instanceof MicroQrOptions ? $options : new MicroQrOptions();

        $result = $this->encoder->encode(
            $data,
            $options->version?->value,
            $options->errorCorrection,
            $options->mask,
        );

        return MicroQrSymbols::fromModules($result['matrix'], [
            'version' => 'M' . $result['version'],
            // Null where the symbol is an M1, which has no level to report
            // rather than a level of zero.
            'errorCorrection' => $result['level']?->name,
            'mask' => $result['mask'],
            // A list, because Micro QR symbols routinely use more than one
            // mode: splitting a payload where the digits start is often a bit
            // or two cheaper, and a bit or two is sometimes a whole version.
            'modes' => array_map(
                static fn (\CrazyGoat\ScanMePHP\Encoding\Mode $mode): string => $mode->name,
                $result['modes'],
            ),
        ]);
    }
}
