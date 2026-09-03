<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBarLimited\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\DataBar\Patterns;
use CrazyGoat\ScanMePHP\Generator\Ean\Patterns as CheckDigit;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * GS1 DataBar Limited in pure PHP.
 *
 * Same family as Omnidirectional, same job, and almost nothing in common
 * underneath. Limited splits the thirteen digits in two around 2013571 and
 * spends one character on each half — two characters rather than four, each
 * twice as long, laid out plainly left to right:
 *
 *     guard  left  finder  right  guard
 *
 * There is no mirrored half here and no second finder, which is precisely what
 * the symbology gives up. Omnidirectional can be swept at any angle because
 * each half of it is a complete, self-locating unit; Limited cannot, and in
 * exchange it fits a GTIN into 74 modules instead of 96.
 *
 * The rest of the arithmetic is in {@see Patterns}, and the differences from
 * Omnidirectional are recorded there because that is where they would otherwise
 * be assumed away. What belongs here is the range: the value must fit in
 * 2013571 squared, which is why Limited carries only indicator digits 0 and 1.
 * A GTIN-14 beginning with 2 is a perfectly good number and there is no Limited
 * symbol for it.
 *
 * The finder is the checksum, as it is in Omnidirectional — but where that one
 * splits a residue over two finder patterns, this one has a single finder and
 * eighty-nine patterns to choose it from, one per residue. So there is no check
 * character in the bars here either, and nothing is skipped: every residue
 * addresses a pattern.
 */
final class PhpBackend implements BackendInterface
{
    /** Digits before the check digit. */
    public const PAYLOAD = 13;

    /** The whole symbol, guards included. */
    public const MODULES = 74;

    /**
     * Height in modules.
     *
     * Limited is not omnidirectional, so it does not need the 33X that buys
     * Omnidirectional its any-angle sweep. GS1 asks for 10X, which is the
     * height at which a scanner passed across the symbol still crosses it.
     */
    public const BAR_HEIGHT = 10;

    /**
     * Blank modules the symbol needs on its right.
     *
     * Asymmetric, and measured rather than assumed: the reference encoder draws
     * five blank modules after the right guard and none before the left one.
     * The left side needs none because the left guard *is* a space — the symbol
     * opens with a light module — so the margin is already in the 74.
     */
    public const RIGHT_QUIET_ZONE = 5;

    /** The value the thirteen digits split around, once per character. */
    private const HALF_RANGE = Patterns::LIMITED_VALUES;

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
        $value = (int) substr($digits, 0, self::PAYLOAD);

        $left = Patterns::character(intdiv($value, self::HALF_RANGE), Patterns::LIMITED, true);
        $right = Patterns::character($value % self::HALF_RANGE, Patterns::LIMITED, true);

        $checksum = Patterns::checksum([...$left, ...$right], Patterns::LIMITED_MODULUS);

        $widths = [
            1,
            1,
            ...$left,
            ...Patterns::limitedFinder($checksum),
            ...$right,
            1,
            1,
        ];

        return Symbol::linear(
            modules: Patterns::modules($widths),
            quietZone: new QuietZone(right: self::RIGHT_QUIET_ZONE),
            barHeight: self::BAR_HEIGHT,
            text: '(01)' . $digits,
            metadata: [
                'symbology' => Symbology::DataBarLimited->value,
                'checkDigit' => (int) $digits[self::PAYLOAD],
                'checksum' => $checksum,
            ],
        );
    }

    /**
     * The full fourteen digits, with the check digit computed or verified.
     *
     * A leading '(01)' is accepted and dropped: it is the application
     * identifier a scanner reports and GS1 prints, but the symbol does not
     * carry it — DataBar means AI 01 and nothing else, so there is nowhere in
     * the bars for it to go.
     *
     * @throws \InvalidArgumentException when the input is not encodable
     */
    public static function normalise(string $data): string
    {
        $digits = CheckDigit::normalise(self::strip($data), self::PAYLOAD, 'DataBar Limited');

        if ($digits[0] !== '0' && $digits[0] !== '1') {
            throw new \InvalidArgumentException(sprintf(
                'DataBar Limited carries indicator digits 0 and 1 only, got: %s',
                $digits[0]
            ));
        }

        return $digits;
    }

    /** Whether $data is a GTIN this symbology has room for. */
    public static function accepts(string $data): bool
    {
        $data = self::strip($data);

        return ($data[0] ?? '') !== '' && ($data[0] === '0' || $data[0] === '1')
            && CheckDigit::accepts($data, self::PAYLOAD);
    }

    private static function strip(string $data): string
    {
        return str_starts_with($data, '(01)') ? substr($data, 4) : $data;
    }
}
