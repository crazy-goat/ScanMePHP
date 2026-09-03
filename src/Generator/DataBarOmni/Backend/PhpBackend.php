<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBarOmni\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\DataBar\Patterns;
use CrazyGoat\ScanMePHP\Generator\DataBarOmni\DataBarOmniOptions;
use CrazyGoat\ScanMePHP\Generator\Ean\Patterns as CheckDigit;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * GS1 DataBar Omnidirectional in pure PHP.
 *
 * The symbol is a single number, and everything about its shape follows from
 * splitting that number up. The first thirteen digits of the GTIN become a
 * value; the value splits in two around 4537077; each half splits again around
 * 1597 into an outside character and an inside one. Those four values are the
 * symbol — there is no character set, no mode, and nothing to choose.
 *
 * What the layout does with them is worth stating, because it is not the
 * left-to-right run every linear symbology here has been so far:
 *
 *     guard  C1  finder  C2 reversed | mirrored( C3  finder  C4 reversed )  guard
 *
 * The right half is the left half's construction, mirrored whole. That is what
 * makes the symbol readable in either direction, and it is also why an encoder
 * that lays the right half out left to right produces something that scans —
 * as the wrong number. The halves were each measured against an oracle rather
 * than reasoned about.
 *
 * The two finder patterns are not fixed. Their indices are the checksum, split
 * nine ways: the left finder is the quotient and the right one the remainder.
 * So the finders are simultaneously how a scanner locates the symbol and how it
 * checks it, and there is no separate check character anywhere in the bars.
 */
final class PhpBackend implements BackendInterface
{
    /** Digits before the check digit. */
    public const PAYLOAD = 13;

    /** The whole symbol, guards included. */
    public const MODULES = 96;

    /**
     * Height in modules for an omnidirectional scan.
     *
     * GS1 asks for 33X. Below it the symbol still decodes under a beam crossing
     * it squarely, which is exactly the failure worth avoiding: it works on the
     * bench and not at the till.
     */
    public const BAR_HEIGHT = 33;

    /** Height in modules once omnidirectional scanning is given up. */
    public const TRUNCATED_BAR_HEIGHT = 13;

    /**
     * The value the thirteen digits split around, and then the halves again.
     *
     * 4537077 is 2841 x 1597, the outside character's range times the inside
     * one's, so the split is exactly a change of base: a half-value is one
     * outside digit and one inside digit written in mixed radix.
     */
    private const HALF_RANGE = 4537077;

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
        $options = $options instanceof DataBarOmniOptions ? $options : new DataBarOmniOptions();

        $digits = self::normalise($data);
        $value = (int) substr($digits, 0, self::PAYLOAD);

        [$left, $right] = [intdiv($value, self::HALF_RANGE), $value % self::HALF_RANGE];
        $characters = [
            Patterns::character(intdiv($left, Patterns::INSIDE_VALUES), Patterns::OUTSIDE, false),
            Patterns::character($left % Patterns::INSIDE_VALUES, Patterns::INSIDE, true),
            Patterns::character(intdiv($right, Patterns::INSIDE_VALUES), Patterns::OUTSIDE, false),
            Patterns::character($right % Patterns::INSIDE_VALUES, Patterns::INSIDE, true),
        ];

        $checksum = Patterns::checksum(array_merge(...$characters));

        // Nine finders squared is 81 pairs and the checksum has 79 values, so
        // two pairs address nothing. Which two is a decision of the standard
        // rather than a consequence of anything: these two skips are what the
        // oracle's symbols show, across every residue.
        if ($checksum >= 8) {
            $checksum++;
        }
        if ($checksum >= 72) {
            $checksum++;
        }

        $widths = [
            1,
            1,
            ...$characters[0],
            ...Patterns::FINDERS[intdiv($checksum, 9)],
            ...Patterns::mirror($characters[1]),
            ...Patterns::mirror([
                ...$characters[2],
                ...Patterns::FINDERS[$checksum % 9],
                ...Patterns::mirror($characters[3]),
            ]),
            1,
            1,
        ];

        return Symbol::linear(
            modules: Patterns::modules($widths),
            // None. Unlike every other linear symbology here, DataBar's guard
            // patterns do the work a quiet zone does elsewhere, and the
            // standard asks for no margin at all.
            quietZone: QuietZone::none(),
            barHeight: $options->truncated ? self::TRUNCATED_BAR_HEIGHT : self::BAR_HEIGHT,
            text: '(01)' . $digits,
            metadata: [
                'symbology' => Symbology::DataBarOmni->value,
                'checkDigit' => (int) $digits[self::PAYLOAD],
                'checksum' => $checksum,
                'truncated' => $options->truncated,
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
        if (str_starts_with($data, '(01)')) {
            $data = substr($data, 4);
        }

        return CheckDigit::normalise($data, self::PAYLOAD, 'DataBar Omnidirectional');
    }
}
