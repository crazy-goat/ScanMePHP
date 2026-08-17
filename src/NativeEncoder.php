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
            return (new FfiEncoder($this->resolveLibraryPath()))->encode($url, $errorCorrectionLevel);
        }

        private function resolveLibraryPath(): string
        {
            // Same resolution order as QRCode::createDefaultEncoder: vendor binary first, then local build
            $vendorBinary = dirname(__DIR__) . '/../../crazy-goat/scanmephp/ffi-binaries/' . PlatformDetector::getCurrentPlatformBinaryName();

            if (FfiEncoder::isAvailable($vendorBinary)) {
                return $vendorBinary;
            }

            $localBuild = dirname(__DIR__) . '/clib/build/libscanme_qr.so';

            if (FfiEncoder::isAvailable($localBuild)) {
                return $localBuild;
            }

            throw new \RuntimeException(
                'No native ScanMePHP library found: build the FFI library (cmake -S clib -B clib/build && cmake --build clib/build) or install the scanmeqr PHP extension.'
            );
        }
    }
}
