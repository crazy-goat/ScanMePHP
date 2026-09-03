<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Aztec;

use CrazyGoat\ScanMePHP\Encoding\Aztec\AztecEncoder;
use CrazyGoat\ScanMePHP\Encoding\Aztec\Specs;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * What Aztec encoding can be told to do.
 *
 * Aztec has no named error correction levels. It has a percentage, and the
 * percentage is a floor rather than a target: the symbol is sized to hold the
 * data plus at least that much recovery data, and whatever capacity is left
 * over becomes recovery data too. Five characters land in the smallest symbol
 * there is and come out with over half of it given to error correction, because
 * there was nowhere else for the room to go.
 *
 * That is worth being explicit about, because two numbers describe the same
 * symbol and encoders disagree about which to report — the share of the whole
 * symbol, or the share relative to the data. This option is the first: the
 * fraction of the symbol's codewords that will not be data.
 */
final class AztecOptions implements GeneratorOptionsInterface
{
    public const MIN_ERROR_CORRECTION_PERCENT = 0;

    public const MAX_ERROR_CORRECTION_PERCENT = 90;

    /**
     * @param int $errorCorrectionPercent The least share of the symbol to give
     *        to error correction, as a percentage. ISO/IEC 24778 recommends at
     *        least 23%; the default here is the 33% that the encoders this
     *        library is checked against use, so that a symbol produced with the
     *        defaults is the one a reader expects. Raising it costs a larger
     *        symbol; there is no ceiling in the standard, and the one here only
     *        keeps a caller from asking for a symbol with no room for data.
     * @param int|null $size Force the symbol's width in modules — one of
     *        {@see Specs::sizes()}, from 15 up to 151. A size names exactly one
     *        symbol, since the compact sizes stop at 27 and the full ones start
     *        at 31, which is why this is a size and not a layer count: there
     *        are two different symbols with four layers. Encoding fails if the
     *        data does not fit. Pinning a size overrides the percentage, since
     *        the two can contradict each other and the size is the more
     *        concrete request.
     */
    public function __construct(
        public readonly int $errorCorrectionPercent = AztecEncoder::DEFAULT_ERROR_CORRECTION_PERCENT,
        public readonly ?int $size = null,
    ) {
        if (
            $errorCorrectionPercent < self::MIN_ERROR_CORRECTION_PERCENT
            || $errorCorrectionPercent > self::MAX_ERROR_CORRECTION_PERCENT
        ) {
            throw new \InvalidArgumentException(sprintf(
                'Aztec error correction must be between %d%% and %d%%, got %d%%',
                self::MIN_ERROR_CORRECTION_PERCENT,
                self::MAX_ERROR_CORRECTION_PERCENT,
                $errorCorrectionPercent,
            ));
        }

        if ($size !== null && !\in_array($size, Specs::sizes(), true)) {
            throw new \InvalidArgumentException(sprintf(
                'An Aztec symbol is not %d modules across; the sizes are %s',
                $size,
                implode(', ', Specs::sizes()),
            ));
        }
    }
}
