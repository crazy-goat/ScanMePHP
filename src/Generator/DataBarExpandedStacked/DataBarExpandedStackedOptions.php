<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBarExpandedStacked;

use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * How wide to let a stacked symbol get before folding it.
 *
 * The only choice the symbology offers, and it is a choice about the label
 * rather than about the data: the same element strings can be four characters
 * wide and six rows tall or twenty wide and two rows tall, and which one fits
 * is something only the person printing it knows.
 *
 * The width is counted in symbol character *pairs*, and it has to be an even
 * number of them. That restriction is ours rather than the standard's, and it
 * is here because it is what we can stand behind: two pairs per row is what
 * every reference encoder draws and what our fixture checks module for module,
 * four to ten read back through an independent decoder, and an odd number of
 * pairs does not — under any of the twelve layouts we could construct for it,
 * which is enough to say the fault is not in one detail of the drawing. Rather
 * than emit a symbol nothing has read, those widths are refused.
 */
final class DataBarExpandedStackedOptions implements GeneratorOptionsInterface
{
    /** The narrowest a row may be, in character pairs. */
    public const MINIMUM_COLUMNS = 2;

    /** The widest a row may be, in character pairs. */
    public const MAXIMUM_COLUMNS = 10;

    /**
     * @param int $columns character pairs per row: an even number from 2 to 10.
     *        Two is the default GS1 gives for the symbol printed on a
     *        shelf-edge label, and what every reference encoder draws when it
     *        is not told otherwise.
     */
    public function __construct(public readonly int $columns = 2)
    {
        if ($columns < self::MINIMUM_COLUMNS || $columns > self::MAXIMUM_COLUMNS || $columns % 2 !== 0) {
            throw new \InvalidArgumentException(sprintf(
                'DataBar Expanded Stacked rows hold an even number of character pairs, %d to %d, got %d',
                self::MINIMUM_COLUMNS,
                self::MAXIMUM_COLUMNS,
                $columns
            ));
        }
    }
}
