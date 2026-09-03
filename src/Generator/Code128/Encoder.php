<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code128;

/**
 * Chooses the character sets a Code 128 symbol is written in.
 *
 * Set C encodes a *pair* of digits per symbol character and set B a single
 * character, so a digit run is worth switching into C for — but the switch
 * itself costs a character, and switching back costs another. Every encoder
 * has to answer where the balance lies, and a wrong answer is invisible: the
 * symbol still scans as the right data, just wider than it needed to be.
 *
 * Rather than guess at thresholds, this finds the shortest encoding outright.
 * Two states (which set is current) over the length of the payload is a linear
 * dynamic program, so optimality costs nothing worth measuring, and it removes
 * a class of question — "is six digits the right cut-off, and what about an odd
 * run at the end?" — instead of answering it.
 *
 * Ties are broken towards set C. There is usually more than one shortest
 * encoding, and something has to choose; this is what zxing-cpp chooses, which
 * is what lets tests/fixtures/code128_reference.csv be compared module for
 * module rather than merely by width.
 *
 * FNC1 is part of the same problem. It is one symbol character in either set —
 * in set C it stands alone rather than for a digit pair — so it is simply
 * another element the program can place, which is why GS1-128 needs no encoder
 * of its own.
 */
final class Encoder
{
    /**
     * The byte that stands for FNC1 in this encoder's input.
     *
     * ASCII GS is what a scanner transmits for an FNC1 that separates GS1
     * element strings, so the payload handed here is byte for byte what comes
     * back out of a reader. It is also outside the printable range Code 128
     * accepts as data, so it cannot collide with anything a caller meant
     * literally.
     */
    public const FNC1 = "\x1d";

    /** Cost of an encoding that does not exist, kept below PHP_INT_MAX. */
    private const IMPOSSIBLE = 1 << 30;

    /**
     * The symbol characters for $data: a start code, the payload, and the
     * check character.
     *
     * @return list<int>
     *
     * @throws \InvalidArgumentException on a byte Code 128 set B cannot carry
     */
    public function symbolValues(string $data): array
    {
        $length = \strlen($data);
        if ($length === 0) {
            throw new \InvalidArgumentException('Code 128 has no representation for an empty payload');
        }

        $suffix = $this->suffixCosts($data);

        if (min($suffix[0]) >= self::IMPOSSIBLE) {
            throw new \InvalidArgumentException(
                'Code 128 set B carries printable ASCII only; this payload contains a byte it cannot represent'
            );
        }

        // Set C first, so a tie starts in C.
        $inSetC = $suffix[0][1] <= $suffix[0][0];
        $values = [$inSetC ? Patterns::START_C : Patterns::START_B];

        for ($position = 0; $position < $length;) {
            [$emitted, $position, $inSetC] = $this->step($data, $position, $inSetC, $suffix);
            foreach ($emitted as $value) {
                $values[] = $value;
            }
        }

        $values[] = Patterns::checkCharacter($values);

        return $values;
    }

    /**
     * The cheapest single move from $position, measured by what it leaves behind.
     *
     * Set C is tried first in each set's list, so a tie moves towards C.
     *
     * @param list<array{int, int}> $suffix
     *
     * @return array{list<int>, int, bool}
     */
    private function step(string $data, int $position, bool $inSetC, array $suffix): array
    {
        $best = null;
        foreach ($this->moves($data, $position, $inSetC) as [$values, $next, $nextInSetC]) {
            $cost = \count($values) + $suffix[$next][$nextInSetC ? 1 : 0];
            if ($best === null || $cost < $best[0]) {
                $best = [$cost, $values, $next, $nextInSetC];
            }
        }

        // suffixCosts() proved a finite encoding exists from here, so some move
        // reaches the end.
        \assert($best !== null);

        return [$best[1], $best[2], $best[3]];
    }

    /**
     * Every move available from $position, cheapest-set-first within each set.
     *
     * A move is the symbol characters it emits, where it leaves the cursor, and
     * which set is current afterwards. Staying put is not a move: a switch is
     * always bundled with the character it was made for, which is what keeps
     * this a plain forward walk rather than something that can loop.
     *
     * @return list<array{list<int>, int, bool}>
     */
    private function moves(string $data, int $position, bool $inSetC): array
    {
        $moves = [];
        $pair = $this->digitPairAt($data, $position);
        $single = $this->setBValue($data, $position);

        if ($inSetC) {
            if ($pair !== null) {
                $moves[] = [[$pair], $position + 2, true];
            }
            if ($data[$position] === self::FNC1) {
                $moves[] = [[Patterns::FNC1], $position + 1, true];
            }
            if ($single !== null) {
                $moves[] = [[Patterns::CODE_B, $single], $position + 1, false];
            }

            return $moves;
        }

        if ($pair !== null) {
            $moves[] = [[Patterns::CODE_C, $pair], $position + 2, true];
        }
        if ($data[$position] === self::FNC1) {
            $moves[] = [[Patterns::FNC1], $position + 1, false];
        }
        if ($single !== null) {
            $moves[] = [[$single], $position + 1, false];
        }

        return $moves;
    }

    /**
     * How many symbol characters it takes to finish, from each position in each set.
     *
     * Filled from the end backwards, so by the time a position is reached
     * everything it can move to is already known.
     *
     * @return list<array{int, int}> Indexed by position, then 0 for set B and 1 for set C
     */
    private function suffixCosts(string $data): array
    {
        $length = \strlen($data);
        $suffix = array_fill(0, $length + 1, [self::IMPOSSIBLE, self::IMPOSSIBLE]);
        $suffix[$length] = [0, 0];

        for ($position = $length - 1; $position >= 0; $position--) {
            foreach ([false, true] as $inSetC) {
                $best = self::IMPOSSIBLE;
                foreach ($this->moves($data, $position, $inSetC) as [$values, $next, $nextInSetC]) {
                    $rest = $suffix[$next][$nextInSetC ? 1 : 0];
                    if ($rest < self::IMPOSSIBLE) {
                        $best = min($best, \count($values) + $rest);
                    }
                }

                $suffix[$position][$inSetC ? 1 : 0] = $best;
            }
        }

        return $suffix;
    }

    /** The set C value at $position, or null if there is no digit pair there. */
    private function digitPairAt(string $data, int $position): ?int
    {
        if ($position + 1 >= \strlen($data)) {
            return null;
        }

        $first = $data[$position];
        $second = $data[$position + 1];

        if ($first < '0' || $first > '9' || $second < '0' || $second > '9') {
            return null;
        }

        return (int) substr($data, $position, 2);
    }

    /** The set B value at $position, or null for a byte set B cannot carry. */
    private function setBValue(string $data, int $position): ?int
    {
        $byte = \ord($data[$position]);

        return $byte >= 32 && $byte <= 126 ? $byte - 32 : null;
    }
}
