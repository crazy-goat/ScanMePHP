<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataMatrix;

/**
 * The ECC200 symbol character placement of ISO/IEC 16022 Annex F.
 *
 * Codeword bits are not laid out in reading order. Each codeword occupies an
 * eight-module "utah" shape, and those shapes are swept diagonally up and down
 * the mapping matrix, wrapping around the edges, so that a local smudge damages
 * bits belonging to many codewords instead of destroying one outright. Four
 * corner cases exist because the diagonal sweep does not close cleanly on every
 * symbol shape — corner 4 only ever fires on the 8×18 and 16×36 sizes, and
 * dropping it would leave those two symbols with an unplaced codeword.
 *
 * The layout depends only on the matrix size, so it is built once per size as a
 * map from module to (codeword, bit) and then applied to codewords. That makes
 * it cacheable across encodes and, more usefully, makes the one property worth
 * checking testable on its own: the map must be a bijection between modules and
 * codeword bits.
 *
 * @internal
 */
final class Placement
{
    /** Map entry for a module the standard fixes rather than fills with data. */
    public const FIXED_DARK = -1;

    public const FIXED_LIGHT = -2;

    /** @var array<string, list<list<int>>> Cached maps by "rows x cols" */
    private static array $maps = [];

    /** @var list<list<int|null>> */
    private array $map;

    /** One-based index of the codeword currently being placed. */
    private int $codeword = 1;

    private function __construct(
        private readonly int $rows,
        private readonly int $cols,
    ) {
        $this->map = array_fill(0, $rows, array_fill(0, $cols, null));
    }

    /**
     * Module → codeword bit map for a mapping matrix of $rows × $cols.
     *
     * Each entry is `codewordIndex * 8 + bitIndex`, bit 0 being the most
     * significant, or one of the FIXED_* constants.
     *
     * @return list<list<int>>
     */
    public static function map(int $rows, int $cols): array
    {
        return self::$maps[$rows . 'x' . $cols] ??= (new self($rows, $cols))->build();
    }

    /**
     * Lay $codewords into a mapping matrix.
     *
     * @param list<int> $codewords
     * @return list<string> One '0'/'1' string per row
     */
    public static function place(array $codewords, int $rows, int $cols): array
    {
        $matrix = [];
        foreach (self::map($rows, $cols) as $row) {
            $line = '';
            foreach ($row as $entry) {
                $line .= match (true) {
                    $entry === self::FIXED_DARK => '1',
                    $entry === self::FIXED_LIGHT => '0',
                    default => ($codewords[intdiv($entry, 8)] >> (7 - $entry % 8)) & 1,
                };
            }
            $matrix[] = $line;
        }

        return $matrix;
    }

    /** @return list<list<int>> */
    private function build(): array
    {
        $row = 4;
        $col = 0;

        do {
            $this->cornerCases($row, $col);

            // Diagonally up and to the right. $col only grows here, so it needs
            // no lower bound; the standard's pseudocode checks one anyway.
            do {
                if ($row < $this->rows && $this->map[$row][$col] === null) {
                    $this->utah($row, $col);
                }
                $row -= 2;
                $col += 2;
            } while ($row >= 0 && $col < $this->cols);
            $row++;
            $col += 3;

            // Diagonally down and to the left. Here $row can start negative,
            // so unlike the upward sweep it does need the lower bound.
            do {
                if ($row >= 0 && $col < $this->cols && $this->map[$row][$col] === null) {
                    $this->utah($row, $col);
                }
                $row += 2;
                $col -= 2;
            } while ($row < $this->rows && $col >= 0);
            $row += 3;
            $col++;
        } while ($row < $this->rows || $col < $this->cols);

        $this->fixedCorner();

        return $this->complete();
    }

    /**
     * The four shapes that close a sweep the diagonal cannot.
     *
     * Which one applies depends on where the sweep has arrived and on the
     * matrix width; at most one fires per pass.
     */
    private function cornerCases(int $row, int $col): void
    {
        $rows = $this->rows;
        $cols = $this->cols;

        if ($row === $rows && $col === 0) {
            $this->shape(
                [$rows - 1, 0],
                [$rows - 1, 1],
                [$rows - 1, 2],
                [0, $cols - 2],
                [0, $cols - 1],
                [1, $cols - 1],
                [2, $cols - 1],
                [3, $cols - 1],
            );
        }

        if ($row === $rows - 2 && $col === 0 && $cols % 4 !== 0) {
            $this->shape(
                [$rows - 3, 0],
                [$rows - 2, 0],
                [$rows - 1, 0],
                [0, $cols - 4],
                [0, $cols - 3],
                [0, $cols - 2],
                [0, $cols - 1],
                [1, $cols - 1],
            );
        }

        if ($row === $rows - 2 && $col === 0 && $cols % 8 === 4) {
            $this->shape(
                [$rows - 3, 0],
                [$rows - 2, 0],
                [$rows - 1, 0],
                [0, $cols - 2],
                [0, $cols - 1],
                [1, $cols - 1],
                [2, $cols - 1],
                [3, $cols - 1],
            );
        }

        if ($row === $rows + 4 && $col === 2 && $cols % 8 === 0) {
            $this->shape(
                [$rows - 1, 0],
                [$rows - 1, $cols - 1],
                [0, $cols - 3],
                [0, $cols - 2],
                [0, $cols - 1],
                [1, $cols - 3],
                [1, $cols - 2],
                [1, $cols - 1],
            );
        }
    }

    /** The eight-module shape a codeword occupies, anchored at its lower right. */
    private function utah(int $row, int $col): void
    {
        $this->shape(
            [$row - 2, $col - 2],
            [$row - 2, $col - 1],
            [$row - 1, $col - 2],
            [$row - 1, $col - 1],
            [$row - 1, $col],
            [$row, $col - 2],
            [$row, $col - 1],
            [$row, $col],
        );
    }

    /**
     * Place the current codeword's eight bits, in order, at the given modules.
     *
     * @param array{int, int} ...$modules Exactly eight (row, column) pairs
     */
    private function shape(array ...$modules): void
    {
        foreach ($modules as $bit => [$row, $col]) {
            $this->assign($row, $col, $bit);
        }

        $this->codeword++;
    }

    /**
     * Record one bit of the current codeword, wrapping modules that fall off
     * an edge round to the opposite side. The offset is what makes the wrapped
     * shapes interlock instead of colliding.
     */
    private function assign(int $row, int $col, int $bit): void
    {
        if ($row < 0) {
            $row += $this->rows;
            $col += 4 - (($this->rows + 4) % 8);
        }
        if ($col < 0) {
            $col += $this->cols;
            $row += 4 - (($this->cols + 4) % 8);
        }

        $this->map[$row][$col] = ($this->codeword - 1) * 8 + $bit;
    }

    /**
     * Sizes whose data region holds four modules more than their codewords
     * finish with a fixed 2×2 checkerboard in the bottom-right corner.
     */
    private function fixedCorner(): void
    {
        if ($this->map[$this->rows - 1][$this->cols - 1] !== null) {
            return;
        }

        $this->map[$this->rows - 1][$this->cols - 1] = self::FIXED_DARK;
        $this->map[$this->rows - 2][$this->cols - 2] = self::FIXED_DARK;
        $this->map[$this->rows - 1][$this->cols - 2] = self::FIXED_LIGHT;
        $this->map[$this->rows - 2][$this->cols - 1] = self::FIXED_LIGHT;
    }

    /** @return list<list<int>> */
    private function complete(): array
    {
        $complete = [];
        foreach ($this->map as $index => $line) {
            foreach ($line as $column => $entry) {
                if ($entry === null) {
                    throw new \LogicException(sprintf(
                        'Placement left module (%d, %d) of a %d x %d matrix unassigned',
                        $index,
                        $column,
                        $this->rows,
                        $this->cols
                    ));
                }
            }
            /** @var list<int> $line */
            $complete[] = $line;
        }

        return $complete;
    }
}
