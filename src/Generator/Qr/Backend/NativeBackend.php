<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Qr\Backend;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Generator\Qr\QrBackendInterface;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\Generator\Qr\QrSymbols;
use CrazyGoat\ScanMePHP\NativeEncoder;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * The scanmeqr PHP extension: the C++ core compiled into the process.
 */
final class NativeBackend implements QrBackendInterface
{
    private ?NativeEncoder $encoder = null;

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

        // NativeEncoder is the one place that knows how to reach the class the
        // C extension registers; going through it keeps that conditional
        // declaration — which no static analyser can resolve — in a single
        // file rather than spreading it across the backends.
        $this->encoder ??= new NativeEncoder();

        return QrSymbols::fromMatrix($this->encoder->encode($data, $level));
    }
}
