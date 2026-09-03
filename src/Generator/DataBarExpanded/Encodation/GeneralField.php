<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBarExpanded\Encodation;

/**
 * The general purpose field: GS1 data compacted into a bit string.
 *
 * Three modes, and the whole difficulty is deciding when to leave one.
 *
 *   numeric        two digits in seven bits, as 11 x d1 + d2 + 8
 *   alphanumeric   digits in five bits, capitals and five punctuation marks in six
 *   ISO 646        digits in five, capitals in seven, lowercase in seven, the
 *                  rest of the printable set in eight
 *
 * Numeric is the cheapest thing that can be said and the mode the field opens
 * in; ISO 646 is the only one that can say a lowercase letter. A latch costs
 * three to five bits, so switching for a short run loses more than it saves,
 * and the standard settles that with look-ahead thresholds rather than by
 * costing the alternatives. The thresholds are asymmetric in a way no amount of
 * reasoning about cost predicts — leaving ISO 646 for a digit run takes ten
 * digits mid-field but only four when what follows the digits could be said in
 * alphanumeric — so every one of them was measured against an encoder we did
 * not write, and {@see \CrazyGoat\ScanMePHP\Tests\DataBarExpandedReferenceTest}
 * is what says they are right.
 *
 * Two rules are worth naming because they are not thresholds and getting them
 * wrong is silent:
 *
 *   * An FNC1 emitted from alphanumeric or ISO 646 mode *returns the field to
 *     numeric mode*. It is not a character that leaves the mode alone.
 *   * A digit that is the last thing in the field may be written in four bits
 *     as d + 1 instead of seven. It is only worth doing when it saves a whole
 *     symbol character, and the standard's encoders do it exactly then.
 *
 * @internal
 */
final class GeneralField
{
    /** What a scanner transmits for the FNC1 that ends a variable-length element. */
    public const FNC1 = "\x1d";

    /** Punctuation alphanumeric mode can say, at 58 upwards. */
    private const ALNUM_PUNCTUATION = '*,-./';

    /** The printable set only ISO 646 mode can say, at 232 upwards. */
    private const ISO_PUNCTUATION = '!"%&\'()*+,-./:;<=>?_ ';

    private function __construct(
        /** Everything but a trailing lone digit. */
        public readonly string $bits,
        /** Whether the field is left in numeric mode, which changes the padding. */
        public readonly bool $endsNumeric,
        /** A digit the caller may write in four bits or seven, or null. */
        public readonly ?int $finalDigit,
    ) {
    }

    /** Whether every byte of $data is in the character set this field can say. */
    public static function accepts(string $data): bool
    {
        $length = \strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $byte = $data[$i];
            if ($byte === self::FNC1
                || ctype_digit($byte)
                || ctype_alpha($byte)
                || str_contains(self::ISO_PUNCTUATION, $byte)
            ) {
                continue;
            }

            return false;
        }

        return true;
    }

    public static function encode(string $data): self
    {
        $bits = '';
        $numeric = true;
        $iso = false;
        $position = 0;
        $length = \strlen($data);

        while ($position < $length) {
            $byte = $data[$position];

            if ($numeric) {
                $next = $position + 1 < $length ? $data[$position + 1] : null;

                if (self::isNumeric($byte) && $next !== null && self::isNumeric($next)) {
                    $bits .= self::field(11 * self::digit($byte) + self::digit($next) + 8, 7);
                    $position += 2;

                    continue;
                }

                if (self::isNumeric($byte) && $next === null) {
                    // A lone final digit is the one place the field has a choice
                    // of widths; the FNC1 has no four-bit form, so it is written
                    // out here with the missing digit standing in as ten.
                    if ($byte !== self::FNC1) {
                        return new self($bits, true, (int) $byte);
                    }

                    $bits .= self::field(11 * self::digit($byte) + 18, 7);
                    $position++;

                    continue;
                }

                $bits .= '0000';
                $numeric = false;
                $iso = false;

                continue;
            }

            if ($byte === self::FNC1) {
                $bits .= self::field(15, 5);
                $position++;
                $numeric = true;

                continue;
            }

            $numericRun = self::runOf($data, $position, static fn (string $c): bool => self::isNumeric($c));

            if (!$iso) {
                if ($numericRun >= 6 || ($numericRun >= 4 && $position + $numericRun >= $length)) {
                    $bits .= '000';
                    $numeric = true;

                    continue;
                }

                if (ctype_digit($byte)) {
                    $bits .= self::field((int) $byte + 5, 5);
                    $position++;

                    continue;
                }

                if ($byte >= 'A' && $byte <= 'Z') {
                    $bits .= self::field(32 + \ord($byte) - \ord('A'), 6);
                    $position++;

                    continue;
                }

                $punctuation = strpos(self::ALNUM_PUNCTUATION, $byte);
                if ($punctuation !== false) {
                    $bits .= self::field(58 + $punctuation, 6);
                    $position++;

                    continue;
                }

                $bits .= self::field(4, 5);
                $iso = true;

                continue;
            }

            if (self::leavesIsoForNumeric($data, $position, $numericRun)) {
                $bits .= '000';
                $numeric = true;

                continue;
            }

            $alnumRun = self::runOf($data, $position, static fn (string $c): bool => self::isAlphanumeric($c));
            if ($alnumRun >= 10 || ($alnumRun >= 5 && $position + $alnumRun >= $length)) {
                $bits .= self::field(4, 5);
                $iso = false;

                continue;
            }

            if (ctype_digit($byte)) {
                $bits .= self::field((int) $byte + 5, 5);
            } elseif ($byte >= 'A' && $byte <= 'Z') {
                $bits .= self::field(64 + \ord($byte) - \ord('A'), 7);
            } elseif ($byte >= 'a' && $byte <= 'z') {
                $bits .= self::field(90 + \ord($byte) - \ord('a'), 7);
            } else {
                $punctuation = strpos(self::ISO_PUNCTUATION, $byte);
                if ($punctuation === false) {
                    throw new \InvalidArgumentException(sprintf(
                        'DataBar Expanded cannot encode the character %s',
                        var_export($byte, true)
                    ));
                }

                $bits .= self::field(232 + $punctuation, 8);
            }

            $position++;
        }

        return new self($bits, $numeric, null);
    }

    /** The bits, with the final digit written in whichever width $spare allows. */
    public function body(int $spare): string
    {
        if ($this->finalDigit === null) {
            return $this->bits;
        }

        return $this->bits . ($spare >= 7
            ? self::field(11 * $this->finalDigit + 18, 7)
            : self::field($this->finalDigit + 1, 4));
    }

    /** The shortest the field can be written. */
    public function shortestLength(): int
    {
        return \strlen($this->bits) + ($this->finalDigit === null ? 0 : 4);
    }

    /**
     * Whether ISO 646 mode gives way to numeric here.
     *
     * Three separate conditions, none of which subsumes the others, and the
     * middle one is why they cannot be collapsed: four digits are enough when
     * everything after them could be said in alphanumeric mode, because the
     * field will not have to come back to ISO 646 at all.
     */
    private static function leavesIsoForNumeric(string $data, int $position, int $numericRun): bool
    {
        if ($numericRun >= 10) {
            return true;
        }

        $length = \strlen($data);

        if ($numericRun >= 4) {
            $alphanumericTail = true;
            for ($i = $position + $numericRun; $i < $length; $i++) {
                if (!self::isAlphanumeric($data[$i])) {
                    $alphanumericTail = false;
                    break;
                }
            }

            if ($alphanumericTail) {
                return true;
            }
        }

        $digitRun = self::runOf($data, $position, static fn (string $c): bool => ctype_digit($c));
        if ($digitRun < 4) {
            return false;
        }

        $after = $position + $digitRun < $length ? $data[$position + $digitRun] : null;

        return $after === null || ($after !== self::FNC1 && self::isAlphanumeric($after));
    }

    /** @param callable(string): bool $predicate */
    private static function runOf(string $data, int $position, callable $predicate): int
    {
        $run = 0;
        $length = \strlen($data);
        while ($position + $run < $length && $predicate($data[$position + $run])) {
            $run++;
        }

        return $run;
    }

    private static function isNumeric(string $byte): bool
    {
        return $byte === self::FNC1 || ctype_digit($byte);
    }

    private static function isAlphanumeric(string $byte): bool
    {
        return $byte === self::FNC1
            || ctype_digit($byte)
            || ($byte >= 'A' && $byte <= 'Z')
            || str_contains(self::ALNUM_PUNCTUATION, $byte);
    }

    /** The FNC1 counts as the digit ten, which is what the base of eleven is for. */
    private static function digit(string $byte): int
    {
        return $byte === self::FNC1 ? 10 : (int) $byte;
    }

    private static function field(int $value, int $bits): string
    {
        return str_pad(decbin($value), $bits, '0', \STR_PAD_LEFT);
    }
}
