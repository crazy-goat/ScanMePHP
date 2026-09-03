<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding;

/**
 * How to split a payload between modes so that it costs the fewest bits.
 *
 * A single segment in the narrowest mode that covers every character is the
 * obvious thing to do and it is not the cheapest. `LOT4471` is seven
 * alphanumeric characters, which is forty-five bits at Micro QR's M3; split
 * into `LOT` alphanumeric and `4471` numeric it is forty-four, because four
 * digits cost fourteen bits where four alphanumeric characters cost twenty-
 * eight and the second segment's header is only seven. One bit is not much —
 * but a symbology whose largest version holds a hundred and twenty-eight is a
 * symbology where one bit is sometimes a whole version, and this is why:
 * `SN-000123` needs fifty-six bits as one segment and fifty as two.
 *
 * The search is a shortest path, not a greedy scan, and it has to be. Greedily
 * switching to numeric at the first digit loses on `A1B`, where the digit is
 * cheaper to leave in alphanumeric than to give a header of its own. So the
 * state below is (position, mode, position within the current run) and the
 * cost of each step is what extending that run by one character actually
 * costs: four bits then three then three for digits, six then five for
 * alphanumeric pairs, eight for a byte.
 *
 * Everything above is true of every symbology in the QR family, and everything
 * that differs between them is in the one number this class is handed: what a
 * segment header costs in a given mode. Micro QR spends nought to three bits
 * of mode indicator and a character count whose width depends on the version
 * *and* the mode; rMQR always spends three and a count width that depends on
 * the size. Neither difference reaches the search, so neither has a copy of
 * it.
 *
 * Kanji is not among the modes. The QR pipeline does not offer it either, and
 * it needs a Shift-JIS conversion this library does not carry; a payload of
 * Japanese text is encoded as bytes, which is correct and one third larger.
 *
 * The character count indicator cannot overflow in either caller and so is not
 * checked: the widest count Micro QR defines is six bits and no Micro QR
 * symbol holds more than thirty-five characters, and rMQR's widths are sized
 * to their own capacities by the standard.
 *
 * @internal Shared by the Micro QR and rMQR encoding pipelines.
 */
final class Segmentation
{
    /** Bits each further character of a run costs, by mode and run position. */
    private const STEP = [
        Mode::Numeric->value => [4, 3, 3],
        Mode::Alphanumeric->value => [6, 5],
        Mode::Byte->value => [8],
    ];

    /**
     * The cheapest split of $data, or an empty list where the symbol cannot
     * carry the payload at all.
     *
     * @param callable(Mode): ?int $headerBits What opening a segment in this
     *        mode costs — mode indicator plus character count — or null where
     *        the symbol cannot be in that mode.
     * @return list<array{Mode, string}>
     */
    public static function optimal(string $data, callable $headerBits): array
    {
        $paths = self::search($data, $headerBits);
        if ($paths === null) {
            return [];
        }

        [$state, $from] = $paths;

        // Walk the parent pointers back. A step that stayed in the same mode
        // kept the same segment; a step that changed mode, and the step off
        // the front of the payload, closed one.
        $segments = [];
        $run = '';

        for ($i = \strlen($data); $i > 0; $i--) {
            $run = $data[$i - 1] . $run;
            $parent = $from[$i][$state[0]][$state[1]];

            if ($parent === null || $parent[0] !== $state[0]) {
                array_unshift($segments, [Mode::from($state[0]), $run]);
                $run = '';
            }

            if ($parent === null) {
                break;
            }

            $state = $parent;
        }

        return $segments;
    }

    /**
     * What the cheapest split costs, header bits included, or PHP_INT_MAX
     * where the symbol cannot carry the payload.
     *
     * @param callable(Mode): ?int $headerBits
     */
    public static function bits(string $data, callable $headerBits): int
    {
        $paths = self::search($data, $headerBits);

        return $paths === null ? \PHP_INT_MAX : $paths[2];
    }

    public static function covers(Mode $mode, string $character): bool
    {
        return match ($mode) {
            Mode::Numeric => $character >= '0' && $character <= '9',
            Mode::Alphanumeric => strpos(Segment::ALPHANUMERIC, $character) !== false,
            default => true,
        };
    }

    /**
     * The shortest path from the front of the payload to the back.
     *
     * @param callable(Mode): ?int $headerBits
     * @return array{0: array{0: int, 1: int}, 1: array<int, array<int, array<int, array{0: int, 1: int}|null>>>, 2: int}|null
     */
    private static function search(string $data, callable $headerBits): ?array
    {
        $length = \strlen($data);
        if ($length === 0) {
            return null;
        }

        /** @var array<int, int> $headers */
        $headers = [];
        /** @var list<Mode> $modes */
        $modes = [];
        foreach ([Mode::Numeric, Mode::Alphanumeric, Mode::Byte] as $mode) {
            $header = $headerBits($mode);
            if ($header !== null) {
                $modes[] = $mode;
                $headers[$mode->value] = $header;
            }
        }

        /** @var array<int, array<int, array<int, int>>> $cost */
        $cost = [0 => []];
        /** @var array<int, array<int, array<int, array{0: int, 1: int}|null>>> $from */
        $from = [];

        for ($i = 0; $i < $length; $i++) {
            $cost[$i + 1] = [];
            $from[$i + 1] = [];

            foreach ($modes as $mode) {
                if (!self::covers($mode, $data[$i])) {
                    continue;
                }

                $steps = self::STEP[$mode->value];
                $period = \count($steps);

                // Carry on in the mode the previous character was already
                // in, which costs whatever the next character of that run
                // costs and no header at all. This is tried first so that a
                // tie is settled in favour of not switching: two encodings of
                // the same length are equally good and the one with fewer
                // segments is the one other encoders produce, which is worth
                // agreeing with for free.
                foreach ($cost[$i][$mode->value] ?? [] as $phase => $value) {
                    self::relax(
                        $cost[$i + 1],
                        $from[$i + 1],
                        $mode->value,
                        ($phase + 1) % $period,
                        $value + $steps[$phase],
                        [$mode->value, $phase],
                    );
                }
                // Or open a segment here, following the cheapest way of having
                // got this far in some other mode. At the front of the payload
                // there is nothing to follow and the header is the first cost.
                $opening = $i === 0 ? [null, 0] : self::cheapestEnd($cost[$i], $mode->value);
                if ($opening !== null) {
                    self::relax(
                        $cost[$i + 1],
                        $from[$i + 1],
                        $mode->value,
                        1 % $period,
                        $opening[1] + $headers[$mode->value] + $steps[0],
                        $opening[0],
                    );
                }
            }

            if ($cost[$i + 1] === []) {
                return null;
            }
        }

        $state = null;
        $best = \PHP_INT_MAX;
        foreach ($cost[$length] as $mode => $phases) {
            foreach ($phases as $phase => $value) {
                if ($value < $best) {
                    $best = $value;
                    $state = [$mode, $phase];
                }
            }
        }

        return $state === null ? null : [$state, $from, $best];
    }

    /**
     * @param array<int, array<int, int>> $cost
     * @param array<int, array<int, array{0: int, 1: int}|null>> $from
     * @param array{0: int, 1: int}|null $parent
     */
    private static function relax(
        array &$cost,
        array &$from,
        int $mode,
        int $phase,
        int $value,
        ?array $parent,
    ): void {
        if (isset($cost[$mode][$phase]) && $cost[$mode][$phase] <= $value) {
            return;
        }

        $cost[$mode][$phase] = $value;
        $from[$mode][$phase] = $parent;
    }

    /**
     * The cheapest state at this position in any mode but $exclude, which is
     * what a segment opening in $exclude follows.
     *
     * @param array<int, array<int, int>> $cost
     * @return array{0: array{0: int, 1: int}, 1: int}|null
     */
    private static function cheapestEnd(array $cost, int $exclude): ?array
    {
        $best = \PHP_INT_MAX;
        $state = null;

        foreach ($cost as $mode => $phases) {
            if ($mode === $exclude) {
                continue;
            }

            foreach ($phases as $phase => $value) {
                if ($value < $best) {
                    $best = $value;
                    $state = [$mode, $phase];
                }
            }
        }

        return $state === null ? null : [$state, $best];
    }
}
