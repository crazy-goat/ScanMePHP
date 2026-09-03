<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Rmqr;

use CrazyGoat\ScanMePHP\Encoding\Rmqr\Specs;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * What rMQR encoding can be told to do.
 *
 * There is no mask option here, and its absence is the symbology's rather than
 * an omission: rMQR defines one mask pattern and no way to say which was used,
 * so there is nothing to choose. The nine bits QR spends on a mask number are
 * spent here on saying which of the thirty-two sizes the symbol is.
 *
 * The size is worth pinning more often than a QR version is. A caller printing
 * along the side of a cable does not want "the smallest that fits" — they want
 * seven modules tall, whatever that costs in width, because that is the space
 * they have. {@see Version} names the shapes so that constraint can be stated
 * directly.
 */
final class RmqrOptions implements GeneratorOptionsInterface
{
    /**
     * @param ErrorCorrectionLevel|null $errorCorrection null, the default,
     *        takes the stronger of the two levels that still fits in the
     *        chosen size. L and Q do not exist in this symbology and are
     *        refused here rather than at encoding time.
     * @param Version|null $version Force one of the thirty-two shapes instead
     *        of taking the smallest that fits. Encoding fails if the data does
     *        not fit rather than silently growing the symbol, because a shape
     *        pinned here is usually a shape the label physically has room for.
     */
    public function __construct(
        public readonly ?ErrorCorrectionLevel $errorCorrection = null,
        public readonly ?Version $version = null,
    ) {
        if ($errorCorrection instanceof ErrorCorrectionLevel && !Specs::supports($errorCorrection)) {
            throw new \InvalidArgumentException(sprintf(
                'rMQR has no error correction level %s; the levels are M and H',
                $errorCorrection->name,
            ));
        }
    }
}
