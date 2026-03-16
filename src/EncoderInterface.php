<?php

declare(strict_types=1);

namespace ScanMePHP;

interface EncoderInterface
{
    public function encode(
        string $url,
        ErrorCorrectionLevel $errorCorrectionLevel,
    ): Matrix;
}
