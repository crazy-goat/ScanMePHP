<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Aztec;

use CrazyGoat\ScanMePHP\Encoding\ReedSolomonGf2m;
use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;

/**
 * A payload to a finished Aztec symbol.
 *
 * The steps, in the order they have to happen:
 *
 *  1. {@see HighLevelEncoder} turns bytes into the shortest bit stream.
 *  2. The bits are **stuffed**: inside every codeword, a run of all zeros or
 *     all ones across the leading bits gets a complementary bit inserted, so
 *     that no codeword is entirely one value. A scanner needs that to keep its
 *     bearings; an encoder that skips it shifts every codeword after the run.
 *  3. The symbol size is chosen. Stuffing depends on the codeword width and the
 *     width depends on the size, so the search restuffs whenever it crosses
 *     into a different width. There is no way round that ordering.
 *  4. Error correction fills whatever the data left over, which is why the
 *     percentage a caller asks for is a floor and the symbol usually beats it.
 *  5. The mode message records the layer count and the data word count, with
 *     its own error correction over GF(16).
 *  6. {@see Layout} places all of it.
 *
 * @internal Part of the Aztec encoding pipeline.
 */
final class AztecEncoder
{
    /**
     * The floor ISO/IEC 24778 recommends, and what every encoder we compare
     * against uses when not told otherwise.
     */
    public const DEFAULT_ERROR_CORRECTION_PERCENT = 23;

    /**
     * Three codewords on top of the percentage, also from the recommendation.
     * It matters most for short payloads, where a percentage of very little is
     * very little and a symbol with two check words is not worth printing.
     */
    private const ERROR_CORRECTION_FLOOR_BITS = 11;

    /**
     * A compact symbol's mode message has six bits for the data word count, so
     * it cannot describe more than this however much room the layers have.
     */
    private const MAX_COMPACT_DATA_WORDS = 64;

    /**
     * @return array{matrix: array<int, array<int, bool>>, layers: int, compact: bool, dataWords: int, totalWords: int}
     */
    public function encode(
        string $data,
        int $errorCorrectionPercent = self::DEFAULT_ERROR_CORRECTION_PERCENT,
        ?int $size = null,
    ): array {
        $bits = (new HighLevelEncoder())->encode($data);
        $eccBits = intdiv(count($bits) * $errorCorrectionPercent, 100) + self::ERROR_CORRECTION_FLOOR_BITS;

        [$layers, $compact, $stuffed] = $this->chooseSymbol($bits, $eccBits, $size);

        $wordBits = Specs::wordBits($layers, $compact);
        $totalWords = Specs::totalWords($layers, $compact);
        $dataWords = intdiv(count($stuffed), $wordBits);

        $message = $this->addCheckWords($stuffed, $wordBits, $totalWords, Specs::totalBits($layers, $compact));
        $mode = $this->modeMessage($layers, $compact, $dataWords);

        return [
            'matrix' => (new Layout($layers, $compact))->build($mode, $message),
            'layers' => $layers,
            'compact' => $compact,
            'dataWords' => $dataWords,
            'totalWords' => $totalWords,
        ];
    }

    /**
     * The smallest symbol that holds the data with the error correction asked
     * for, trying compact sizes before full ones.
     *
     * Compact 1 to 4 come first and then full 4 to 32, which looks like a gap
     * and is not: a full symbol of one, two or three layers holds *less* than a
     * compact one of four, so it would never be the answer.
     *
     * @param list<int> $bits
     * @return array{int, bool, list<int>}
     */
    private function chooseSymbol(array $bits, int $eccBits, ?int $size): array
    {
        $pinned = $size === null ? null : Specs::fromSize($size);
        $wordBits = 0;
        $stuffed = null;

        for ($step = 0; $step <= Specs::MAX_FULL_LAYERS; $step++) {
            $compact = $step <= 3;
            $layers = $compact ? $step + 1 : $step;

            if ($pinned !== null && [$layers, $compact] !== $pinned) {
                continue;
            }

            $totalBits = Specs::totalBits($layers, $compact);
            if ($pinned === null && count($bits) + $eccBits > $totalBits) {
                continue;
            }

            if ($stuffed === null || $wordBits !== Specs::wordBits($layers, $compact)) {
                $wordBits = Specs::wordBits($layers, $compact);
                $stuffed = $this->stuffBits($bits, $wordBits);
            }

            $usableBits = $totalBits - $totalBits % $wordBits;
            if ($compact && count($stuffed) > $wordBits * self::MAX_COMPACT_DATA_WORDS && $pinned === null) {
                continue;
            }

            if ($pinned !== null) {
                if (count($stuffed) > $usableBits) {
                    throw DataTooLargeException::forSymbolSize(
                        intdiv(count($stuffed), $wordBits),
                        intdiv($usableBits, $wordBits),
                        sprintf('a %s Aztec symbol of %d layers', $compact ? 'compact' : 'full', $layers),
                    );
                }

                return [$layers, $compact, $stuffed];
            }

            if (count($stuffed) + $eccBits <= $usableBits) {
                return [$layers, $compact, $stuffed];
            }
        }

        $largest = Specs::totalWords(Specs::MAX_FULL_LAYERS, false);

        throw DataTooLargeException::forSymbolSize(
            intdiv(count($bits) + $eccBits, Specs::wordBits(Specs::MAX_FULL_LAYERS, false)),
            $largest,
            'the largest Aztec symbol',
        );
    }

    /**
     * Insert a complementary bit wherever a codeword's leading bits are all the
     * same value.
     *
     * The rule is stated over codewords rather than over the whole stream, so a
     * run only counts when it fills one codeword's leading bits — which is why
     * this walks in steps of the codeword width and, when it stuffs, advances
     * one bit less. Bits past the end of the message read as ones, so the final
     * partial codeword is padded before the rule is applied to it.
     *
     * @param list<int> $bits
     * @return list<int>
     */
    private function stuffBits(array $bits, int $wordBits): array
    {
        $count = count($bits);
        $out = [];

        for ($i = 0; $i < $count; $i += $wordBits) {
            $leading = [];
            for ($j = 0; $j < $wordBits - 1; $j++) {
                $leading[] = $i + $j >= $count ? 1 : $bits[$i + $j];
            }

            $ones = array_sum($leading);
            if ($ones === $wordBits - 1 || $ones === 0) {
                foreach ($leading as $bit) {
                    $out[] = $bit;
                }
                $out[] = $ones === 0 ? 1 : 0;
                $i--;

                continue;
            }

            for ($j = 0; $j < $wordBits; $j++) {
                $out[] = $i + $j >= $count ? 1 : $bits[$i + $j];
            }
        }

        return $out;
    }

    /**
     * The data words followed by their check words, right-aligned in the
     * spiral.
     *
     * A layer's bit count is not always a whole number of codewords — a compact
     * one-layer symbol has 104 positions and holds seventeen six-bit words,
     * which is 102 — so the leftover positions go at the front and carry
     * nothing.
     *
     * @param list<int> $stuffed
     * @return list<int>
     */
    private function addCheckWords(array $stuffed, int $wordBits, int $totalWords, int $totalBits): array
    {
        $words = [];
        $counter = count($stuffed);
        for ($i = 0; $i < $counter; $i += $wordBits) {
            $word = 0;
            for ($j = 0; $j < $wordBits; $j++) {
                $word = ($word << 1) | $stuffed[$i + $j];
            }
            $words[] = $word;
        }

        $check = (new ReedSolomonGf2m($wordBits))->encode($words, $totalWords - count($words));

        $bits = array_fill(0, $totalBits % $wordBits, 0);
        foreach ([...$words, ...$check] as $word) {
            for ($i = $wordBits - 1; $i >= 0; $i--) {
                $bits[] = ($word >> $i) & 1;
            }
        }

        return $bits;
    }

    /**
     * The layer count and data word count, with check words over GF(16).
     *
     * @return list<int>
     */
    private function modeMessage(int $layers, bool $compact, int $dataWords): array
    {
        $bits = [];
        $push = static function (int $value, int $width) use (&$bits): void {
            for ($i = $width - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };

        if ($compact) {
            $push($layers - 1, 2);
            $push($dataWords - 1, 6);
        } else {
            $push($layers - 1, 5);
            $push($dataWords - 1, 11);
        }

        return $this->addCheckWords($bits, 4, intdiv(Specs::modeMessageBits($compact), 4), Specs::modeMessageBits($compact));
    }
}
