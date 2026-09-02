<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * @internal Contract between the QR generator and its backends.
 */
interface EncoderInterface
{
    public function encode(
        string $url,
        ErrorCorrectionLevel $errorCorrectionLevel,
    ): Matrix;
}
