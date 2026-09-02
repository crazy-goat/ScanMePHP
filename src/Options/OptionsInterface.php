<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Options;

/**
 * Marker for any option bag a caller can hand to Scanme::render().
 *
 * Options are deliberately not one god object: a generator's knobs (error
 * correction level, forced version, encoding mode) have nothing to do with a
 * renderer's (module size, colours, compression), and a custom symbology or
 * renderer must be able to introduce its own without touching this library.
 * The facade routes each bag to whoever declares it, so an unrecognised bag is
 * reported rather than ignored.
 */
interface OptionsInterface
{
}
