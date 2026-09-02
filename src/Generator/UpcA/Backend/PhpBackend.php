<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\UpcA\Backend;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Ean\Patterns;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * UPC-A in pure PHP, the North American retail code.
 *
 * The same ninety-five modules as EAN-13, and deliberately so: a UPC-A symbol
 * is bit for bit the EAN-13 of the same number with a leading zero, which is
 * why scanners read one as the other. It is a separate symbology here rather
 * than an alias because the twelve digits printed underneath, the symmetric
 * quiet zone and what a caller gets back all differ, and because a decoder
 * asked for UPC-A must report UPC-A.
 */
final class PhpBackend implements BackendInterface
{
    public const WIDTH = 95;

    /** Module offsets of the three guards, which extend below the other bars. */
    private const GUARDS = [
        [0, Patterns::START_GUARD],
        [45, Patterns::CENTRE_GUARD],
        [92, Patterns::END_GUARD],
    ];

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

        // Every left-hand digit is odd-parity: the number system digit is
        // printed, so there is nothing for the parity choice to carry.
        $modules = Patterns::START_GUARD;
        for ($position = 0; $position < 6; $position++) {
            $modules .= Patterns::LEFT_ODD[(int) $digits[$position]];
        }
        $modules .= Patterns::CENTRE_GUARD;
        for ($position = 6; $position < 12; $position++) {
            $modules .= Patterns::RIGHT[(int) $digits[$position]];
        }
        $modules .= Patterns::END_GUARD;

        return new Symbol(
            width: self::WIDTH,
            height: 2,
            modules: $modules . Patterns::descenderRow(self::WIDTH, self::GUARDS),
            dimension: Dimension::Linear,
            quietZone: new QuietZone(left: 9, right: 9),
            rowHeights: [Patterns::BAR_HEIGHT, Patterns::GUARD_DESCENT],
            text: $digits,
            metadata: [
                'symbology' => Symbology::UpcA->value,
                'checkDigit' => (int) $digits[11],
                // The number a scanner reports, and what to hand to an EAN-13
                // renderer or a GTIN-13 database column.
                'ean13' => '0' . $digits,
            ],
        );
    }

    /**
     * The full 12 digits, computing the check digit when only 11 were given.
     *
     * @throws \InvalidArgumentException when the input is not encodable
     */
    public static function normalise(string $data): string
    {
        return Patterns::normalise($data, 11, 'UPC-A');
    }
}
