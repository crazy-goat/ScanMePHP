<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Qr\Backend;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Generator\Qr\QrBackendInterface;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\Generator\Qr\QrSymbols;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * The scanmeqr PHP extension: the C++ core compiled into the process.
 */
final class NativeBackend implements QrBackendInterface
{
    public function getName(): string
    {
        return 'native';
    }

    public function isAvailable(): bool
    {
        return \extension_loaded('scanmeqr') && class_exists('NativeEncoderCore');
    }

    public function getPriority(): int
    {
        return 400;
    }

    public function supportsForcedVersion(): bool
    {
        return false;
    }

    public function getMaxForcedVersion(): int
    {
        return 0;
    }

    public function encode(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        $level = $options instanceof QrOptions
            ? $options->errorCorrection
            : ErrorCorrectionLevel::Medium;

        /** @var object{encodeMatrix: callable} $core */
        $core = new \NativeEncoderCore();

        return QrSymbols::fromMatrix($core->encodeMatrix($data, $level));
    }
}
