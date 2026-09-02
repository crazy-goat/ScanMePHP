<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Ean5\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Ean\Patterns;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * EAN-5 in pure PHP: the five-digit add-on, most often a book's list price.
 *
 * Forty-eight modules — a five-module guard, five seven-module digits and four
 * two-module separators — and no trailing guard. The parity of the five digits
 * is chosen by a checksum weighted 3 and 9 from the left, so unlike EAN-2 an
 * add-on here does carry redundancy, just nowhere a human can read it.
 *
 * Unlike the four main members this symbol is not a complete article number.
 * A scanner asked for an add-on alone will usually decline to report it, which
 * is why the round-trip gate for this symbology tests it beside an EAN-13.
 */
final class PhpBackend implements BackendInterface
{
    public const WIDTH = 48;

    /** Digits carried; the checksum that picks their parity is not one of them. */
    public const DIGITS = 5;

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
                'symbology' => Symbology::Ean5->value,
                'parity' => Patterns::addOnParity($digits),
                // Never printed, but the only thing standing between a misread
                // and a wrong price, so a caller auditing a symbol can see it.
                'checkDigit' => Patterns::addOnCheckDigit($digits),
            ],
        );
    }

    /**
     * The five digits, rejecting anything else.
     *
     * @throws \InvalidArgumentException when the input is not encodable
     */
    public static function normalise(string $data): string
    {
        return Patterns::addOnDigits($data, self::DIGITS, 'EAN-5');
    }
}
