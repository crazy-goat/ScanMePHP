<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Aztec;

/**
 * Aztec's five character modes, and how to get from one to another.
 *
 * The tables below are ISO/IEC 24778:2008 Table 2, transcribed once. Everything
 * else — which characters need which mode, what a shift costs, how many bits it
 * takes to reach Upper from Lower — is derived from them, because the derived
 * facts are the ones easy to get subtly wrong. Lower has no latch to Upper at
 * all, for instance: the cheapest route is Lower to Digit (five bits) and then
 * Digit to Upper (four), which is nine, and not the ten a reader would guess
 * from counting two five-bit latches. That number falls out of a search here
 * rather than being written down and trusted.
 *
 * Punct is the odd one. Four of its codes stand for *two* characters — a full
 * stop and a space, a comma and a space, a colon and a space, and a carriage
 * return followed by a line feed — which is why the encoder has to consider
 * two-character steps and not just one.
 *
 * @internal Part of the Aztec encoding pipeline.
 */
final class CharacterModes
{
    public const UPPER = 0;
    public const LOWER = 1;
    public const MIXED = 2;
    public const PUNCT = 3;
    public const DIGIT = 4;

    public const MODES = [self::UPPER, self::LOWER, self::MIXED, self::PUNCT, self::DIGIT];

    /** Digit codes are four bits; every other mode's are five. */
    public const WIDTH = [
        self::UPPER => 5,
        self::LOWER => 5,
        self::MIXED => 5,
        self::PUNCT => 5,
        self::DIGIT => 4,
    ];

    /** The code that opens a binary shift, for the modes that have one. */
    public const BINARY_SHIFT = [
        self::UPPER => 31,
        self::LOWER => 31,
        self::MIXED => 31,
    ];

    /**
     * Single-character latches: [from][to] = code in the *from* mode.
     *
     * Deliberately only the one-step moves. Multi-step routes are searched for
     * in {@see latchRoute()}.
     */
    private const LATCH = [
        self::UPPER => [self::LOWER => 28, self::MIXED => 29, self::DIGIT => 30],
        self::LOWER => [self::MIXED => 29, self::DIGIT => 30],
        self::MIXED => [self::LOWER => 28, self::UPPER => 29, self::PUNCT => 30],
        self::PUNCT => [self::UPPER => 31],
        self::DIGIT => [self::UPPER => 14],
    ];

    /** Shifts, good for exactly one code: [from][to] = code in the from mode. */
    private const SHIFT = [
        self::UPPER => [self::PUNCT => 0],
        self::LOWER => [self::PUNCT => 0, self::UPPER => 28],
        self::MIXED => [self::PUNCT => 0],
        self::PUNCT => [],
        self::DIGIT => [self::PUNCT => 0, self::UPPER => 15],
    ];

    /** @var array<int, array<int, int>>|null byte => [mode => code] */
    private static ?array $single = null;

    /** @var array<string, int>|null two-byte sequence => Punct code */
    private static ?array $pairs = null;

    /** @var array<int, array<int, array{int, list<array{int, int}>}>>|null */
    private static ?array $routes = null;

    /**
     * Every mode a byte can be written in directly, with its code.
     *
     * @return array<int, int> mode => code
     */
    public static function modesFor(int $byte): array
    {
        self::$single ??= self::buildSingles();

        return self::$single[$byte] ?? [];
    }

    /** The Punct code for a two-byte sequence, or null. */
    public static function pairCode(string $twoBytes): ?int
    {
        self::$pairs ??= [
            "\r\n" => 2,
            '. ' => 3,
            ', ' => 4,
            ': ' => 5,
        ];

        return self::$pairs[$twoBytes] ?? null;
    }

    /** The one-code shift from $from into $to, or null if there is none. */
    public static function shiftCode(int $from, int $to): ?int
    {
        return self::SHIFT[$from][$to] ?? null;
    }

    /**
     * The cheapest latch route from one mode to another.
     *
     * @return array{int, list<array{int, int}>} total bits, then [width, code] pairs
     */
    public static function latchRoute(int $from, int $to): array
    {
        self::$routes ??= self::buildRoutes();

        return self::$routes[$from][$to];
    }

    /** @return array<int, array<int, int>> */
    private static function buildSingles(): array
    {
        $table = [];

        $add = static function (int $mode, int $code, int $byte) use (&$table): void {
            $table[$byte][$mode] = $code;
        };

        $add(self::UPPER, 1, 0x20);
        for ($i = 0; $i < 26; $i++) {
            $add(self::UPPER, 2 + $i, 0x41 + $i);
            $add(self::LOWER, 2 + $i, 0x61 + $i);
        }
        $add(self::LOWER, 1, 0x20);

        $add(self::MIXED, 1, 0x20);
        for ($i = 0; $i < 13; $i++) {
            $add(self::MIXED, 2 + $i, 1 + $i);          // ^A to ^M
        }
        for ($i = 0; $i < 5; $i++) {
            $add(self::MIXED, 15 + $i, 27 + $i);        // ^[ to ^_
        }
        foreach ([0x40, 0x5c, 0x5e, 0x5f, 0x60, 0x7c, 0x7e, 0x7f] as $i => $byte) {
            $add(self::MIXED, 20 + $i, $byte);
        }

        $add(self::PUNCT, 1, 0x0d);
        foreach ([
            0x21, 0x22, 0x23, 0x24, 0x25, 0x26, 0x27, 0x28, 0x29, 0x2a, 0x2b, 0x2c,
            0x2d, 0x2e, 0x2f, 0x3a, 0x3b, 0x3c, 0x3d, 0x3e, 0x3f, 0x5b, 0x5d, 0x7b, 0x7d,
        ] as $i => $byte) {
            $add(self::PUNCT, 6 + $i, $byte);
        }

        $add(self::DIGIT, 1, 0x20);
        for ($i = 0; $i < 10; $i++) {
            $add(self::DIGIT, 2 + $i, 0x30 + $i);
        }
        $add(self::DIGIT, 12, 0x2c);
        $add(self::DIGIT, 13, 0x2e);

        return $table;
    }

    /**
     * Shortest latch routes, by Dijkstra over the one-step latches.
     *
     * @return array<int, array<int, array{int, list<array{int, int}>}>>
     */
    private static function buildRoutes(): array
    {
        $routes = [];

        foreach (self::MODES as $from) {
            $best = [$from => [0, []]];
            $queue = [$from];

            while ($queue !== []) {
                usort($queue, static fn (int $a, int $b): int => $best[$a][0] <=> $best[$b][0]);
                $mode = array_shift($queue);
                [$bits, $codes] = $best[$mode];

                foreach (self::LATCH[$mode] as $next => $code) {
                    $cost = $bits + self::WIDTH[$mode];
                    if (isset($best[$next]) && $best[$next][0] <= $cost) {
                        continue;
                    }
                    $best[$next] = [$cost, [...$codes, [self::WIDTH[$mode], $code]]];
                    $queue[] = $next;
                }
            }

            $routes[$from] = $best;
        }

        return $routes;
    }
}
