<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code39;

/**
 * Which of the two readings of Code 39 a symbol is meant for.
 *
 * The distinction is not in the bars. A Code 39 Extended symbol is an ordinary
 * Code 39 symbol whose characters happen to include the four shift characters;
 * nothing in the printed pattern says "interpret me as ASCII", and it is the
 * scanner's configuration that decides. That is why the two are registered as
 * separate symbologies rather than settled by an option: the choice belongs to
 * the data, before any bar is drawn, and a caller who picks the wrong one gets
 * a symbol that reads back as something else entirely rather than one that
 * fails to encode.
 */
enum Mode: string
{
    /** The 43 characters and nothing else. */
    case Standard = 'standard';

    /** All 128 ASCII bytes, the other 85 as two-character escape sequences. */
    case Extended = 'extended';
}
