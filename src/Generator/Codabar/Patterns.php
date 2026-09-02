<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Codabar;

/**
 * The Codabar table: sixteen data characters and four delimiters.
 *
 * Every character is seven elements — four bars and three spaces — and unlike
 * every other two-width symbology here the number of wide elements is not
 * constant. Digits, '-' and '$' have two; ':', '/', '.', '+' and all four
 * delimiters have three. So characters are not all the same width, and a
 * symbol's width is not its character count times anything.
 *
 * The table was read out of zxing-cpp rather than transcribed, for the reason
 * every other table in this library was: a swapped row gives a symbol that
 * still scans, as different data.
 */
final class Patterns
{
    /** The sixteen data characters, in the order the standard numbers them. */
    public const CHARACTERS = '0123456789-$:/.+';

    /** The four delimiters, which are patterns but not data. */
    public const DELIMITERS = 'ABCD';

    /**
     * Element widths per character, read bar-space-bar-space-bar-space-bar.
     *
     * Keyed by the character, but PHP narrows the ten numeric keys to int, so
     * read this through pattern() rather than indexing it: the accessor is
     * what keeps the key type from leaking into every caller.
     *
     * @var array<int|string, string>
     */
    public const PATTERNS = [
        '0' => 'nnnnnww',
        '1' => 'nnnnwwn',
        '2' => 'nnnwnnw',
        '3' => 'wwnnnnn',
        '4' => 'nnwnnwn',
        '5' => 'wnnnnwn',
        '6' => 'nwnnnnw',
        '7' => 'nwnnwnn',
        '8' => 'nwwnnnn',
        '9' => 'wnnwnnn',
        '-' => 'nnnwwnn',
        '$' => 'nnwwnnn',
        ':' => 'wnnnwnw',
        '/' => 'wnwnnnw',
        '.' => 'wnwnwnn',
        '+' => 'nnwnwnw',
        'A' => 'nnwwnwn',
        'B' => 'nwnwnnw',
        'C' => 'nnnwnww',
        'D' => 'nnnwwwn',
    ];

    /** Elements per character: four bars and three spaces. */
    public const ELEMENTS = 7;

    /** One narrow space between characters. */
    public const INTER_CHARACTER_GAP = 1;

    /** Minimum quiet zone either side, in narrow modules. */
    public const QUIET_ZONE = 10;

    /** A legible default; the standard states height as a fraction of length. */
    public const BAR_HEIGHT = 50;

    /** Whether every character of $data is a Codabar data character. */
    public static function isEncodable(string $data): bool
    {
        return $data !== '' && strspn($data, self::CHARACTERS) === \strlen($data);
    }

    /**
     * Bars and spaces for a delimited character sequence.
     *
     * The symbol ends on a bar, as every character does: there is no stop
     * pattern beyond the closing delimiter, and no trailing gap.
     *
     * @throws \InvalidArgumentException on a character with no pattern
     */
    public static function modules(string $characters, int $wideRatio): string
    {
        $modules = '';

        for ($position = 0, $length = \strlen($characters); $position < $length; $position++) {
            if ($position > 0) {
                $modules .= str_repeat('0', self::INTER_CHARACTER_GAP);
            }

            $modules .= self::elements(self::pattern($characters[$position]), $wideRatio);
        }

        return $modules;
    }

    /** The module width of a delimited character sequence. */
    public static function width(string $characters, int $wideRatio): int
    {
        $width = (\strlen($characters) - 1) * self::INTER_CHARACTER_GAP;

        for ($position = 0, $length = \strlen($characters); $position < $length; $position++) {
            $wide = substr_count(self::pattern($characters[$position]), 'w');
            $width += (self::ELEMENTS - $wide) + $wide * $wideRatio;
        }

        return $width;
    }

    /**
     * The element widths of one character, data or delimiter.
     *
     * @throws \InvalidArgumentException on a character with no pattern
     */
    public static function pattern(string $character): string
    {
        return self::PATTERNS[$character] ?? throw new \InvalidArgumentException(
            sprintf('Not a Codabar character: %s', $character)
        );
    }

    /** Every character with a pattern, data characters then delimiters. */
    public static function everyCharacter(): string
    {
        return self::CHARACTERS . self::DELIMITERS;
    }

    /** One seven-element pattern as '1'/'0' modules, starting with a bar. */
    private static function elements(string $pattern, int $wideRatio): string
    {
        $modules = '';
        for ($index = 0; $index < self::ELEMENTS; $index++) {
            $modules .= str_repeat(
                $index % 2 === 0 ? '1' : '0',
                $pattern[$index] === 'w' ? $wideRatio : 1
            );
        }

        return $modules;
    }
}
