<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Pdf417;

/**
 * The four submodes of PDF417's text compaction, and how to get between them.
 *
 * Text compaction packs two characters into one codeword, so the unit of cost
 * here is half a codeword: a character, a shift, or a latch each take one of
 * the two slots. That is what makes the choice of submode worth optimising
 * rather than guessing — a latch is not free, but neither is spelling a
 * lower-case word out of shifts.
 *
 * The alphabets below were measured, not transcribed. Encoding each printable
 * character on its own and reading the codeword back gives that character's
 * submode and code directly, and all ninety-eight of them agree with the
 * standard's tables. Seven characters — full stop, comma, hyphen, dollar,
 * slash, colon and asterisk — appear in both Mixed and Punctuation, and from
 * Alpha they cost exactly the same either way, which is the first of several
 * places where two encodings of one payload are the same length.
 *
 * The route table is derived rather than written down, because writing it down
 * invites the same error Aztec's did: there is no latch from Lower back to
 * Alpha, only a shift, so getting there to stay costs two latches through
 * Mixed. A reader who assumes symmetry gets it wrong; Dijkstra does not.
 *
 * @internal Shared encoding primitive, not part of the public API.
 */
final class TextSubmodes
{
    public const ALPHA = 0;

    public const LOWER = 1;

    public const MIXED = 2;

    public const PUNCT = 3;

    /**
     * The code that fills the unused half of a codeword.
     *
     * It is the shift to Punctuation, which a reader at the end of the data
     * has nothing left to apply, so it is inert. There is no dedicated filler.
     */
    public const FILLER = 29;

    /** @var array<int, array<string, int>> Submode to character to code */
    private static array $alphabets;

    /** @var array<int, array<int, int>> Submode to submode to latch code */
    private const LATCH = [
        self::ALPHA => [self::LOWER => 27, self::MIXED => 28],
        self::LOWER => [self::MIXED => 28],
        self::MIXED => [self::PUNCT => 25, self::LOWER => 27, self::ALPHA => 28],
        self::PUNCT => [self::ALPHA => 29],
    ];

    /** @var array<int, array<int, int>> Submode to submode to shift code */
    private const SHIFT = [
        self::ALPHA => [self::PUNCT => 29],
        self::LOWER => [self::ALPHA => 27, self::PUNCT => 29],
        self::MIXED => [self::PUNCT => 29],
        self::PUNCT => [],
    ];

    /** @var array<string, list<int>> Cached Dijkstra results */
    private static array $routes = [];

    /** @return array<int, array<string, int>> */
    public static function alphabets(): array
    {
        return self::$alphabets ??= [
            self::ALPHA => self::spell('ABCDEFGHIJKLMNOPQRSTUVWXYZ') + [' ' => 26],
            self::LOWER => self::spell('abcdefghijklmnopqrstuvwxyz') + [' ' => 26],
            self::MIXED => self::spell('0123456789') + [
                '&' => 10, "\r" => 11, "\t" => 12, ',' => 13, ':' => 14,
                '#' => 15, '-' => 16, '.' => 17, '$' => 18, '/' => 19,
                '+' => 20, '%' => 21, '*' => 22, '=' => 23, '^' => 24,
                ' ' => 26,
            ],
            self::PUNCT => [
                ';' => 0, '<' => 1, '>' => 2, '@' => 3, '[' => 4,
                '\\' => 5, ']' => 6, '_' => 7, '`' => 8, '~' => 9,
                '!' => 10, "\r" => 11, "\t" => 12, ',' => 13, ':' => 14,
                "\n" => 15, '-' => 16, '.' => 17, '$' => 18, '/' => 19,
                '"' => 20, '|' => 21, '*' => 22, '(' => 23, ')' => 24,
                '?' => 25, '{' => 26, '}' => 27, "'" => 28,
            ],
        ];
    }

    /** The code for $character in $submode, or null if it has none there. */
    public static function code(int $submode, string $character): ?int
    {
        return self::alphabets()[$submode][$character] ?? null;
    }

    /** Whether text compaction can carry $character at all. */
    public static function isTextual(string $character): bool
    {
        foreach (self::alphabets() as $alphabet) {
            if (isset($alphabet[$character])) {
                return true;
            }
        }

        return false;
    }

    /** The shift code from $from to $to, or null when there is no shift. */
    public static function shift(int $from, int $to): ?int
    {
        return self::SHIFT[$from][$to] ?? null;
    }

    /**
     * The latch codes that move from $from to $to, in order.
     *
     * Empty when already there. Every submode is reachable from every other,
     * but not always in one step, and the shortest route is not always the
     * obvious one.
     *
     * @return list<int>
     */
    public static function latchRoute(int $from, int $to): array
    {
        $key = $from . '>' . $to;
        if (isset(self::$routes[$key])) {
            return self::$routes[$key];
        }

        $best = [$from => []];
        $queue = [$from];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach (self::LATCH[$current] as $next => $code) {
                if (isset($best[$next])) {
                    continue;
                }
                $best[$next] = [...$best[$current], $code];
                $queue[] = $next;
            }
        }

        if (!isset($best[$to])) {
            throw new \LogicException(sprintf('No latch route from submode %d to %d', $from, $to));
        }

        return self::$routes[$key] = $best[$to];
    }

    /** @return array<string, int> */
    private static function spell(string $characters): array
    {
        $codes = [];
        for ($i = 0; $i < \strlen($characters); $i++) {
            $codes[$characters[$i]] = $i;
        }

        return $codes;
    }
}
