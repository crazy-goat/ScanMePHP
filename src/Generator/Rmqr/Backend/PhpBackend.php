<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Rmqr\Backend;

use CrazyGoat\ScanMePHP\Encoding\Mode;
use CrazyGoat\ScanMePHP\Encoding\Rmqr\RmqrEncoder;
use CrazyGoat\ScanMePHP\Encoding\Rmqr\Specs;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Rmqr\RmqrOptions;
use CrazyGoat\ScanMePHP\Generator\Rmqr\RmqrSymbols;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * rMQR in pure PHP, which is the only way it is implemented.
 *
 * The C++ core and the extension stay QR-only, as they do for Micro QR. They
 * exist because QR is the symbology generated in bulk, and nobody generates a
 * hundred and fifty bytes on the side of a cable a million times an hour.
 */
final class PhpBackend implements BackendInterface
{
    private ?RmqrEncoder $encoder = null;

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
        $this->encoder ??= new RmqrEncoder();
        $options = $options instanceof RmqrOptions ? $options : new RmqrOptions();

        $result = $this->encoder->encode($data, $options->version?->value, $options->errorCorrection);

        return RmqrSymbols::fromModules($result['matrix'], [
            'version' => sprintf(
                'R%dx%d',
                Specs::height($result['index']),
                Specs::width($result['index']),
            ),
            'errorCorrection' => $result['level']->name,
            // A list, because rMQR symbols routinely use more than one mode:
            // splitting a payload where the digits start is often a bit or two
            // cheaper, and a bit or two is sometimes a whole size.
            'modes' => array_map(
                static fn (Mode $mode): string => $mode->name,
                $result['modes'],
            ),
        ]);
    }
}
