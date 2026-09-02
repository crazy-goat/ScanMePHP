<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

/**
 * Which side of the bars a human-readable interpretation is printed on.
 *
 * Below is the ordinary case and what every symbology in this library wanted
 * until add-on placement: the digits under an EAN, the payload under a Code
 * 128. Above exists because an EAN-2 or EAN-5 printed beside a main symbol has
 * its digits over its bars, not under them — there is nowhere under them to
 * put anything, since the main symbol's own digits occupy that line.
 */
enum TextPlacement: string
{
    case Above = 'above';

    case Below = 'below';
}
