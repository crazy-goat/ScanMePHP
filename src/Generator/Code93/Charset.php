<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code93;

/**
 * The Code 93 tables: 43 data characters, 4 shift characters, the guard, and
 * the two mandatory check characters.
 *
 * Every pattern is nine modules of three bars and three spaces, and every one
 * of them starts on a bar and ends on a space — which is why characters butt
 * together with no inter-character gap. Nine modules a character against Code
 * 39's thirteen is where the density comes from, though the two mandatory
 * check characters eat into it on short payloads: 81% of the Code 39 width at
 * eleven characters, 72% at fifty-nine.
 *
 * The tables were read out of zxing-cpp rather than transcribed from ANSI/AIM
 * BC5, for the reason the EAN parity and Code 39 tables were: a swapped row
 * gives a symbol that still scans, as different data.
 * tests/fixtures/code93_reference.csv is the frozen result.
 */
final class Charset
{
    /** The 43 data characters, in symbol-value order. */
    public const CHARACTERS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%';

    /**
     * The four shift characters, at symbol values 43 to 46.
     *
     * They are written ($), (%), (/) and (+) precisely because they are *not*
     * the data characters of those names, which sit at values 39 to 42 with
     * patterns of their own. That distinction is the whole difference between
     * this symbology and Code 39 Extended: there, a shift is spelled with a
     * data character and 'A$B' is ambiguous; here the shift has its own bars
     * and 'A$B' can only be read one way.
     */
    public const SHIFTS = '$%/+';

    /** Symbol value of the first shift character. */
    private const FIRST_SHIFT = 43;

    /** Nine modules per character, indexed by symbol value 0 to 42. */
    private const PATTERNS = [
        '100010100', '101001000', '101000100', '101000010', '100101000', '100100100', '100100010', '101010000',
        '100010010', '100001010', '110101000', '110100100', '110100010', '110010100', '110010010', '110001010',
        '101101000', '101100100', '101100010', '100110100', '100011010', '101011000', '101001100', '101000110',
        '100101100', '100010110', '110110100', '110110010', '110101100', '110100110', '110010110', '110011010',
        '101101100', '101100110', '100110110', '100111010', '100101110', '111010100', '111010010', '111001010',
        '101101110', '101110110', '110101110',
    ];

    /** Patterns for symbol values 43 to 46, in SHIFTS order. */
    private const SHIFT_PATTERNS = [
        '100100110', '111011010', '111010110', '100110010',
    ];

    /** Drawn at both ends; the closing one is followed by a terminator bar. */
    public const GUARD = '101011110';

    /**
     * The single module that closes the symbol.
     *
     * Every character pattern ends on a space, so without it the last guard
     * would run into the quiet zone and the symbol would have no defined end.
     */
    public const TERMINATOR = '1';

    /** Symbol values, i.e. the modulus of both check characters. */
    public const CHECK_MODULUS = 47;

    /** Weight cycle of the C check character, counting from the right. */
    public const CHECK_C_WEIGHTS = 20;

    /** And of K, which is computed over the data with C already appended. */
    public const CHECK_K_WEIGHTS = 15;

    /** Minimum quiet zone either side, in modules. */
    public const QUIET_ZONE = 10;

    /** A legible default; the standard states height as a fraction of length. */
    public const BAR_HEIGHT = 50;

    /**
     * Every ASCII byte as the Code 93 characters that stand for it.
     *
     * A one-character entry is that data character; a two-character entry is a
     * shift followed by a letter. Forty-three bytes are characters in their own
     * right — including '$', '%', '/' and '+', which Code 39 Extended has to
     * escape and this symbology does not.
     *
     * @var list<string>
     */
    private const EXTENDED = [
        // 0
        '%U', '$A', '$B', '$C', '$D', '$E', '$F', '$G',
        // 8
        '$H', '$I', '$J', '$K', '$L', '$M', '$N', '$O',
        // 16
        '$P', '$Q', '$R', '$S', '$T', '$U', '$V', '$W',
        // 24
        '$X', '$Y', '$Z', '%A', '%B', '%C', '%D', '%E',
        // 32
        ' ', '/A', '/B', '/C', '$', '%', '/F', '/G',
        // 40
        '/H', '/I', '/J', '+', '/L', '-', '.', '/',
        // 48
        '0', '1', '2', '3', '4', '5', '6', '7',
        // 56
        '8', '9', '/Z', '%F', '%G', '%H', '%I', '%J',
        // 64
        '%V', 'A', 'B', 'C', 'D', 'E', 'F', 'G',
        // 72
        'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O',
        // 80
        'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W',
        // 88
        'X', 'Y', 'Z', '%K', '%L', '%M', '%N', '%O',
        // 96
        '%W', '+A', '+B', '+C', '+D', '+E', '+F', '+G',
        // 104
        '+H', '+I', '+J', '+K', '+L', '+M', '+N', '+O',
        // 112
        '+P', '+Q', '+R', '+S', '+T', '+U', '+V', '+W',
        // 120
        '+X', '+Y', '+Z', '%P', '%Q', '%R', '%S', '%T',
    ];

    /** Whether every byte of $data is ASCII, which is all Code 93 carries. */
    public static function isEncodable(string $data): bool
    {
        for ($position = 0, $length = \strlen($data); $position < $length; $position++) {
            if (\ord($data[$position]) > 127) {
                return false;
            }
        }

        return true;
    }

    /**
     * The symbol values for $data, check characters included.
     *
     * @return list<int>
     *
     * @throws \InvalidArgumentException on a byte above 127
     */
    public static function symbolValues(string $data): array
    {
        $values = [];

        for ($position = 0, $length = \strlen($data); $position < $length; $position++) {
            $byte = \ord($data[$position]);
            if ($byte > 127) {
                throw new \InvalidArgumentException(sprintf(
                    'Code 93 covers ASCII only; byte %d at position %d has no representation',
                    $byte,
                    $position
                ));
            }

            $encoded = self::EXTENDED[$byte];
            if (\strlen($encoded) === 2) {
                $values[] = self::FIRST_SHIFT + strpos(self::SHIFTS, $encoded[0]);
                $values[] = strpos(self::CHARACTERS, $encoded[1]);

                continue;
            }

            $values[] = strpos(self::CHARACTERS, $encoded);
        }

        // C first, then K over the data with C already on the end. Both are
        // mandatory — unlike Code 39 there is nothing to opt into, and a
        // scanner verifies them, so a wrong one is an unreadable symbol rather
        // than a spurious trailing character.
        $values[] = self::checkValue($values, self::CHECK_C_WEIGHTS);
        $values[] = self::checkValue($values, self::CHECK_K_WEIGHTS);

        return $values;
    }

    /**
     * A weighted sum modulo 47, weights running 1, 2, 3… from the rightmost
     * character and starting over once $weights is reached.
     *
     * The cycle is what makes this stronger than Code 39's unweighted sum: a
     * transposition changes the result, and the two characters use different
     * cycle lengths so a single error cannot satisfy both.
     *
     * @param list<int> $values
     */
    public static function checkValue(array $values, int $weights): int
    {
        $sum = 0;
        $position = 0;

        for ($index = \count($values) - 1; $index >= 0; $index--, $position++) {
            $sum += $values[$index] * ($position % $weights + 1);
        }

        return $sum % self::CHECK_MODULUS;
    }

    /**
     * A symbol value written the way the standard writes it.
     *
     * Forty-three of the 47 values are data characters and name themselves.
     * The other four are shifts, which have no printable form and are written
     * ($), (%), (/) and (+) — deliberately not as the bare characters, since
     * those are different values with different bars.
     *
     * Both check characters are ordinary symbol values, so either can land on
     * a shift: 'ABCDEFGHIJKLMNOPQRST' has C = (+). Anything reporting a check
     * character has to be able to say so.
     */
    public static function characterName(int $value): string
    {
        if ($value < 0 || $value >= self::CHECK_MODULUS) {
            throw new \InvalidArgumentException(sprintf(
                'Not a Code 93 symbol value: %d (0 to %d)',
                $value,
                self::CHECK_MODULUS - 1
            ));
        }

        return $value < self::FIRST_SHIFT
            ? self::CHARACTERS[$value]
            : '(' . self::SHIFTS[$value - self::FIRST_SHIFT] . ')';
    }

    /** The pattern for one symbol value, data characters and shifts alike. */
    public static function pattern(int $value): string
    {
        return $value < self::FIRST_SHIFT
            ? self::PATTERNS[$value]
            : self::SHIFT_PATTERNS[$value - self::FIRST_SHIFT];
    }

    /**
     * Bars and spaces for a list of symbol values, guards included.
     *
     * @param list<int> $values
     */
    public static function modules(array $values): string
    {
        $modules = self::GUARD;
        foreach ($values as $value) {
            $modules .= self::pattern($value);
        }

        return $modules . self::GUARD . self::TERMINATOR;
    }

    /** The module width of a symbol carrying $characters characters, guards included. */
    public static function width(int $characters): int
    {
        // No inter-character gap: every pattern opens on a bar and closes on a
        // space, so they butt together.
        return \strlen(self::GUARD) * 2 + $characters * 9 + \strlen(self::TERMINATOR);
    }
}
