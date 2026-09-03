<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Pdf417;

/**
 * Draws the codewords into module rows.
 *
 * PDF417's layout is the simplest of any two-dimensional symbology here, and
 * deliberately so: it is a stack of one-dimensional rows, each readable on its
 * own, which is what lets a scanner recover a symbol from a few sweeps across
 * it rather than needing the whole thing in one image. There is no spiral, no
 * mask and no reserved region — every row is start pattern, left row
 * indicator, the row's data, right row indicator, stop pattern.
 *
 * Two things carry the structure. Each row's codewords are drawn from one of
 * three pattern clusters, chosen by the row index, so a scanner that picks up a
 * codeword mid-symbol knows which row it came from. And the row indicators
 * repeat the symbol's shape and error correction level on both sides, rotated
 * by one cluster between them, so any three consecutive rows tell a scanner
 * everything it needs even if it only ever reads one edge.
 *
 * Rows are one module row each here. Their conventional three-module height is
 * presentation rather than data and belongs to the symbol's row heights.
 *
 * @internal Shared encoding primitive, not part of the public API.
 */
final class Layout
{
    public function __construct(
        private readonly int $rows,
        private readonly int $columns,
        private readonly int $level,
    ) {
    }

    /**
     * @param list<int> $codewords Exactly rows * columns of them, row-major
     * @return list<list<bool>>
     */
    public function build(array $codewords): array
    {
        $expected = $this->rows * $this->columns;
        if (\count($codewords) !== $expected) {
            throw new \LogicException(sprintf(
                'A %dx%d symbol holds %d codewords, got %d',
                $this->rows,
                $this->columns,
                $expected,
                \count($codewords),
            ));
        }

        $matrix = [];
        for ($row = 0; $row < $this->rows; $row++) {
            $cluster = Specs::cluster($row);
            $modules = $this->spell(Specs::START_PATTERN, Specs::CODEWORD_MODULES);
            $modules = [...$modules, ...$this->codeword(
                $cluster,
                Specs::leftIndicator($row, $this->rows, $this->columns, $this->level),
            )];

            for ($column = 0; $column < $this->columns; $column++) {
                $modules = [...$modules, ...$this->codeword(
                    $cluster,
                    $codewords[$row * $this->columns + $column],
                )];
            }

            $modules = [...$modules, ...$this->codeword(
                $cluster,
                Specs::rightIndicator($row, $this->rows, $this->columns, $this->level),
            )];
            $matrix[] = [...$modules, ...$this->spell(Specs::STOP_PATTERN, Specs::STOP_MODULES)];
        }

        return $matrix;
    }

    /** @return list<bool> */
    private function codeword(int $cluster, int $value): array
    {
        return $this->spell(
            CodewordPatterns::pattern($cluster, $value),
            Specs::CODEWORD_MODULES,
        );
    }

    /** @return list<bool> */
    private function spell(int $pattern, int $modules): array
    {
        $out = [];
        for ($bit = $modules - 1; $bit >= 0; $bit--) {
            $out[] = ($pattern >> $bit & 1) === 1;
        }

        return $out;
    }
}
