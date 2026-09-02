<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

/**
 * A 5×7 bitmap font, just large enough to print a barcode's human-readable
 * interpretation.
 *
 * The PNG writer is a hand-rolled 1-bit encoder with no GD and no font engine,
 * which used to mean it had to refuse every symbology that carries text —
 * including EAN-13, whose digits the standard requires. Ten to forty glyphs of
 * embedded bitmap are enough to fix that without taking on a dependency.
 *
 * The glyphs are written as pictures rather than packed bits so a reader can
 * check them by eye; renderGlyphSheet() in the tests prints the whole set.
 * Adding a character is a pure data change: extend GLYPHS and the renderers
 * accept it automatically, because the supported set is reported rather than
 * assumed.
 */
final class BitmapFont
{
    public const WIDTH = 5;

    public const HEIGHT = 7;

    /** Blank columns between adjacent glyphs. */
    public const TRACKING = 1;

    /**
     * One entry per supported character: HEIGHT rows of WIDTH cells, '#' dark.
     *
     * Deliberately covers digits, uppercase letters and the punctuation that
     * turns up in article numbers and SKUs. Lowercase is absent, so a Code 128
     * payload containing it is reported as unprintable instead of being drawn
     * with holes.
     */
    private const GLYPHS = [
        '0' => ['.###.', '#...#', '#..##', '#.#.#', '##..#', '#...#', '.###.'],
        '1' => ['..#..', '.##..', '..#..', '..#..', '..#..', '..#..', '.###.'],
        '2' => ['.###.', '#...#', '....#', '...#.', '..#..', '.#...', '#####'],
        '3' => ['#####', '...#.', '..#..', '...#.', '....#', '#...#', '.###.'],
        '4' => ['...#.', '..##.', '.#.#.', '#..#.', '#####', '...#.', '...#.'],
        '5' => ['#####', '#....', '####.', '....#', '....#', '#...#', '.###.'],
        '6' => ['..##.', '.#...', '#....', '####.', '#...#', '#...#', '.###.'],
        '7' => ['#####', '....#', '...#.', '..#..', '.#...', '.#...', '.#...'],
        '8' => ['.###.', '#...#', '#...#', '.###.', '#...#', '#...#', '.###.'],
        '9' => ['.###.', '#...#', '#...#', '.####', '....#', '...#.', '.##..'],
        'A' => ['..#..', '.#.#.', '#...#', '#...#', '#####', '#...#', '#...#'],
        'B' => ['####.', '#...#', '#...#', '####.', '#...#', '#...#', '####.'],
        'C' => ['.###.', '#...#', '#....', '#....', '#....', '#...#', '.###.'],
        'D' => ['####.', '#...#', '#...#', '#...#', '#...#', '#...#', '####.'],
        'E' => ['#####', '#....', '#....', '####.', '#....', '#....', '#####'],
        'F' => ['#####', '#....', '#....', '####.', '#....', '#....', '#....'],
        'G' => ['.###.', '#...#', '#....', '#.###', '#...#', '#...#', '.###.'],
        'H' => ['#...#', '#...#', '#...#', '#####', '#...#', '#...#', '#...#'],
        'I' => ['.###.', '..#..', '..#..', '..#..', '..#..', '..#..', '.###.'],
        'J' => ['..###', '...#.', '...#.', '...#.', '...#.', '#..#.', '.##..'],
        'K' => ['#...#', '#..#.', '#.#..', '##...', '#.#..', '#..#.', '#...#'],
        'L' => ['#....', '#....', '#....', '#....', '#....', '#....', '#####'],
        'M' => ['#...#', '##.##', '#.#.#', '#.#.#', '#...#', '#...#', '#...#'],
        'N' => ['#...#', '#...#', '##..#', '#.#.#', '#..##', '#...#', '#...#'],
        'O' => ['.###.', '#...#', '#...#', '#...#', '#...#', '#...#', '.###.'],
        'P' => ['####.', '#...#', '#...#', '####.', '#....', '#....', '#....'],
        'Q' => ['.###.', '#...#', '#...#', '#...#', '#.#.#', '#..#.', '.##.#'],
        'R' => ['####.', '#...#', '#...#', '####.', '#.#..', '#..#.', '#...#'],
        'S' => ['.###.', '#...#', '#....', '.###.', '....#', '#...#', '.###.'],
        'T' => ['#####', '..#..', '..#..', '..#..', '..#..', '..#..', '..#..'],
        'U' => ['#...#', '#...#', '#...#', '#...#', '#...#', '#...#', '.###.'],
        'V' => ['#...#', '#...#', '#...#', '#...#', '#...#', '.#.#.', '..#..'],
        'W' => ['#...#', '#...#', '#...#', '#.#.#', '#.#.#', '##.##', '#...#'],
        'X' => ['#...#', '#...#', '.#.#.', '..#..', '.#.#.', '#...#', '#...#'],
        'Y' => ['#...#', '#...#', '.#.#.', '..#..', '..#..', '..#..', '..#..'],
        'Z' => ['#####', '....#', '...#.', '..#..', '.#...', '#....', '#####'],
        ' ' => ['.....', '.....', '.....', '.....', '.....', '.....', '.....'],
        '-' => ['.....', '.....', '.....', '#####', '.....', '.....', '.....'],
        '.' => ['.....', '.....', '.....', '.....', '.....', '.##..', '.##..'],
        '/' => ['....#', '...#.', '...#.', '..#..', '.#...', '.#...', '#....'],
        ':' => ['.....', '.##..', '.##..', '.....', '.##..', '.##..', '.....'],
        '+' => ['.....', '..#..', '..#..', '#####', '..#..', '..#..', '.....'],
        '$' => ['..#..', '.####', '#.#..', '.###.', '..#.#', '####.', '..#..'],
        '%' => ['##..#', '##.#.', '..#..', '.#...', '#..##', '.#.##', '.....'],
        '*' => ['.....', '#.#.#', '.###.', '#####', '.###.', '#.#.#', '.....'],
    ];

    /** @return list<string> Every character this font can print */
    public static function characters(): array
    {
        return array_map(strval(...), array_keys(self::GLYPHS));
    }

    public static function supports(string $text): bool
    {
        return self::missing($text) === [];
    }

    /**
     * The characters of $text this font cannot print, each reported once.
     *
     * @return list<string>
     */
    public static function missing(string $text): array
    {
        $missing = [];
        for ($i = 0, $length = \strlen($text); $i < $length; $i++) {
            $character = $text[$i];
            if (!isset(self::GLYPHS[$character]) && !\in_array($character, $missing, true)) {
                $missing[] = $character;
            }
        }

        return $missing;
    }

    /** Width of $text in font pixels, tracking included. */
    public static function measure(string $text): int
    {
        $length = \strlen($text);

        return $length === 0 ? 0 : $length * self::WIDTH + ($length - 1) * self::TRACKING;
    }

    /**
     * $text as HEIGHT rows of '1'/'0', one character per font pixel.
     *
     * @return list<string>
     * @throws \InvalidArgumentException when a character has no glyph
     */
    public static function rasterise(string $text): array
    {
        $missing = self::missing($text);
        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf(
                'This font has no glyph for: %s',
                implode(' ', array_map(
                    static fn (string $character): string => sprintf('%s (0x%02X)', $character, \ord($character)),
                    $missing
                ))
            ));
        }

        $rows = array_fill(0, self::HEIGHT, '');
        $gap = str_repeat('.', self::TRACKING);

        for ($i = 0, $length = \strlen($text); $i < $length; $i++) {
            $glyph = self::GLYPHS[$text[$i]];
            foreach ($glyph as $line => $cells) {
                $rows[$line] .= ($i > 0 ? $gap : '') . $cells;
            }
        }

        return array_map(static fn (string $row): string => strtr($row, ['#' => '1', '.' => '0']), $rows);
    }
}
