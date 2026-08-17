<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * NativeEncoder - Implementacja hybrydowa.
 */
if (extension_loaded('scanmeqr')) {
    // Jeśli extension jest, dziedziczymy po klasie z C (NativeEncoderCore)
    // i implementujemy interfejs PHP.
    // Dzięki temu mamy szybkość C i zgodność typów PHP.
    final class NativeEncoder extends NativeEncoderCore implements EncoderInterface
    {
        public function encode(
            string $url,
            ErrorCorrectionLevel $errorCorrectionLevel,
        ): Matrix {
            // Przekazujemy do metody z NativeEncoderCore (zdefiniowanej w C)
            return parent::encodeMatrix($url, $errorCorrectionLevel);
        }
    }
} else {
    // Fallback gdy brak extensionu
    final class NativeEncoder implements EncoderInterface
    {
        public function encode(
            string $url,
            ErrorCorrectionLevel $errorCorrectionLevel,
        ): Matrix {
            $libraryPath = FfiEncoder::resolveLibraryPath()
                ?? throw new \RuntimeException(
                    'No native ScanMePHP library available: build the FFI library '
                    . '(cmake -S clib -B clib/build && cmake --build clib/build), '
                    . 'enable the ext-ffi extension, or install the scanmeqr PHP extension.'
                );

            return (new FfiEncoder($libraryPath))->encode($url, $errorCorrectionLevel);
        }
    }
}
