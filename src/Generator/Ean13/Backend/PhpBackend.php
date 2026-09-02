<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Ean13\Backend;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * EAN-13 in pure PHP.
 *
 * The symbol is a fixed 95 modules: three guard patterns and twelve
 * seven-module digits. The thirteenth digit is not drawn at all — it is
 * encoded in which parity pattern each of the six left-hand digits uses, which
 * is what lets a scanner read the symbol in either direction.
 */
final class PhpBackend implements BackendInterface
{
    /** Odd-parity digits, used for left-hand positions marked L. */
    private const LEFT_ODD = [
        '0001101', '0011001', '0010011', '0111101', '0100011',
        '0110001', '0101111', '0111011', '0110111', '0001011',
    ];

    /** Even-parity digits, used for left-hand positions marked G. */
    private const LEFT_EVEN = [
        '0100111', '0110011', '0011011', '0100001', '0011101',
        '0111001', '0000101', '0010001', '0001001', '0010111',
    ];

    /** Right-hand digits: the bitwise complement of LEFT_ODD. */
    private const RIGHT = [
        '1110010', '1100110', '1101100', '1000010', '1011100',
        '1001110', '1010000', '1000100', '1001000', '1110100',
    ];

    /**
     * Which parity each of the six left-hand digits uses, selected by the
     * first digit. This is how the first digit is carried without a
     * thirteenth printed character.
     */
    private const FIRST_DIGIT_PARITY = [
        'LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG',
        'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL',
    ];

    private const START_GUARD = '101';

    private const CENTRE_GUARD = '01010';

    private const END_GUARD = '101';

    /** Module offsets of the three guards, which extend below the other bars. */
    private const GUARDS = [[0, self::START_GUARD], [45, self::CENTRE_GUARD], [92, self::END_GUARD]];

    public const WIDTH = 95;

    /**
     * Nominal geometry at magnification 1: bars 64 modules tall, with the
     * guards descending a further 5 so the human-readable digits sit between
     * them (ISO/IEC 15420).
     */
    private const BAR_HEIGHT = 64;

    private const GUARD_DESCENT = 5;

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
        $parity = self::FIRST_DIGIT_PARITY[(int) $digits[0]];

        $modules = self::START_GUARD;
        for ($position = 0; $position < 6; $position++) {
            $digit = (int) $digits[$position + 1];
            $modules .= $parity[$position] === 'L'
                ? self::LEFT_ODD[$digit]
                : self::LEFT_EVEN[$digit];
        }
        $modules .= self::CENTRE_GUARD;
        for ($position = 7; $position < 13; $position++) {
            $modules .= self::RIGHT[(int) $digits[$position]];
        }
        $modules .= self::END_GUARD;

        // Two module rows: the bars, then a shorter row dark only under the
        // guards. The row heights carry the descent, so the grid stays a plain
        // two-level bitmap and every renderer draws it without special cases.
        $descenders = str_repeat('0', self::WIDTH);
        foreach (self::GUARDS as [$offset, $guard]) {
            $descenders = substr_replace($descenders, $guard, $offset, \strlen($guard));
        }

        return new Symbol(
            width: self::WIDTH,
            height: 2,
            modules: $modules . $descenders,
            dimension: Dimension::Linear,
            // ISO/IEC 15420: 11 modules on the left, 7 on the right. The
            // asymmetry is real and a caller must not have to know it.
            quietZone: new QuietZone(left: 11, right: 7),
            rowHeights: [self::BAR_HEIGHT, self::GUARD_DESCENT],
            text: $digits,
            metadata: [
                'symbology' => Symbology::Ean13->value,
                'checkDigit' => (int) $digits[12],
            ],
        );
    }

    /**
     * The full 13 digits, computing the check digit when only 12 were given.
     *
     * @throws \InvalidArgumentException when the input is not encodable
     */
    public static function normalise(string $data): string
    {
        if (preg_match('/^\d{12,13}$/', $data) !== 1) {
            throw new \InvalidArgumentException('EAN-13 needs 12 or 13 digits, got: ' . $data);
        }

        $twelve = substr($data, 0, 12);
        $check = self::checkDigit($twelve);

        if (\strlen($data) === 13 && (int) $data[12] !== $check) {
            throw new \InvalidArgumentException(sprintf(
                'EAN-13 check digit for %s must be %d, got %s',
                $twelve,
                $check,
                $data[12]
            ));
        }

        return $twelve . $check;
    }

    /** Weighted modulo 10 over the first twelve digits, alternating 1 and 3. */
    public static function checkDigit(string $twelve): int
    {
        $sum = 0;
        for ($position = 0; $position < 12; $position++) {
            $sum += (int) $twelve[$position] * ($position % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }
}
