<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code128;

/**
 * The Code 128 character sets this implementation uses.
 *
 * Set A — the one that encodes ASCII control characters — is deliberately
 * absent: it costs a third switching path for payloads that essentially never
 * occur in the wild, and claiming support for it while shipping the switching
 * logic untested would be worse than declining the input outright. Data
 * outside printable ASCII is therefore reported as unencodable.
 */
enum CodeSet: string
{
    /** Printable ASCII, one symbol character per input character. */
    case B = 'B';

    /** Digit pairs, one symbol character per two digits — half the width. */
    case C = 'C';
}
