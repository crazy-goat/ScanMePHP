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

    public const MIN_MASK = 0;

    public const MAX_MASK = 7;

    /**
     * @param int|null $version Force a symbol version (1–40) instead of using
     *        the smallest that fits. Encoding fails if the data does not fit
     *        the requested version at the requested error correction level.
     * @param int|null $mask Force one of the eight mask patterns (0–7) instead
     *        of scoring them. Null, the default, keeps the automatic choice.
     *
     *        This is a genuine choice rather than a limitation worked around.
     *        ISO/IEC 18004 clause 7.8.3 says to score all eight and take the
     *        lowest, but the rules — chiefly rule 3, the 1:1:3:1:1 pattern —
     *        are read differently in practice and ties are ordinary, so
     *        conforming encoders routinely disagree: over sixty random byte
     *        payloads, zxing-cpp and Nayuki's qrcodegen produced the same
     *        modules eight times. All eight maskings carry identical data and
     *        all of them scan. A caller reproducing another system's symbols
     *        byte for byte, or pinning output for a golden-file test, needs to
     *        say which one; everyone else should leave this alone.
     */
    public function __construct(
        public readonly ErrorCorrectionLevel $errorCorrection = ErrorCorrectionLevel::Medium,
        public readonly ?int $version = null,
        public readonly ?int $mask = null,
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

        if ($this->mask !== null && ($this->mask < self::MIN_MASK || $this->mask > self::MAX_MASK)) {
            throw new \InvalidArgumentException(sprintf(
                'QR mask pattern must be between %d and %d, got %d',
                self::MIN_MASK,
                self::MAX_MASK,
                $this->mask
            ));
        }
    }
}
