<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\UpcE\Backend;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Ean\Patterns;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * UPC-E in pure PHP: a UPC-A with its run of zeros suppressed.
 *
 * Fifty-one modules — a start guard, six digits, and a six-module end guard
 * with no centre guard at all. Two things make it the odd member of the
 * family. Its check digit is never drawn: like EAN-13's first digit it lives
 * in the parity pattern, but selected by the check digit *and* the number
 * system digit, which is why the table below is indexed by both. And its
 * digits are not the number it represents: the symbol carries six, the article
 * number has twelve, and the mapping between them is a set of
 * zero-suppression rules rather than a truncation.
 */
final class PhpBackend implements BackendInterface
{
    public const WIDTH = 51;

    /**
     * Left-hand parity per check digit, for number system 0; number system 1
     * uses the complement of the same row.
     *
     * L is odd parity, G even, as in EAN-13. Verified pattern by pattern
     * against an independent encoder — see tests/fixtures/ean_upc_reference.csv
     * — because a single swapped row here yields a symbol that scans as a
     * different product.
     */
    private const CHECK_DIGIT_PARITY = [
        'GGGLLL', 'GGLGLL', 'GGLLGL', 'GGLLLG', 'GLGGLL',
        'GLLGGL', 'GLLLGG', 'GLGLGL', 'GLGLLG', 'GLLGLG',
    ];

    /** Module offsets of the two guards, which extend below the other bars. */
    private const GUARDS = [
        [0, Patterns::START_GUARD],
        [45, Patterns::UPCE_END_GUARD],
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
        $system = (int) $digits[0];
        $check = (int) $digits[7];

        $parity = self::CHECK_DIGIT_PARITY[$check];
        if ($system === 1) {
            $parity = strtr($parity, 'LG', 'GL');
        }

        $modules = Patterns::START_GUARD;
        for ($position = 0; $position < 6; $position++) {
            $digit = (int) $digits[$position + 1];
            $modules .= $parity[$position] === 'L'
                ? Patterns::LEFT_ODD[$digit]
                : Patterns::LEFT_EVEN[$digit];
        }
        $modules .= Patterns::UPCE_END_GUARD;

        return new Symbol(
            width: self::WIDTH,
            height: 2,
            modules: $modules . Patterns::descenderRow(self::WIDTH, self::GUARDS),
            dimension: Dimension::Linear,
            // ISO/IEC 15420: nine modules on the left, seven on the right.
            quietZone: new QuietZone(left: 9, right: 7),
            rowHeights: [Patterns::BAR_HEIGHT, Patterns::GUARD_DESCENT],
            text: $digits,
            metadata: [
                'symbology' => Symbology::UpcE->value,
                'checkDigit' => $check,
                // What a scanner actually reports: the expanded article
                // number. A caller that only has the UPC-E digits would
                // otherwise have to reimplement the rules to look the product up.
                'upca' => self::expand($digits),
            ],
        );
    }

    /**
     * The full 8 digits: number system, six data digits, check digit.
     *
     * Accepts the UPC-E form (7 or 8 digits) or the UPC-A it stands for
     * (11 or 12), compressing the latter. Both are things callers actually
     * hold: a UPC-E from a label spec, a UPC-A from a product database.
     *
     * @throws \InvalidArgumentException when the input is not encodable
     */
    public static function normalise(string $data): string
    {
        if (preg_match('/^\d{11,12}$/', $data) === 1) {
            return self::compress(Patterns::normalise($data, 11, 'UPC-A'));
        }

        if (preg_match('/^[01]\d{6,7}$/', $data) !== 1) {
            throw new \InvalidArgumentException(
                'UPC-E needs 7 or 8 digits starting with number system 0 or 1, or the 11 or 12 '
                . 'digits of the UPC-A it stands for, got: ' . $data
            );
        }

        $drawn = substr($data, 0, 7);
        self::assertCompressible($drawn);

        // The check digit belongs to the expanded article number: it cannot be
        // computed from the six digits the symbol draws, which is the whole
        // reason UPC-E cannot be treated as a short UPC-A.
        $check = Patterns::checkDigit(substr(self::expand($drawn . '0'), 0, 11));

        if (\strlen($data) === 8 && (int) $data[7] !== $check) {
            throw new \InvalidArgumentException(sprintf(
                'UPC-E check digit for %s must be %d, got %s',
                $drawn,
                $check,
                $data[7]
            ));
        }

        return $drawn . $check;
    }

    /**
     * The twelve-digit UPC-A an eight-digit UPC-E stands for.
     *
     * The last data digit selects which run of zeros was suppressed, and the
     * rules do not overlap — that is what makes the compression reversible.
     */
    public static function expand(string $upcE): string
    {
        $system = $upcE[0];
        $six = substr($upcE, 1, 6);
        $check = $upcE[7];

        $body = match (true) {
            (int) $six[5] <= 2 => substr($six, 0, 2) . $six[5] . '0000' . substr($six, 2, 3),
            $six[5] === '3' => substr($six, 0, 3) . '00000' . substr($six, 3, 2),
            $six[5] === '4' => substr($six, 0, 4) . '00000' . $six[4],
            default => substr($six, 0, 5) . '0000' . $six[5],
        };

        return $system . $body . $check;
    }

    /**
     * The eight-digit UPC-E for a twelve-digit UPC-A, if it has one.
     *
     * Most UPC-A numbers do not: the zeros have to sit exactly where one of
     * the four rules puts them. Refusing is the only honest answer — there is
     * no shorter symbol for that article number.
     *
     * @throws \InvalidArgumentException when the number cannot be compressed
     */
    public static function compress(string $upcA): string
    {
        [$system, $d, $check] = [$upcA[0], substr($upcA, 1, 10), $upcA[11]];

        if ($system !== '0' && $system !== '1') {
            throw new \InvalidArgumentException(sprintf(
                'UPC-E only exists for number system 0 or 1, and %s starts with %s',
                $upcA,
                $system
            ));
        }

        // $d is A B C D E F G H I J; the rules read off which zeros are gone.
        $six = match (true) {
            substr($d, 3, 4) === '0000' && $d[2] <= '2' => substr($d, 0, 2) . substr($d, 7, 3) . $d[2],
            substr($d, 3, 5) === '00000' => substr($d, 0, 3) . substr($d, 8, 2) . '3',
            substr($d, 4, 5) === '00000' => substr($d, 0, 4) . $d[9] . '4',
            substr($d, 5, 4) === '0000' && $d[9] >= '5' => substr($d, 0, 5) . $d[9],
            default => throw new \InvalidArgumentException(sprintf(
                '%s has no UPC-E form: its zeros do not match any zero-suppression rule',
                $upcA
            )),
        };

        return $system . $six . $check;
    }

    /**
     * A UPC-E whose digits break the zero-suppression rules would expand to a
     * UPC-A that compresses back to a different UPC-E, so it is not a symbol
     * we are allowed to draw.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertCompressible(string $upcE): void
    {
        $six = substr($upcE, 1, 6);
        $broken = match (true) {
            $six[5] === '3' && $six[2] <= '2' => 'with a last digit of 3 the third digit cannot be 0, 1 or 2',
            $six[5] === '4' && $six[3] === '0' => 'with a last digit of 4 the fourth digit cannot be 0',
            $six[5] >= '5' && $six[4] === '0' => 'with a last digit of 5 to 9 the fifth digit cannot be 0',
            default => null,
        };

        if ($broken !== null) {
            throw new \InvalidArgumentException(sprintf('%s is not a valid UPC-E: %s', $upcE, $broken));
        }
    }
}
