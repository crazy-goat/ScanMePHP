<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Qr\Backend;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\FfiEncoder;
use CrazyGoat\ScanMePHP\Generator\Qr\QrBackendInterface;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\Generator\Qr\QrSymbols;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * The same C++ core as the extension, reached through ext-ffi.
 */
final class FfiBackend implements QrBackendInterface
{
    private ?string $libraryPath = null;

    private bool $resolved = false;

    private ?FfiEncoder $encoder = null;

    public function getName(): string
    {
        return 'ffi';
    }

    public function isAvailable(): bool
    {
        if (!$this->resolved) {
            $this->libraryPath = FfiEncoder::resolveLibraryPath();
            $this->resolved = true;
        }

        return $this->libraryPath !== null;
    }

    public function getPriority(): int
    {
        return 300;
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
        if (!$this->isAvailable()) {
            throw new \RuntimeException('No usable native ScanMePHP library for the FFI backend');
        }

        // FFI::cdef() parses the header and dlopens the library, so the encoder
        // is built once and reused rather than per encode.
        $this->encoder ??= new FfiEncoder((string) $this->libraryPath);

        $level = $options instanceof QrOptions
            ? $options->errorCorrection
            : ErrorCorrectionLevel::Medium;

        return QrSymbols::fromMatrix($this->encoder->encode($data, $level));
    }
}
