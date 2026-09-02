<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Ean;

/**
 * The module patterns and check-digit arithmetic shared by the EAN/UPC family.
 *
 * EAN-13, EAN-8, UPC-A and UPC-E are four layouts over one set of tables
 * (ISO/IEC 15420). Keeping the tables in one place is not tidiness: a
 * transcription slip in a parity table produces a symbol that still looks like
 * a barcode and still scans — as some other article number. One copy means one
 * thing to verify, and EanReferenceTest verifies it against patterns written
 * by an independent encoder rather than against the standard we read.
 *
 * @internal Shared by the EAN/UPC generators.
 */
final class Patterns
{
    /** Odd-parity digits, drawn at left-hand positions marked L. */
    public const LEFT_ODD = [
        '0001101', '0011001', '0010011', '0111101', '0100011',
        '0110001', '0101111', '0111011', '0110111', '0001011',
    ];

    /** Even-parity digits, drawn at left-hand positions marked G. */
    public const LEFT_EVEN = [
        '0100111', '0110011', '0011011', '0100001', '0011101',
        '0111001', '0000101', '0010001', '0001001', '0010111',
    ];

    /** Right-hand digits: the bitwise complement of LEFT_ODD. */
    public const RIGHT = [
        '1110010', '1100110', '1101100', '1000010', '1011100',
        '1001110', '1010000', '1000100', '1001000', '1110100',
    ];

    public const START_GUARD = '101';

    public const CENTRE_GUARD = '01010';

    public const END_GUARD = '101';

    /** UPC-E has no centre guard; its trailing guard is six modules wide. */
    public const UPCE_END_GUARD = '010101';

    /**
     * Nominal bar height at magnification 1, in modules, with the guards
     * descending a further five so the human-readable digits sit between them.
     */
    public const BAR_HEIGHT = 64;

    public const GUARD_DESCENT = 5;

    /**
     * Weighted modulo 10 over the payload, weight 3 on the rightmost digit and
     * alternating leftwards.
     *
     * Written from the right rather than the left because that is what makes
     * it one function for all four members: EAN-13 and UPC-A have an
     * even-length payload and start at weight 1, EAN-8 and the eleven digits
     * behind a UPC-E start at weight 3.
     */
    public static function checkDigit(string $payload): int
    {
        $sum = 0;
        $last = \strlen($payload) - 1;
        for ($position = 0; $position <= $last; $position++) {
            $sum += (int) $payload[$position] * (($last - $position) % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    /**
     * The payload plus its check digit, verifying one that was already given.
     *
     * A caller passing the check digit is asserting a specific article number,
     * so a wrong one is rejected rather than silently corrected: quietly
     * encoding a different product would be worse than failing.
     *
     * @param int $payloadLength Digits before the check digit
     * @throws \InvalidArgumentException when the input is not encodable
     */
    public static function normalise(string $data, int $payloadLength, string $symbology): string
    {
        if (preg_match('/^\d{' . $payloadLength . ',' . ($payloadLength + 1) . '}$/', $data) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                '%s needs %d or %d digits, got: %s',
                $symbology,
                $payloadLength,
                $payloadLength + 1,
                $data
            ));
        }

        $payload = substr($data, 0, $payloadLength);
        $check = self::checkDigit($payload);

        if (\strlen($data) === $payloadLength + 1 && (int) $data[$payloadLength] !== $check) {
            throw new \InvalidArgumentException(sprintf(
                '%s check digit for %s must be %d, got %s',
                $symbology,
                $payload,
                $check,
                $data[$payloadLength]
            ));
        }

        return $payload . $check;
    }

    /** Whether $data is $payloadLength digits, or one more with a right check digit. */
    public static function accepts(string $data, int $payloadLength): bool
    {
        if (preg_match('/^\d{' . $payloadLength . ',' . ($payloadLength + 1) . '}$/', $data) !== 1) {
            return false;
        }

        return \strlen($data) === $payloadLength
            || (int) $data[$payloadLength] === self::checkDigit(substr($data, 0, $payloadLength));
    }

    /**
     * A row of descender modules matching the guards of a bar row.
     *
     * The descent travels as a second module row with its own height, so the
     * grid stays a plain two-level bitmap and every renderer draws the
     * guard extensions without knowing what a guard is.
     *
     * @param list<array{int, string}> $guards Module offset and pattern of each
     */
    public static function descenderRow(int $width, array $guards): string
    {
        $row = str_repeat('0', $width);
        foreach ($guards as [$offset, $guard]) {
            $row = substr_replace($row, $guard, $offset, \strlen($guard));
        }

        return $row;
    }
}
