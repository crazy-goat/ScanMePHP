<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * NativeEncoder - PHP wrapper for the scanme_qr PHP extension.
 *
 * This class provides the fastest QR encoding by using the native C library
 * via the scanme_qr PHP extension. It implements EncoderInterface for
 * seamless integration with ScanMePHP.
 */
final class NativeEncoder implements EncoderInterface
{
    private static ?NativeEncoderExt $extInstance = null;
    
    private function getExt(): NativeEncoderExt
    {
        if (self::$extInstance === null) {
            if (!extension_loaded('scanme_qr')) {
                throw new \RuntimeException('scanme_qr extension is not loaded');
            }
            self::$extInstance = new NativeEncoderExt();
        }
        return self::$extInstance;
    }

    public function encode(
        string $url,
        ErrorCorrectionLevel $errorCorrectionLevel,
    ): Matrix {
        // Get raw data from extension as array
        $rawData = $this->getExt()->encodeRaw($url, $errorCorrectionLevel);

        // Create Matrix and populate it
        $version = $rawData['version'];
        $matrix = new Matrix($version);
        $matrix->setRawData($rawData['data']);

        return $matrix;
    }
}
