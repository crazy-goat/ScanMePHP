<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Aztec;

/**
 * Bytes to the shortest Aztec bit stream, exactly rather than greedily.
 *
 * Aztec's five character modes overlap, and the cheap-looking move is often
 * wrong: a single lower-case letter inside a word of capitals is cheaper
 * shifted than latched, three of them are cheaper latched, and two is a tie
 * that has to be broken somehow. Nothing local decides this, so the encoder
 * carries a frontier of candidate states and lets the cheapest survive — the
 * same shape as Code128Encoder, for the same reason.
 *
 * A state is a mode, a bit count, and how many bytes of an open binary shift it
 * is carrying. The run length has to be in the state rather than resolved on
 * the spot because the length field is five bits up to thirty-one bytes and
 * sixteen after that, so whether extending a run is worth it depends on how
 * long the run already is.
 *
 * States are held per input position rather than per step, because four Punct
 * codes stand for two characters — ". ", ", ", ": " and a CR LF pair — and a
 * frontier that advanced one byte at a time could not express them.
 *
 * The frontier stays small because of one exact dominance rule. Order states
 * in the same mode by how cheap their future is: a state with a one-byte open
 * run is the best off, since every extra binary byte costs it eight bits and it
 * is furthest from the wider length field; longer runs are progressively worse;
 * and a state with no open run is worst of all, since it has to pay to open
 * one. Closing a run is free. So walking that order and keeping only states
 * that strictly beat every bit count kept so far loses nothing — no
 * approximation and no tuning constant.
 *
 * Not implemented: FLG(n), Punct code 0, which is how Aztec carries an ECI or
 * an FNC1. Nothing in this library asks for either yet — a GS1 Aztec would,
 * and would want it — and an encoder that emitted one by accident would be
 * worse than one that cannot.
 *
 * @internal Part of the Aztec encoding pipeline.
 */
final class HighLevelEncoder
{
    /**
     * A binary run's length field holds up to 31 directly; beyond that it holds
     * a zero and eleven more bits counting from 31.
     */
    private const SHORT_RUN = 31;

    private const MAX_RUN = self::SHORT_RUN + 2047;

    private const LONG_RUN_EXTRA = 11;

    /** @return list<int> The message bits, most significant first */
    public function encode(string $data): array
    {
        $length = strlen($data);
        if ($length === 0) {
            return [];
        }

        /** @var list<list<array<string, mixed>>> */
        $frontier = array_fill(0, $length + 1, []);
        $frontier[0] = [[
            'mode' => CharacterModes::UPPER,
            'bits' => 0,
            'run' => 0,
            'runStart' => 0,
            'tokens' => null,
        ]];

        for ($i = 0; $i < $length; $i++) {
            $frontier[$i] = $this->prune($frontier[$i]);
            foreach ($frontier[$i] as $state) {
                $this->step($data, $i, $state, $frontier);
            }
        }

        $states = $this->prune($frontier[$length]);
        if ($states === []) {
            throw new \LogicException('every byte has a binary shift, so the frontier cannot empty');
        }

        return $this->emit($data, $this->cheapest($states));
    }

    /**
     * @param array<string, mixed> $state
     * @param list<list<array<string, mixed>>> $frontier
     */
    private function step(string $data, int $index, array $state, array &$frontier): void
    {
        if ($index + 1 < strlen($data)) {
            $pair = CharacterModes::pairCode(substr($data, $index, 2));
            if ($pair !== null) {
                $this->appendCode($state, $frontier[$index + 2], CharacterModes::PUNCT, $pair);
            }
        }

        foreach (CharacterModes::modesFor(ord($data[$index])) as $target => $code) {
            $this->appendCode($state, $frontier[$index + 1], $target, $code);
        }

        $this->appendBinary($state, $frontier[$index + 1], $index);
    }

    /**
     * One code in $target, reached by staying put, shifting or latching.
     *
     * All three are offered when all three exist. A shift leaves the latched
     * mode alone, which is the whole point of it: one foreign character costs
     * the shift and nothing afterwards, while a latch costs the same now and
     * pays back on the second character.
     *
     * @param array<string, mixed> $state
     * @param list<array<string, mixed>> $into
     */
    private function appendCode(array $state, array &$into, int $target, int $code): void
    {
        $mode = $state['mode'];
        $width = CharacterModes::WIDTH[$target];
        $closed = $this->closeRun($state);

        if ($target === $mode) {
            $into[] = $this->advance($closed, $mode, $closed['bits'] + $width, [[$width, $code]]);

            return;
        }

        $shift = CharacterModes::shiftCode($mode, $target);
        if ($shift !== null) {
            $into[] = $this->advance(
                $closed,
                $mode,
                $closed['bits'] + CharacterModes::WIDTH[$mode] + $width,
                [[CharacterModes::WIDTH[$mode], $shift], [$width, $code]],
            );
        }

        [$latchBits, $latchCodes] = CharacterModes::latchRoute($mode, $target);
        $into[] = $this->advance(
            $closed,
            $target,
            $closed['bits'] + $latchBits + $width,
            [...$latchCodes, [$width, $code]],
        );
    }

    /**
     * Extend the open binary run, or open one — latching to Upper first if the
     * current mode has no binary shift, which Punct and Digit do not.
     *
     * @param array<string, mixed> $state
     * @param list<array<string, mixed>> $into
     */
    private function appendBinary(array $state, array &$into, int $index): void
    {
        if ($state['run'] > 0 && $state['run'] < self::MAX_RUN) {
            $bits = $state['bits'] + 8;
            if ($state['run'] + 1 > self::SHORT_RUN) {
                $bits += $state['run'] + 1 === self::SHORT_RUN + 1 ? self::LONG_RUN_EXTRA : 0;
            }

            $into[] = [
                'mode' => $state['mode'],
                'bits' => $bits,
                'run' => $state['run'] + 1,
                'runStart' => $state['runStart'],
                'tokens' => $state['tokens'],
            ];

            return;
        }

        $closed = $this->closeRun($state);
        $mode = $closed['mode'];
        $bits = $closed['bits'];
        $tokens = $closed['tokens'];

        if (!isset(CharacterModes::BINARY_SHIFT[$mode])) {
            [$latchBits, $latchCodes] = CharacterModes::latchRoute($mode, CharacterModes::UPPER);
            $bits += $latchBits;
            foreach ($latchCodes as [$width, $value]) {
                $tokens = [$tokens, ['code', $width, $value]];
            }
            $mode = CharacterModes::UPPER;
        }

        $into[] = [
            'mode' => $mode,
            // The binary shift code, then a five-bit length, then the byte.
            'bits' => $bits + CharacterModes::WIDTH[$mode] + 5 + 8,
            'run' => 1,
            'runStart' => $index,
            'tokens' => $tokens,
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function closeRun(array $state): array
    {
        if ($state['run'] === 0) {
            return $state;
        }

        $state['tokens'] = [
            $state['tokens'],
            ['binary', $state['runStart'], $state['run'], CharacterModes::WIDTH[$state['mode']], CharacterModes::BINARY_SHIFT[$state['mode']]],
        ];
        $state['run'] = 0;

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @param list<array{int, int}> $codes
     * @return array<string, mixed>
     */
    private function advance(array $state, int $mode, int $bits, array $codes): array
    {
        $tokens = $state['tokens'];
        foreach ($codes as [$width, $value]) {
            $tokens = [$tokens, ['code', $width, $value]];
        }

        return [
            'mode' => $mode,
            'bits' => $bits,
            'run' => 0,
            'runStart' => 0,
            'tokens' => $tokens,
        ];
    }

    /**
     * @param list<array<string, mixed>> $states
     * @return list<array<string, mixed>>
     */
    private function prune(array $states): array
    {
        $byMode = [];
        foreach ($states as $state) {
            $existing = $byMode[$state['mode']][$state['run']] ?? null;
            if ($existing === null || $state['bits'] < $existing['bits']) {
                $byMode[$state['mode']][$state['run']] = $state;
            }
        }

        $kept = [];
        foreach ($byMode as $candidates) {
            // Cheapest future first: a short open run, then longer ones, then
            // no run at all. See the dominance rule in the class docblock.
            $closed = $candidates[0] ?? null;
            unset($candidates[0]);
            ksort($candidates);
            if ($closed !== null) {
                $candidates[] = $closed;
            }

            $floor = PHP_INT_MAX;
            foreach ($candidates as $state) {
                if ($state['bits'] >= $floor) {
                    continue;
                }
                $floor = $state['bits'];
                $kept[] = $state;
            }
        }

        return $kept;
    }

    /**
     * @param list<array<string, mixed>> $states
     * @return array<string, mixed>
     */
    private function cheapest(array $states): array
    {
        $best = null;
        foreach ($states as $state) {
            if ($best === null || $state['bits'] < $best['bits']) {
                $best = $state;
            }
        }

        \assert($best !== null);

        return $this->closeRun($best);
    }

    /**
     * @param array<string, mixed> $state
     * @return list<int>
     */
    private function emit(string $data, array $state): array
    {
        $tokens = [];
        for ($node = $state['tokens']; $node !== null; $node = $node[0]) {
            $tokens[] = $node[1];
        }

        $bits = [];
        foreach (array_reverse($tokens) as $token) {
            if ($token[0] === 'code') {
                $this->pushBits($bits, $token[2], $token[1]);

                continue;
            }

            [, $start, $count, $width, $shift] = $token;
            $this->pushBits($bits, $shift, $width);
            if ($count <= self::SHORT_RUN) {
                $this->pushBits($bits, $count, 5);
            } else {
                $this->pushBits($bits, 0, 5);
                $this->pushBits($bits, $count - self::SHORT_RUN, 11);
            }
            for ($i = 0; $i < $count; $i++) {
                $this->pushBits($bits, ord($data[$start + $i]), 8);
            }
        }

        return $bits;
    }

    /** @param list<int> $bits */
    private function pushBits(array &$bits, int $value, int $width): void
    {
        for ($i = $width - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }
}
