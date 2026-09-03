<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBarOmni;

use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * What GS1 DataBar Omnidirectional encoding can be told to do.
 *
 * There is exactly one choice, and it is a height. GS1 lists "DataBar
 * Truncated" as its own symbology, but the two are the same ninety-six modules
 * in the same order — a truncated symbol is an omnidirectional one printed
 * shorter. Registering it separately would mean two names for one encoder and a
 * caller having to know which of them draws the bars they already have; making
 * it a preference says the true thing, which is that the modules do not change.
 *
 * The heights are not decoration either. Omnidirectional means a scanner may
 * sweep the symbol at any angle and still cross a full row of it, and 33X is
 * the height that buys that. At 13X it no longer holds, which is why a
 * truncated symbol is for a scanner passed straight over it.
 */
final class DataBarOmniOptions implements GeneratorOptionsInterface
{
    /**
     * @param bool $truncated Print at 13X instead of 33X. The bars are
     *        identical; what is given up is the omnidirectional scan.
     */
    public function __construct(
        public readonly bool $truncated = false,
    ) {
    }
}
