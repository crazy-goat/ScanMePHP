<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Rmqr;

use CrazyGoat\ScanMePHP\Encoding\Rmqr\Specs;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;

/**
 * The thirty-two rMQR symbol sizes, named as the standard names them.
 *
 * The backing value is the number the symbol's own format information carries,
 * which is why the cases are in this order and not sorted by area: R11x27 is
 * smaller than R7x59 and comes after it here, because that is the order a
 * reader decodes them in. {@see \CrazyGoat\ScanMePHP\Encoding\Rmqr\RmqrEncoder::order()}
 * is where the by-area order lives, and it is a separate thing on purpose.
 */
enum Version: int
{
    case R7x43 = 0;
    case R7x59 = 1;
    case R7x77 = 2;
    case R7x99 = 3;
    case R7x139 = 4;
    case R9x43 = 5;
    case R9x59 = 6;
    case R9x77 = 7;
    case R9x99 = 8;
    case R9x139 = 9;
    case R11x27 = 10;
    case R11x43 = 11;
    case R11x59 = 12;
    case R11x77 = 13;
    case R11x99 = 14;
    case R11x139 = 15;
    case R13x27 = 16;
    case R13x43 = 17;
    case R13x59 = 18;
    case R13x77 = 19;
    case R13x99 = 20;
    case R13x139 = 21;
    case R15x43 = 22;
    case R15x59 = 23;
    case R15x77 = 24;
    case R15x99 = 25;
    case R15x139 = 26;
    case R17x43 = 27;
    case R17x59 = 28;
    case R17x77 = 29;
    case R17x99 = 30;
    case R17x139 = 31;

    public function height(): int
    {
        return Specs::height($this->value);
    }

    public function width(): int
    {
        return Specs::width($this->value);
    }

    /**
     * The error correction levels this size offers, weakest first.
     *
     * The same two at every size — M and H — which is why this is on the enum
     * rather than being looked up per case: there is no size that offers a
     * different pair, and a caller asking is asking about the symbology.
     *
     * @return list<ErrorCorrectionLevel>
     */
    public function levels(): array
    {
        return Specs::levels();
    }

    public function supports(ErrorCorrectionLevel $level): bool
    {
        return Specs::supports($level);
    }
}
