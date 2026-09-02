<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

/**
 * Which element family HtmlRenderer builds the grid from.
 *
 * Flexbox divs are the better default; the table form exists because several
 * email clients still strip or ignore flex layout, and a barcode in an email
 * that collapses into a single line is worse than verbose markup.
 */
enum HtmlMode: string
{
    case Div = 'html-div';
    case Table = 'html-table';
}
