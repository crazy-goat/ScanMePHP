<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Ean8\Backend;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Ean\Patterns;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * EAN-8 in pure PHP, the short form for packages too small for EAN-13.
 *
 * Sixty-seven modules: three guards and eight seven-module digits, four on
 * each side of the centre. Unlike EAN-13 there is no parity encoding — every
 * left-hand digit is odd-parity — because with eight digits printed there is
 * no thirteenth to hide.
 */
final class PhpBackend implements BackendInterface
{
    public const WIDTH = 67;

    /** Module offsets of the three guards, which extend below the other bars. */
    private const GUARDS = [
        [0, Patterns::START_GUARD],
        [31, Patterns::CENTRE_GUARD],
        [64, Patterns::END_GUARD],
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

        $modules = Patterns::START_GUARD;
        for ($position = 0; $position < 4; $position++) {
            $modules .= Patterns::LEFT_ODD[(int) $digits[$position]];
        }
        $modules .= Patterns::CENTRE_GUARD;
        for ($position = 4; $position < 8; $position++) {
            $modules .= Patterns::RIGHT[(int) $digits[$position]];
        }
        $modules .= Patterns::END_GUARD;

        return new Symbol(
            width: self::WIDTH,
            height: 2,
            modules: $modules . Patterns::descenderRow(self::WIDTH, self::GUARDS),
            dimension: Dimension::Linear,
            // ISO/IEC 15420: seven modules on both sides. Symmetric here,
            // unlike EAN-13, because there is no digit printed to the left.
            quietZone: new QuietZone(left: 7, right: 7),
            rowHeights: [Patterns::BAR_HEIGHT, Patterns::GUARD_DESCENT],
            text: $digits,
            metadata: [
                'symbology' => Symbology::Ean8->value,
                'checkDigit' => (int) $digits[7],
            ],
        );
    }

    /**
     * The full 8 digits, computing the check digit when only 7 were given.
     *
     * @throws \InvalidArgumentException when the input is not encodable
     */
    public static function normalise(string $data): string
    {
        return Patterns::normalise($data, 7, 'EAN-8');
    }
}
