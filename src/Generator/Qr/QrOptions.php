<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Qr;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * What QR encoding itself can be told to do.
 *
 * Error correction lives here rather than in render options because it changes
 * the modules: a higher level spends symbol capacity on recovery data and can
 * push the symbol to a larger version. Nothing about appearance belongs in
 * this class — quiet zone, module size and colours are the renderer's.
 *
 * Each symbology brings its own bag, because their error correction schemes do
 * not line up: QR has four named levels, PDF417 has nine numbered ones, Aztec
 * takes a percentage, and DataMatrix ECC200 has none to choose.
 */
final class QrOptions implements GeneratorOptionsInterface
{
    public const MIN_VERSION = 1;

    public const MAX_VERSION = 40;

    /**
     * @param int|null $version Force a symbol version (1–40) instead of using
     *        the smallest that fits. Encoding fails if the data does not fit
     *        the requested version at the requested error correction level.
     */
    public function __construct(
        public readonly ErrorCorrectionLevel $errorCorrection = ErrorCorrectionLevel::Medium,
        public readonly ?int $version = null,
    ) {
        if ($this->version !== null
            && ($this->version < self::MIN_VERSION || $this->version > self::MAX_VERSION)
        ) {
            throw new \InvalidArgumentException(sprintf(
                'QR version must be between %d and %d, got %d',
                self::MIN_VERSION,
                self::MAX_VERSION,
                $this->version
            ));
        }
    }
}
