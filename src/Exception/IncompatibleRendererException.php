<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Exception;

use Exception;

/**
 * The chosen renderer cannot faithfully draw the symbol it was handed.
 *
 * Raised before any output is produced, because the alternative — emitting a
 * symbol with its hexagons squared off or its human-readable digits missing —
 * looks like a working barcode while failing to scan or failing an audit.
 */
class IncompatibleRendererException extends Exception
{
    /**
     * @param string|null $symbology Name the symbol reports, when it reports one
     * @param list<string> $reasons
     */
    public static function because(?string $symbology, string $format, array $reasons): self
    {
        return new self(sprintf(
            'The "%s" renderer cannot render %s: %s',
            $format,
            $symbology === null ? 'this symbol' : 'this ' . $symbology . ' symbol',
            implode('; ', $reasons)
        ));
    }
}
