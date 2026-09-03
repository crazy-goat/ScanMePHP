<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\MaxiCode;

/**
 * Bytes to MaxiCode data codewords, by exact search.
 *
 * The choice at every character is which of the five code sets to be in, and
 * the sets overlap enough that a greedy answer is regularly wrong: a space is
 * carried by all five, a comma by three, and the cost of writing one depends
 * entirely on what the characters around it need. So this is a shortest-path
 * problem rather than a set of rules, and it is a small one — five states, one
 * pass over the payload, no lookahead limit and no tuning constant.
 *
 * Four kinds of move leave each state:
 *
 *  - write the character directly, one codeword, when the open set carries it;
 *  - **shift** one character out of the open set, two codewords, which leaves
 *    the set unchanged;
 *  - **latch** into another set, one or two codewords and no character
 *    consumed;
 *  - from B only, shift the next two or three characters into A at once, which
 *    is the cheapest way to write a capital inside a lower-case run.
 *
 * plus the numeric move: nine consecutive digits cost six codewords instead of
 * nine, and the set stays as it was. Nine is the only length that compacts —
 * there is no shorter form — so the move is all-or-nothing and the search does
 * not have to reason about partial runs.
 *
 * **Ties are the normal case**, as they were for PDF417, and two rules break
 * them. Both are cost-neutral, so neither can make an encoding longer.
 *
 * The first: **only a character the open set cannot write may change the set**.
 * Deferring a latch past a character both sets carry costs exactly nothing, so
 * every encoding has an equal-cost twin whose latches sit as late as they can,
 * and keeping one representative of each family stops the search from picking
 * between them at random.
 *
 * The second: **a single shift beats a latch of the same cost, and a
 * multi-character shift loses to one**. Shifting once leaves the open set
 * alone, which is the smaller disturbance; shifting two or three characters is
 * a latch that forgot to come back, and where the two cost the same the latch
 * is the one that says what it is doing. Both halves match the encoder this is
 * measured against at every tie observed.
 */
final class HighLevelEncoder
{
    /** Nine digits, and only nine, compact into five codewords. */
    public const NUMERIC_RUN = 9;

    public const NUMERIC_CODEWORDS = 5;

    private const COST_WEIGHT = 1_000;

    /** Tie-break weights; see the class docblock for why they are ordered so. */
    private const LATCH_PENALTY = 1;

    private const MULTI_SHIFT_PENALTY = 3;

    private const UNREACHABLE = \PHP_INT_MAX;

    /**
     * The data codewords for $data, unpadded, and the code set they end in.
     *
     * The closing set travels with the codewords because padding depends on it
     * and re-deriving it would mean parsing the stream back — the one place a
     * shift and a character are indistinguishable without the history.
     *
     * @return array{codewords: list<int>, set: int}
     */
    public function encode(string $data): array
    {
        $length = \strlen($data);
        if ($length === 0) {
            return ['codewords' => [], 'set' => CodeSets::A];
        }

        /** @var list<array<int, int>> $cost */
        $cost = [array_fill(0, CodeSets::COUNT, self::UNREACHABLE)];
        $cost[0][CodeSets::A] = 0;
        /** @var array<int, array<int, array{int, int, list<int>}>> $from */
        $from = [];

        for ($i = 0; $i <= $length; $i++) {
            $cost[$i] ??= array_fill(0, CodeSets::COUNT, self::UNREACHABLE);
            $this->relaxLatches($cost[$i], $from, $i, $i < $length ? $data[$i] : null);

            if ($i === $length) {
                continue;
            }

            for ($set = 0; $set < CodeSets::COUNT; $set++) {
                if ($cost[$i][$set] === self::UNREACHABLE) {
                    continue;
                }

                foreach ($this->moves($data, $i, $set) as [$step, $words, $preference, $emitted]) {
                    $cost[$i + $step] ??= array_fill(0, CodeSets::COUNT, self::UNREACHABLE);
                    $candidate = $cost[$i][$set] + $words * self::COST_WEIGHT + $preference;
                    if ($candidate < $cost[$i + $step][$set]) {
                        $cost[$i + $step][$set] = $candidate;
                        $from[$i + $step][$set] = [$i, $set, $emitted];
                    }
                }
            }
        }

        return $this->walkBack($cost, $from, $length);
    }

    /**
     * The five sets at one position, after every worthwhile latch.
     *
     * Latching twice in a row never helps — the second latch reaches whatever
     * the first would have — so one relaxation round per set is enough, and
     * repeating it until nothing improves costs at most four rounds.
     *
     * @param array<int, int> $cost
     * @param array<int, array<int, array{int, int, list<int>}>> $from
     */
    private function relaxLatches(array &$cost, array &$from, int $position, ?string $next): void
    {
        for ($round = 0; $round < CodeSets::COUNT - 1; $round++) {
            $changed = false;
            for ($set = 0; $set < CodeSets::COUNT; $set++) {
                if ($cost[$set] === self::UNREACHABLE) {
                    continue;
                }

                // Only a character the open set cannot write may change the
                // set. Deferring a latch past a character both sets carry costs
                // exactly nothing, so every encoding has an equal-cost twin
                // whose latches sit as late as they can; keeping one of each
                // family is what stops the search reporting arbitrary ties.
                if ($next !== null && CodeSets::value($set, $next) !== null) {
                    continue;
                }

                for ($target = 0; $target < CodeSets::COUNT; $target++) {
                    if ($target === $set) {
                        continue;
                    }

                    $latch = CodeSets::latch($set, $target);
                    $candidate = $cost[$set]
                        + \count($latch) * self::COST_WEIGHT
                        + \count($latch) * self::LATCH_PENALTY;
                    if ($candidate < $cost[$target]) {
                        $cost[$target] = $candidate;
                        $from[$position][$target] = [$position, $set, $latch];
                        $changed = true;
                    }
                }
            }

            if (!$changed) {
                return;
            }
        }
    }

    /**
     * Every move out of ($position, $set): characters consumed, codewords
     * spent, tie-break preference, and the codewords themselves.
     *
     * @return list<array{int, int, int, list<int>}>
     */
    private function moves(string $data, int $position, int $set): array
    {
        $moves = [];
        $byte = $data[$position];

        $direct = CodeSets::value($set, $byte);
        if ($direct !== null) {
            $moves[] = [1, 1, 0, [$direct]];
        }

        foreach (CodeSets::sets($byte) as $target => $value) {
            if ($target === $set) {
                continue;
            }

            $shift = CodeSets::shift($set, $target);
            if ($shift !== null) {
                $moves[] = [1, 2, 0, [$shift, $value]];
            }
        }

        if ($set === CodeSets::B) {
            foreach ([2 => CodeSets::SHIFT_TWO_A, 3 => CodeSets::SHIFT_THREE_A] as $run => $code) {
                $values = $this->runIn(CodeSets::A, $data, $position, $run);
                if ($values !== null) {
                    $moves[] = [$run, $run + 1, self::MULTI_SHIFT_PENALTY, [$code, ...$values]];
                }
            }
        }

        $digits = $this->digitRun($data, $position);
        if ($digits !== null) {
            $moves[] = [self::NUMERIC_RUN, 1 + self::NUMERIC_CODEWORDS, 0, $digits];
        }

        return $moves;
    }

    /**
     * The values of $run characters from $position, if $set carries them all.
     *
     * @return list<int>|null
     */
    private function runIn(int $set, string $data, int $position, int $run): ?array
    {
        if ($position + $run > \strlen($data)) {
            return null;
        }

        $values = [];
        for ($i = 0; $i < $run; $i++) {
            $value = CodeSets::value($set, $data[$position + $i]);
            if ($value === null) {
                return null;
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * The numeric latch and five codewords for nine digits, if nine start here.
     *
     * The nine digits are read as one decimal number and written base 64, most
     * significant codeword first. Nine digits is at most 999999999, which is
     * under 2^30, so five six-bit codewords always hold it and the leading
     * zeros a shorter number produces are part of the value rather than lost.
     *
     * @return list<int>|null
     */
    private function digitRun(string $data, int $position): ?array
    {
        if ($position + self::NUMERIC_RUN > \strlen($data)) {
            return null;
        }

        $digits = substr($data, $position, self::NUMERIC_RUN);
        if (ctype_digit($digits) === false) {
            return null;
        }

        $value = (int) $digits;
        $codewords = [];
        for ($i = self::NUMERIC_CODEWORDS - 1; $i >= 0; $i--) {
            $codewords[] = $value >> ($i * Specs::CODEWORD_BITS) & (Specs::CODEWORD_VALUES - 1);
        }

        return [CodeSets::NUMERIC_LATCH, ...$codewords];
    }

    /**
     * The codewords of the cheapest path, and the set it ends in.
     *
     * @param list<array<int, int>> $cost
     * @param array<int, array<int, array{int, int, list<int>}>> $from
     * @return array{codewords: list<int>, set: int}
     */
    private function walkBack(array $cost, array $from, int $length): array
    {
        $best = null;
        $bestCost = self::UNREACHABLE;
        for ($set = 0; $set < CodeSets::COUNT; $set++) {
            // Ending in C or D costs one more codeword than ending anywhere
            // else, because neither has a pad and the tail has to latch to A
            // before it can be filled.
            $finish = CodeSets::pad($set) === null ? self::COST_WEIGHT : 0;
            if ($cost[$length][$set] !== self::UNREACHABLE && $cost[$length][$set] + $finish < $bestCost) {
                $bestCost = $cost[$length][$set] + $finish;
                $best = $set;
            }
        }

        if ($best === null) {
            throw new \LogicException('every byte belongs to some MaxiCode code set, so this cannot happen');
        }

        $codewords = [];
        $position = $length;
        $set = $best;
        while ($position > 0 || isset($from[$position][$set])) {
            [$previousPosition, $previousSet, $emitted] = $from[$position][$set];
            array_unshift($codewords, ...$emitted);
            if ($previousPosition === $position && $previousSet === $set) {
                throw new \LogicException('the search moved nowhere');
            }
            $position = $previousPosition;
            $set = $previousSet;
        }

        return ['codewords' => $codewords, 'set' => $best];
    }
}
