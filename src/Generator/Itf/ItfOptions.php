<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Itf;

use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * What ITF encoding can be told to do.
 *
 * Both settings are in ISO/IEC 16390 and neither has a right answer.
 */
final class ItfOptions implements GeneratorOptionsInterface
{
    /**
     * @param bool $checkDigit Append the GS1 modulo-10 check digit. Optional
     *        in the standard and off by default, because a scanner not
     *        configured to verify it reports it as a trailing digit. It also
     *        flips which payload lengths are acceptable: with it on, an *odd*
     *        number of digits is what encodes, the check digit making the
     *        count even.
     * @param int $wideRatio Modules spanned by a wide element, the narrow one
     *        being 1. The standard permits 2 to 3; 3 is the default because
     *        ITF is not self-checking and the wider ratio is what keeps a
     *        marginal scan from resolving into a different number.
     */
    public function __construct(
        public readonly bool $checkDigit = false,
        public readonly int $wideRatio = 3,
    ) {
        if ($this->wideRatio < 2 || $this->wideRatio > 3) {
            throw new \InvalidArgumentException(sprintf(
                'The ITF wide-to-narrow ratio must be 2 or 3, got %d',
                $this->wideRatio
            ));
        }
    }
}
