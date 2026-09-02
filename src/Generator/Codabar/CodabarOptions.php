<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Codabar;

use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * What Codabar encoding can be told to do.
 *
 * Notably absent: a check character. Codabar's is optional and there is more
 * than one incompatible definition of it in circulation — the AIM modulo-16
 * character, and at least two library-system variants that weight differently.
 * Nothing this library can verify against agrees on one: zxing-cpp neither
 * writes nor validates any of them, so a check character shipped here would be
 * a table with no independent check, which is the one thing this codebase does
 * not do with a barcode. If you need a specific variant, compute it and append
 * it to the payload — it is an ordinary data character in the symbol either
 * way.
 */
final class CodabarOptions implements GeneratorOptionsInterface
{
    /**
     * @param Delimiter $start Opening delimiter. Carries no data; it exists so
     *        one application's symbols can be told from another's on the same
     *        scanner.
     * @param Delimiter $stop Closing delimiter. Conventionally the same as the
     *        start, or B where the start is A.
     * @param int $wideRatio Modules spanned by a wide element, the narrow one
     *        being 1. The standard permits 2 to 3; 2 is the default and is
     *        what reference encoders emit.
     */
    public function __construct(
        public readonly Delimiter $start = Delimiter::A,
        public readonly Delimiter $stop = Delimiter::A,
        public readonly int $wideRatio = 2,
    ) {
        if ($this->wideRatio < 2 || $this->wideRatio > 3) {
            throw new \InvalidArgumentException(sprintf(
                'The Codabar wide-to-narrow ratio must be 2 or 3, got %d',
                $this->wideRatio
            ));
        }
    }
}
