<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * The output formats shipped with this library.
 *
 * Accepted as `string|Format` everywhere, for the same reason as Symbology: a
 * caller registering their own renderer — a PDF writer, an EPS writer, an
 * imagick-backed one — must be able to address it as a first-class format.
 */
enum Format: string
{
    case Svg = 'svg';
    case Png = 'png';
    case HtmlDiv = 'html-div';
    case HtmlTable = 'html-table';
    case AsciiBlocks = 'ascii-blocks';
    case AsciiHalfBlocks = 'ascii-half-blocks';
    case AsciiDots = 'ascii-dots';

    /** The name the registry resolves, for either accepted form. */
    public static function nameOf(string|self $format): string
    {
        return $format instanceof self ? $format->value : $format;
    }
}
