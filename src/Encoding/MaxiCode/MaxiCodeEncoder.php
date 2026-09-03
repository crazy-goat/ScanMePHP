<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\MaxiCode;

use CrazyGoat\ScanMePHP\Encoding\ReedSolomonGf2m;
use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;

/**
 * A payload to a finished MaxiCode symbol.
 *
 * The steps, in the order they have to happen:
 *
 *  1. {@see HighLevelEncoder} turns bytes into the shortest run of six-bit
 *     data codewords, then the run is padded to 93 — or to 84 in the two
 *     structured modes, whose first nine are spent on routing instead.
 *  2. The stream is split. The **primary message** is the mode codeword and
 *     the first nine data codewords, and it gets ten check codewords of its
 *     own; the **secondary message** is the remaining 84, split into its
 *     even-numbered and odd-numbered halves, each with twenty. Two blocks
 *     rather than one is what makes a scratch across neighbouring modules land
 *     half in each half and stay correctable.
 *  3. {@see Placement} writes all 144 codewords into the hexagon lattice, and
 *     the orientation patterns go in on top.
 *
 * All three Reed-Solomon blocks live in GF(64), the same field Aztec uses at
 * one and two layers, so {@see ReedSolomonGf2m} covers them unchanged.
 *
 * The bullseye is not produced here and cannot be: it is three concentric
 * rings, not modules, so it has no representation in a grid of light and dark
 * cells. The symbol reports it as a finder region and the renderer draws it —
 * which is the whole reason MaxiCode needs a module shape of its own.
 */
final class MaxiCodeEncoder
{
    private readonly ReedSolomonGf2m $reedSolomon;

    private readonly HighLevelEncoder $highLevel;

    public function __construct()
    {
        $this->reedSolomon = new ReedSolomonGf2m(Specs::GALOIS_FIELD_BITS);
        $this->highLevel = new HighLevelEncoder();
    }

    /**
     * @param list<int>|null $primary The ten primary codewords for a structured
     *        mode, from {@see StructuredCarrierMessage::primary()}
     * @return array{
     *     matrix: list<list<bool>>,
     *     mode: int,
     *     dataCodewords: int,
     *     padCodewords: int,
     * }
     */
    public function encode(string $data, Mode $mode = Mode::Standard, ?array $primary = null): array
    {
        if ($mode->isStructured() === ($primary === null)) {
            throw new \InvalidArgumentException(
                'A structured mode needs a carrier message and the other modes cannot carry one'
            );
        }

        $encoded = $this->highLevel->encode($data);
        $codewords = $encoded['codewords'];
        $capacity = $mode->capacity();

        if (\count($codewords) > $capacity) {
            throw new DataTooLargeException(sprintf(
                'MaxiCode mode %d holds %d codewords; this payload needs %d',
                $mode->value,
                $capacity,
                \count($codewords),
            ));
        }

        $used = \count($codewords);
        $codewords = $this->pad($codewords, $encoded['set'], $capacity);

        $message = $primary ?? [$mode->value, ...\array_slice($codewords, 0, Specs::PRIMARY_CODEWORDS - 1)];
        $secondary = \array_slice($codewords, $mode->isStructured() ? 0 : Specs::PRIMARY_CODEWORDS - 1);

        $vector = [
            ...$message,
            ...$this->reedSolomon->encode($message, Specs::PRIMARY_CHECK_CODEWORDS),
            ...$secondary,
            ...$this->reedSolomon->encode($this->everyOther($secondary, 0), Specs::SECONDARY_CHECK_CODEWORDS),
            ...$this->reedSolomon->encode($this->everyOther($secondary, 1), Specs::SECONDARY_CHECK_CODEWORDS),
        ];

        return [
            'matrix' => $this->layout($vector),
            'mode' => $mode->value,
            'dataCodewords' => $used,
            'padCodewords' => $capacity - $used,
        ];
    }

    /**
     * Fill the stream out to capacity.
     *
     * Sets C and D have no pad codeword — 33 is a printable character in both —
     * so a stream that ends in one latches to A and pads from there. That latch
     * takes one of the slots being filled, which is why it cannot simply be
     * appended.
     *
     * @param list<int> $codewords
     * @return list<int>
     */
    private function pad(array $codewords, int $set, int $capacity): array
    {
        $missing = $capacity - \count($codewords);
        if ($missing === 0) {
            return $codewords;
        }

        if (CodeSets::pad($set) === null) {
            $codewords[] = CodeSets::LATCH_A;
            $missing--;
            $set = CodeSets::A;
        }

        $pad = CodeSets::pad($set);
        if ($pad === null) {
            throw new \LogicException('code set A always has a pad codeword');
        }

        return [...$codewords, ...array_fill(0, $missing, $pad)];
    }

    /**
     * @param list<int> $codewords
     * @return list<int>
     */
    private function everyOther(array $codewords, int $offset): array
    {
        $out = [];
        for ($i = $offset, $count = \count($codewords); $i < $count; $i += 2) {
            $out[] = $codewords[$i];
        }

        return $out;
    }

    /**
     * @param list<int> $vector
     * @return list<list<bool>>
     */
    private function layout(array $vector): array
    {
        $matrix = [];
        for ($row = 0; $row < Specs::ROWS; $row++) {
            $matrix[] = array_fill(0, Specs::COLUMNS, false);
        }

        foreach (Placement::fixedDark() as [$row, $column]) {
            $matrix[$row][$column] = true;
        }

        foreach ($vector as $index => $codeword) {
            foreach (Placement::cells($index) as $bit => [$row, $column]) {
                if (($codeword >> $bit & 1) === 1) {
                    $matrix[$row][$column] = true;
                }
            }
        }

        return $matrix;
    }
}
