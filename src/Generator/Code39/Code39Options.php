<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code39;

use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * What Code 39 encoding can be told to do.
 *
 * Both settings are in the standard and neither has a right answer: the check
 * character is optional and required only by particular industry
 * specifications, and the wide-to-narrow ratio is a printing decision.
 *
 * The reading mode is deliberately not here — see Mode.
 */
final class Code39Options implements GeneratorOptionsInterface
{
    /**
     * @param bool $checkCharacter Append the modulo-43 check character.
     *        Optional in ISO/IEC 16388 and off by default, because a scanner
     *        not configured to verify it reports it as a trailing data
     *        character; required by LOGMARS and HIBC, among others.
     * @param int $wideRatio Modules spanned by a wide element, the narrow one
     *        being 1. The standard permits 2 to 3 and recommends 3 wherever
     *        the print process allows it; 2 is the default because it is the
     *        narrowest legal symbol and what reference encoders emit.
     */
    public function __construct(
        public readonly bool $checkCharacter = false,
        public readonly int $wideRatio = 2,
    ) {
        if ($this->wideRatio < 2 || $this->wideRatio > 3) {
            throw new \InvalidArgumentException(sprintf(
                'The Code 39 wide-to-narrow ratio must be 2 or 3, got %d',
                $this->wideRatio
            ));
        }
    }
}
