<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Exception;

use Exception;

/**
 * An option bag nobody claimed, or two bags claiming the same slot.
 *
 * Silently ignoring an option the caller deliberately passed is the worst
 * outcome here: the symbol renders, looks plausible, and quietly lacks the
 * error correction level or colour that was asked for.
 */
class UnsupportedOptionsException extends Exception
{
    public static function unclaimed(string $class): self
    {
        return new self(sprintf(
            'Options of type %s implement neither GeneratorOptionsInterface nor RenderOptionsInterface, '
            . 'so nothing would consume them',
            $class
        ));
    }

    public static function duplicate(string $slot, string $first, string $second): self
    {
        return new self(sprintf(
            'Received two %s option bags (%s and %s); pass at most one',
            $slot,
            $first,
            $second
        ));
    }

    public static function notApplicable(string $class, string $context): self
    {
        return new self(sprintf(
            'Options of type %s are not consumed when %s; drop them or use the call that applies them',
            $class,
            $context
        ));
    }

    public static function wrongType(string $symbology, string $expected, string $given): self
    {
        return new self(sprintf(
            'The %s generator expects options of type %s, got %s',
            $symbology,
            $expected,
            $given
        ));
    }
}
