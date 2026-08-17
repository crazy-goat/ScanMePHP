<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

use CrazyGoat\ScanMePHP\Encoding\Mode;
use CrazyGoat\ScanMePHP\Exception\InvalidDataException;
use FFI;

class FfiEncoder implements EncoderInterface
{
    private readonly FFI $ffi;

    public function __construct(string $libraryPath)
    {
        if (!extension_loaded('ffi')) {
            throw new \RuntimeException('ext-ffi is required for FfiEncoder');
        }

        if (!file_exists($libraryPath)) {
            throw new \RuntimeException(
                sprintf('Native library not found: %s', $libraryPath)
            );
        }

        $header = (string) file_get_contents(__DIR__ . '/ffi/scanme_qr.h');
        $this->ffi = FFI::cdef($header, $libraryPath);
    }

    public function encode(
        string $data,
        ErrorCorrectionLevel $errorCorrectionLevel,
        int $requestedVersion = 0,
        ?Mode $forcedMode = null
    ): Matrix {
        if ($data === '') {
            throw InvalidDataException::emptyData();
        }

        $out = $this->ffi->new('scanme_qr_result_t');
        $ret = $this->ffi->scanme_qr_encode(
            $data,
            strlen($data),
            $errorCorrectionLevel->value,
            FFI::addr($out)
        );

        if ($ret !== 0 || FFI::isNull($out->modules)) {
            throw new \RuntimeException('Native QR encoding failed');
        }

        $size    = $out->size;
        $version = $out->version;

        $flat = FFI::string($out->modules, $size * $size);
        $this->ffi->scanme_qr_result_free(FFI::addr($out));

        $bytes = array_values((array) unpack('C*', $flat));
        $matrix = new Matrix($version);
        $matrix->setData(
            array_map(
                static fn (array $row): array => array_map(
                    static fn (int $v): bool => $v !== 0,
                    $row
                ),
                array_chunk($bytes, $size)
            )
        );

        return $matrix;
    }

    public static function isAvailable(string $libraryPath): bool
    {
        return extension_loaded('ffi') && file_exists($libraryPath);
    }

    /**
     * Single source of truth for the FFI library path resolution used by both
     * QRCode::createDefaultEncoder() and NativeEncoder's no-extension fallback.
     * Returns the first usable library path (vendor binary first, then local
     * build), or null when none is available (including when ext-ffi is absent).
     */
    public static function resolveLibraryPath(): ?string
    {
        $vendorBinary = dirname(__DIR__) . '/../../crazy-goat/scanmephp/ffi-binaries/' . PlatformDetector::getCurrentPlatformBinaryName();
        if (self::isAvailable($vendorBinary)) {
            return $vendorBinary;
        }

        $localBuild = dirname(__DIR__) . '/clib/build/libscanme_qr.so';
        if (self::isAvailable($localBuild)) {
            return $localBuild;
        }

        return null;
    }

    public function getLibraryVersion(): string
    {
        return (string) $this->ffi->scanme_qr_version();
    }
}
