<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code39;

/**
 * The Code 39 tables: 43 characters, the start/stop bar pattern, the modulo-43
 * check character and the full-ASCII escape sequences.
 *
 * Every pattern here is nine elements — five bars and four spaces — of which
 * exactly three are wide. That invariant is what makes Code 39
 * self-checking-ish and what a scanner uses to reject a smudge, so the tables
 * are asserted against it in the tests rather than merely trusted.
 *
 * The patterns and the escape table were both read out of zxing-cpp rather
 * than transcribed from ISO/IEC 16388, for the same reason the EAN parity
 * tables were: a swapped row produces a symbol that still scans, as different
 * data. tests/fixtures/code39_reference.csv is the frozen result.
 */
final class Charset
{
    /**
     * The 43 characters, in symbol-value order — which is also the order the
     * modulo-43 check character is computed in, so the index is the value.
     */
    public const CHARACTERS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-. $/+%';

    /** Narrow and wide elements, indexed by symbol value. */
    private const PATTERNS = [
        'nnnwwnwnn', 'wnnwnnnnw', 'nnwwnnnnw', 'wnwwnnnnn', 'nnnwwnnnw', 'wnnwwnnnn', 'nnwwwnnnn', 'nnnwnnwnw',
        'wnnwnnwnn', 'nnwwnnwnn', 'wnnnnwnnw', 'nnwnnwnnw', 'wnwnnwnnn', 'nnnnwwnnw', 'wnnnwwnnn', 'nnwnwwnnn',
        'nnnnnwwnw', 'wnnnnwwnn', 'nnwnnwwnn', 'nnnnwwwnn', 'wnnnnnnww', 'nnwnnnnww', 'wnwnnnnwn', 'nnnnwnnww',
        'wnnnwnnwn', 'nnwnwnnwn', 'nnnnnnwww', 'wnnnnnwwn', 'nnwnnnwwn', 'nnnnwnwwn', 'wwnnnnnnw', 'nwwnnnnnw',
        'wwwnnnnnn', 'nwnnwnnnw', 'wwnnwnnnn', 'nwwnwnnnn', 'nwnnnnwnw', 'wwnnnnwnn', 'nwwnnnwnn', 'nwnwnwnnn',
        'nwnwnnnwn', 'nwnnnwnwn', 'nnnwnwnwn',
    ];

    /**
     * The start and stop character, drawn identically at both ends.
     *
     * It is conventionally written '*', and '*' is therefore not encodable as
     * data: a payload containing one would end the symbol early. In extended
     * mode it is reachable as '/J'.
     */
    public const START_STOP = 'nwnnwnwnn';

    /** Check character modulus, i.e. the size of the character set. */
    public const CHECK_MODULUS = 43;

    /**
     * One narrow element between characters. The standard allows it to be
     * wider; keeping it at one narrow module is what every reference encoder
     * does and what the fixture was generated with.
     */
    public const INTER_CHARACTER_GAP = 1;

    /** Minimum quiet zone either side, in narrow modules (ISO/IEC 16388 §4.5). */
    public const QUIET_ZONE = 10;

    /**
     * Default bar height in modules. Code 39 has no fixed height — the
     * standard states it as a fraction of symbol length — so this is a legible
     * default for render options to override.
     */
    public const BAR_HEIGHT = 50;

    /**
     * Every ASCII byte as the Code 39 characters that stand for it.
     *
     * Forty-three bytes are characters in their own right and appear as
     * themselves; the other eighty-five are two characters, a shift out of
     * '$', '/', '%' or '+' followed by a letter. Note what that means for the
     * shift characters themselves: '$' is not encoded as '$' in extended mode
     * but as '/D', because a literal '$' would be read as the start of an
     * escape. Passing data through unshifted is the mistake this table exists
     * to prevent.
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
        ' ', '/A', '/B', '/C', '/D', '/E', '/F', '/G',
        // 40
        '/H', '/I', '/J', '/K', '/L', '-', '.', '/O',
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

    /** Whether every byte of $data is a Code 39 character in its own right. */
    public static function isEncodable(string $data): bool
    {
        return strspn($data, self::CHARACTERS) === \strlen($data);
    }

    /** Whether every byte of $data has an escape sequence, i.e. is ASCII. */
    public static function isEncodableExtended(string $data): bool
    {
        for ($position = 0, $length = \strlen($data); $position < $length; $position++) {
            if (\ord($data[$position]) > 127) {
                return false;
            }
        }

        return true;
    }

    /**
     * $data as the sequence of Code 39 characters that encodes it.
     *
     * The result is longer than the input for anything outside the 43, which
     * is why a payload's byte count says nothing about its symbol width in
     * extended mode.
     *
     * @throws \InvalidArgumentException on a byte above 127, which has no
     *         Code 39 representation at all
     */
    public static function toExtended(string $data): string
    {
        $encoded = '';
        for ($position = 0, $length = \strlen($data); $position < $length; $position++) {
            $byte = \ord($data[$position]);
            if ($byte > 127) {
                throw new \InvalidArgumentException(sprintf(
                    'Code 39 Extended covers ASCII only; byte %d at position %d has no representation',
                    $byte,
                    $position
                ));
            }

            $encoded .= self::EXTENDED[$byte];
        }

        return $encoded;
    }

    /** The symbol value of one character, for the check calculation. */
    public static function symbolValue(string $character): int
    {
        $value = strpos(self::CHARACTERS, $character);
        if ($value === false) {
            throw new \InvalidArgumentException(sprintf(
                'Not a Code 39 character: %s (accepts %s)',
                $character,
                self::CHARACTERS
            ));
        }

        return $value;
    }

    /**
     * The modulo-43 check character for an already-encoded character sequence.
     *
     * Unweighted, unlike every other check in this library: Code 39 sums the
     * symbol values and takes the remainder, so a transposition of two
     * characters does not change it. That is a known weakness of the
     * symbology, not of this implementation.
     */
    public static function checkCharacter(string $encoded): string
    {
        $sum = 0;
        for ($position = 0, $length = \strlen($encoded); $position < $length; $position++) {
            $sum += self::symbolValue($encoded[$position]);
        }

        return self::CHARACTERS[$sum % self::CHECK_MODULUS];
    }

    /**
     * Bars and spaces for an encoded character sequence, guards included.
     *
     * @param int $wideRatio How many modules a wide element spans
     */
    public static function modules(string $encoded, int $wideRatio): string
    {
        $gap = str_repeat('0', self::INTER_CHARACTER_GAP);
        $modules = self::elements(self::START_STOP, $wideRatio);

        for ($position = 0, $length = \strlen($encoded); $position < $length; $position++) {
            $modules .= $gap . self::elements(
                self::PATTERNS[self::symbolValue($encoded[$position])],
                $wideRatio
            );
        }

        return $modules . $gap . self::elements(self::START_STOP, $wideRatio);
    }

    /** The module width of a symbol carrying $characters characters, guards included. */
    public static function width(int $characters, int $wideRatio): int
    {
        $total = $characters + 2;

        return $total * (6 + 3 * $wideRatio) + ($total - 1) * self::INTER_CHARACTER_GAP;
    }

    /** One nine-element pattern as '1'/'0' modules, starting with a bar. */
    private static function elements(string $pattern, int $wideRatio): string
    {
        $modules = '';
        for ($index = 0; $index < 9; $index++) {
            $modules .= str_repeat(
                $index % 2 === 0 ? '1' : '0',
                $pattern[$index] === 'w' ? $wideRatio : 1
            );
        }

        return $modules;
    }
}
