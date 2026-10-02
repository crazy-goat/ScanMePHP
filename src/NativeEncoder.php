<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * NativeEncoder - Hybrid implementation.
 */
if (extension_loaded('scanmeqr')) {
    // If the extension is loaded, extend the C class (NativeEncoderCore)
    // and implement the PHP interface.
    // This gives the speed of C and the type compatibility of PHP.
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
