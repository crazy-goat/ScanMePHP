<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Pdf417;

/**
 * Turns a byte string into PDF417 data codewords, taking the cheapest route.
 *
 * PDF417 has three compaction modes and the text one has four submodes, so a
 * payload of any length has an enormous number of legal encodings and they are
 * not the same size. Greedy mode selection is what most encoders do and it is
 * usually within a codeword or two of optimal; this does the exact thing
 * instead, for the same reason Aztec's encoder does: a codeword saved is
 * sometimes a row saved, and the rule "take the cheapest" needs no tuning
 * constant to explain.
 *
 * **The cost unit is half a codeword.** Text compaction packs two characters
 * into one codeword, so a character, a shift or a latch each occupy one slot,
 * while a mode latch or a compacted codeword occupies two. Counting in halves
 * is what lets one search cover all three modes, and it is why leaving text
 * mode from an odd position costs one unit: the unused slot has to be filled
 * before a whole codeword can start.
 *
 * **The state is finite because both group structures are.** Numeric
 * compaction converts groups of forty-four digits and byte compaction groups of
 * six bytes, so the marginal cost of one more character depends only on how far
 * into the current group it falls — not on how long the run has been. Tracking
 * that offset turns what would be a search over every possible segment length
 * into fifty-eight states per position: eight for text (four submodes, two
 * parities), forty-four for numeric, six for byte. So the search is linear in
 * the payload and still exact, with no cap on how far ahead it looks.
 *
 * Two things it does not do. It emits no ECI header, so a payload is whatever
 * bytes were passed and the reader's charset assumption applies — zxing-cpp
 * prepends codewords 927 and 899 for binary input, which is a different symbol
 * and a deliberate difference recorded in tools/pdf417_reference.py. And it
 * emits no macro block, so segmented PDF417 is out of scope here.
 *
 * @internal Shared encoding primitive, not part of the public API.
 */
final class HighLevelEncoder
{
    /** Latch to text compaction, and the codeword that pads a short symbol. */
    public const LATCH_TEXT = 900;

    /** Latch to byte compaction, and its variant for a multiple of six. */
    public const LATCH_BYTE = 901;

    public const LATCH_BYTE_GROUPED = 924;

    /** Latch to numeric compaction. */
    public const LATCH_NUMERIC = 902;

    /** Byte compaction for exactly one byte, returning to the current mode. */
    public const SHIFT_BYTE = 913;

    /**
     * How equal-cost encodings are chosen between.
     *
     * Ties are not rare in PDF417, they are the normal case. Seven characters
     * — full stop, comma, hyphen, dollar, slash, colon and asterisk — sit in
     * both the Mixed and the Punctuation submode and cost the same either way,
     * and a digit run one past a group boundary costs the same whether its odd
     * digit goes in text or in the numeric run. Some preference has to settle
     * those, and leaving it to the order the moves happen to be generated in
     * would make the encoder's output an accident of this file's layout.
     *
     * So the search minimises the cost first and then, among encodings of equal
     * cost, prefers the one that disturbs the open mode least. A shift is the
     * gentlest change, since it lasts one character and leaves the open submode
     * intact; a submode latch is dearer; a switch into numeric compaction
     * dearer still; and every byte through byte compaction dearest of all.
     * That ordering is not arbitrary. Byte compaction is opaque — a reader gets
     * bytes and has to guess what they mean — while text and numeric say what
     * they are, and the less a stream moves around, the better a partial read
     * of it recovers.
     *
     * It also happens to be what the encoders this library is checked against
     * choose, which is worth having for its own sake: the reference fixture
     * then compares modules on the rows where the choice arises, instead of
     * skipping them.
     *
     * What it does not settle is *where* an unavoidable latch falls. Writing a
     * space in the submode already open and latching afterwards costs exactly
     * what latching first and writing the space in the new submode costs, and
     * no preference short of a positional weight separates those. Those ties
     * are left alone and recorded in the fixture instead.
     *
     * The weight only ever separates paths of identical cost, because a single
     * unit of cost outweighs any preference a payload can accumulate.
     */
    private const COST_WEIGHT = 1_000_000;

    /** Byte compaction, per byte — the only penalty that is not per switch. */
    private const BYTE_PENALTY = 8;

    /** Entering numeric compaction, per switch. */
    private const NUMERIC_PENALTY = 4;

    /** A latch between text submodes, per code. */
    private const LATCH_PENALTY = 2;

    /** A shift, which lasts one character and leaves the submode open. */
    private const SHIFT_PENALTY = 1;

    private const TEXT_STATES = 8;

    private const NUMERIC_BASE = self::TEXT_STATES;

    private const BYTE_BASE = self::NUMERIC_BASE + Compaction::NUMERIC_GROUP;

    private const STATES = self::BYTE_BASE + Compaction::BYTE_GROUP;

    private const UNREACHABLE = \PHP_INT_MAX;

    /**
     * The data codewords for $data, excluding the length descriptor.
     *
     * @return list<int>
     */
    public function encode(string $data): array
    {
        if ($data === '') {
            return [];
        }

        return $this->emit($data, $this->search($data));
    }

    /**
     * The cheapest path through the payload, as one step per transition.
     *
     * @return list<array{int, int, int, string, int|list<int>}> from state, to
     *         state, position, kind, payload
     */
    private function search(string $data): array
    {
        $length = \strlen($data);
        $cost = [array_fill(0, self::STATES, self::UNREACHABLE)];
        $cost[0][$this->textState(TextSubmodes::ALPHA, 0)] = 0;
        $from = [array_fill(0, self::STATES, null)];

        for ($i = 0; $i < $length; $i++) {
            $cost[$i + 1] = array_fill(0, self::STATES, self::UNREACHABLE);
            $from[$i + 1] = array_fill(0, self::STATES, null);

            // Latching out of a compaction mode consumes no character, so it
            // has to settle before the position's characters are considered.
            $this->relaxLatchesToText($cost[$i], $from[$i], $i);

            foreach ($cost[$i] as $state => $reached) {
                if ($reached === self::UNREACHABLE) {
                    continue;
                }
                foreach ($this->transitions($state, $data[$i]) as [$next, $step, $kind, $payload]) {
                    if ($reached + $step < $cost[$i + 1][$next]) {
                        $cost[$i + 1][$next] = $reached + $step;
                        $from[$i + 1][$next] = [$state, $i, $kind, $payload];
                    }
                }
            }
        }

        $this->relaxLatchesToText($cost[$length], $from[$length], $length);

        $best = null;
        $bestCost = self::UNREACHABLE;
        foreach ($cost[$length] as $state => $reached) {
            if ($reached === self::UNREACHABLE) {
                continue;
            }
            // A payload that ends mid-codeword pays for the unused slot.
            $total = $reached + ($state < self::TEXT_STATES ? $this->weigh($state % 2, 0) : 0);
            if ($total < $bestCost) {
                $bestCost = $total;
                $best = $state;
            }
        }

        if ($best === null) {
            throw new \LogicException('Every PDF417 payload has an encoding, so this cannot happen');
        }

        $steps = [];
        $state = $best;
        $position = $length;
        while ($from[$position][$state] !== null) {
            [$previous, $at, $kind, $payload] = $from[$position][$state];
            $steps[] = [$previous, $state, $at, $kind, $payload];
            $state = $previous;
            $position = $at;
        }

        return array_reverse($steps);
    }

    /**
     * The moves available from $state when the next character is $character.
     *
     * @return list<array{int, int, string, int|list<int>}> next state, cost,
     *         kind, payload
     */
    private function transitions(int $state, string $character): array
    {
        $byte = \ord($character);
        $isDigit = $character >= '0' && $character <= '9';
        $moves = [];

        if ($state < self::TEXT_STATES) {
            $submode = intdiv($state, 2);
            $parity = $state % 2;
            $align = $parity;

            $code = TextSubmodes::code($submode, $character);
            if ($code !== null) {
                $moves[] = [$this->textState($submode, 1 - $parity), $this->weigh(1, 0), 'char', $code];
            }

            // Only a character the open submode cannot write is allowed to
            // change submode. Deferring a latch past a character that both
            // submodes can write costs exactly nothing — the character takes
            // one slot either way and the latch takes one wherever it lands —
            // so every encoding has an equal-cost twin whose latches sit as
            // late as they can, and this keeps only that twin. It removes a
            // whole family of ties, cuts the moves to search, and is what the
            // encoders this library is checked against do.
            foreach ($code === null ? [TextSubmodes::ALPHA, TextSubmodes::LOWER, TextSubmodes::MIXED, TextSubmodes::PUNCT] : [] as $target) {
                if ($target === $submode) {
                    continue;
                }
                $inTarget = TextSubmodes::code($target, $character);
                if ($inTarget === null) {
                    continue;
                }

                $shift = TextSubmodes::shift($submode, $target);
                if ($shift !== null) {
                    // A shift lasts one character, so the submode is unchanged
                    // and the parity comes back to where it was.
                    $moves[] = [$state, $this->weigh(2, self::SHIFT_PENALTY), 'shift', [$shift, $inTarget]];
                }

                $route = TextSubmodes::latchRoute($submode, $target);
                $used = \count($route) + 1;
                $moves[] = [
                    $this->textState($target, ($parity + $used) % 2),
                    $this->weigh($used, \count($route) * self::LATCH_PENALTY),
                    'latch',
                    [...$route, $inTarget],
                ];
            }

            // One byte, anywhere, without leaving text mode. The only way to
            // carry a byte that no submode spells, short of a mode latch.
            $moves[] = [
                $this->textState($submode, 0),
                $this->weigh($align + 4, self::BYTE_PENALTY),
                'byte-shift',
                $byte,
            ];

            if ($isDigit) {
                $moves[] = [
                    self::NUMERIC_BASE + 1 % Compaction::NUMERIC_GROUP,
                    $this->weigh($align + 2 + 2 * $this->numericStep(0), self::NUMERIC_PENALTY),
                    'enter-numeric',
                    $byte,
                ];
            }
            $moves[] = [
                self::BYTE_BASE + 1 % Compaction::BYTE_GROUP,
                $this->weigh($align + 2 + 2 * $this->byteStep(0), self::BYTE_PENALTY),
                'enter-byte',
                $byte,
            ];

            return $moves;
        }

        if ($state < self::BYTE_BASE) {
            $offset = $state - self::NUMERIC_BASE;
            if ($isDigit) {
                $moves[] = [
                    self::NUMERIC_BASE + ($offset + 1) % Compaction::NUMERIC_GROUP,
                    $this->weigh(2 * $this->numericStep($offset), 0),
                    'digit',
                    $byte,
                ];
            }
            $moves[] = [
                self::BYTE_BASE + 1 % Compaction::BYTE_GROUP,
                $this->weigh(2 + 2 * $this->byteStep(0), self::BYTE_PENALTY),
                'enter-byte',
                $byte,
            ];

            return $moves;
        }

        $offset = $state - self::BYTE_BASE;
        $moves[] = [
            self::BYTE_BASE + ($offset + 1) % Compaction::BYTE_GROUP,
            $this->weigh(2 * $this->byteStep($offset), self::BYTE_PENALTY),
            'byte',
            $byte,
        ];
        if ($isDigit) {
            $moves[] = [
                self::NUMERIC_BASE + 1 % Compaction::NUMERIC_GROUP,
                $this->weigh(2 + 2 * $this->numericStep(0), self::NUMERIC_PENALTY),
                'enter-numeric',
                $byte,
            ];
        }

        return $moves;
    }

    /**
     * Latching from a compaction mode back to text, which consumes no input.
     *
     * @param array<int, int> $cost
     * @param array<int, array{int, int, string, int|list<int>}|null> $from
     */
    private function relaxLatchesToText(array &$cost, array &$from, int $position): void
    {
        $target = $this->textState(TextSubmodes::ALPHA, 0);

        for ($state = self::NUMERIC_BASE; $state < self::STATES; $state++) {
            if ($cost[$state] === self::UNREACHABLE) {
                continue;
            }
            $reached = $cost[$state] + $this->weigh(2, 0);
            if ($reached >= $cost[$target]) {
                continue;
            }
            $cost[$target] = $reached;
            $from[$target] = [$state, $position, 'latch-text', self::LATCH_TEXT];
        }
    }

    /**
     * The codewords the chosen path spells out.
     *
     * Done in two passes, because a piece of the stream cannot be written
     * until it is known to be finished. Text slots become codewords two at a
     * time and a run of them ending on an odd slot needs a filler, and a byte
     * segment's latch says whether its length was a whole number of groups —
     * neither is known while the run is still growing. So the walk groups the
     * path into runs first and spells each one out afterwards.
     *
     * @param list<array{int, int, int, string, int|list<int>}> $steps
     * @return list<int>
     */
    private function emit(string $data, array $steps): array
    {
        /** @var list<array{kind: string, codes: list<int>, data: string}> $runs */
        $runs = [];

        foreach ($steps as [, , $position, $kind, $payload]) {
            switch ($kind) {
                case 'char':
                    $this->openRun($runs, 'text')['codes'][] = $payload;

                    break;
                case 'shift':
                case 'latch':
                    foreach ((array) $payload as $code) {
                        $this->openRun($runs, 'text')['codes'][] = $code;
                    }

                    break;
                case 'byte-shift':
                    $runs[] = ['kind' => 'raw', 'codes' => [self::SHIFT_BYTE, $payload], 'data' => ''];

                    break;
                case 'latch-text':
                    $runs[] = ['kind' => 'raw', 'codes' => [self::LATCH_TEXT], 'data' => ''];

                    break;
                case 'enter-numeric':
                    $runs[] = ['kind' => 'numeric', 'codes' => [], 'data' => $data[$position]];

                    break;
                case 'enter-byte':
                    $runs[] = ['kind' => 'byte', 'codes' => [], 'data' => $data[$position]];

                    break;
                case 'digit':
                case 'byte':
                    $runs[\count($runs) - 1]['data'] .= $data[$position];

                    break;
            }
        }

        $codewords = [];
        foreach ($runs as $run) {
            $codewords = [...$codewords, ...$this->spell($run)];
        }

        return $codewords;
    }

    /**
     * The run at the end of $runs, opening a new one if the last is not $kind.
     *
     * A run of one kind is ended by the next run of another, which is what
     * makes the grouping in emit() a single pass with no flushing.
     *
     * @param list<array{kind: string, codes: list<int>, data: string}> $runs
     * @return array{kind: string, codes: list<int>, data: string}
     */
    private function &openRun(array &$runs, string $kind): array
    {
        if ($runs === [] || $runs[\count($runs) - 1]['kind'] !== $kind) {
            $runs[] = ['kind' => $kind, 'codes' => [], 'data' => ''];
        }

        return $runs[\count($runs) - 1];
    }

    /**
     * One run as codewords.
     *
     * @param array{kind: string, codes: list<int>, data: string} $run
     * @return list<int>
     */
    private function spell(array $run): array
    {
        if ($run['kind'] === 'raw') {
            return $run['codes'];
        }

        if ($run['kind'] === 'numeric') {
            return [self::LATCH_NUMERIC, ...Compaction::numeric($run['data'])];
        }

        if ($run['kind'] === 'byte') {
            $latch = \strlen($run['data']) % Compaction::BYTE_GROUP === 0
                ? self::LATCH_BYTE_GROUPED
                : self::LATCH_BYTE;

            return [$latch, ...Compaction::bytes($run['data'])];
        }

        $codes = $run['codes'];
        if (\count($codes) % 2 === 1) {
            $codes[] = TextSubmodes::FILLER;
        }

        $codewords = [];
        $counter = \count($codes);
        for ($i = 0; $i < $counter; $i += 2) {
            $codewords[] = $codes[$i] * 30 + $codes[$i + 1];
        }

        return $codewords;
    }

    /** One move's cost: units of half a codeword, then the tie-break. */
    private function weigh(int $units, int $preference): int
    {
        return $units * self::COST_WEIGHT + $preference;
    }

    /** Codewords added by the digit at offset $offset of a numeric group. */
    private function numericStep(int $offset): int
    {
        if ($offset === 0) {
            return 1;
        }

        return intdiv($offset + 4, 3) - intdiv($offset + 3, 3);
    }

    /** Codewords added by the byte at offset $offset of a byte group. */
    private function byteStep(int $offset): int
    {
        return $offset === Compaction::BYTE_GROUP - 1 ? 0 : 1;
    }

    private function textState(int $submode, int $parity): int
    {
        return $submode * 2 + $parity;
    }
}
