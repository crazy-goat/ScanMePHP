<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Ean2\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Ean\Patterns;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * EAN-2 in pure PHP: the two-digit add-on, printed beside a main symbol.
 *
 * Twenty-one modules — a five-module guard, two seven-module digits and the
 * two-module separator between them — and no trailing guard at all. Which
 * parity each digit uses is the printed value modulo 4, so the pair 00 and the
 * pair 04 draw the same parities but different digits; there is no check digit
 * to catch a misread, only that parity.
 *
 * Unlike the four main members this symbol is not a complete article number.
 * A scanner asked for an add-on alone will usually decline to report it, which
 * is why the round-trip gate for this symbology tests it beside an EAN-13.
 */
final class PhpBackend implements BackendInterface
{
    public const WIDTH = 21;

    /** Digits carried, before any parity is chosen. */
    public const DIGITS = 2;

    public function getName(): string
    {
        return 'php';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function encode(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        $digits = self::normalise($data);

        return Symbol::linear(
            modules: Patterns::addOnModules($digits),
            // ISO/IEC 15420: five modules to the right of an add-on, and at
            // least seven to its left — that left margin is the gap from the
            // main symbol, kept here so a standalone add-on still prints with
            // room for the symbol it belongs to.
            quietZone: new QuietZone(left: 7, right: 5),
            barHeight: Patterns::BAR_HEIGHT,
            // The spec prints an add-on's digits above its bars, not below.
            // The renderers draw text below, which is where it goes when the
            // add-on is the whole symbol and nothing sits above it.
            text: $digits,
            metadata: [
                'symbology' => Symbology::Ean2->value,
                'parity' => Patterns::addOnParity($digits),
            ],
        );
    }

    /**
     * The two digits, rejecting anything else.
     *
     * @throws \InvalidArgumentException when the input is not encodable
     */
    public static function normalise(string $data): string
    {
        return Patterns::addOnDigits($data, self::DIGITS, 'EAN-2');
    }
}
