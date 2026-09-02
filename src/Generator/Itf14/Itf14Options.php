<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Itf14;

use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * What ITF-14 encoding can be told to do.
 *
 * Deliberately not a check-digit switch: the fourteenth digit of a GTIN-14 is
 * the check digit, so it is never optional here. That is the whole difference
 * between this and a fourteen-digit ITF.
 */
final class Itf14Options implements GeneratorOptionsInterface
{
    /**
     * @param bool $bearerBar Draw the bearer bar, the solid frame around the
     *        symbol. GS1 requires it on ITF-14, and it is not decoration: ITF
     *        is not self-checking, so a scan that clips the start or stop
     *        guard reads a valid shorter number, and the frame is what stops
     *        the beam from doing that. Off only if you are drawing your own.
     * @param int $wideRatio Modules spanned by a wide element. GS1 specifies
     *        2.5 for ITF-14 and permits 2 to 3; a module grid cannot draw the
     *        half, so 3 is the default — the wider of the two legal integers,
     *        matching the reason the bearer bar exists.
     */
    public function __construct(
        public readonly bool $bearerBar = true,
        public readonly int $wideRatio = 3,
    ) {
        if ($this->wideRatio < 2 || $this->wideRatio > 3) {
            throw new \InvalidArgumentException(sprintf(
                'The ITF-14 wide-to-narrow ratio must be 2 or 3, got %d',
                $this->wideRatio
            ));
        }
    }
}
