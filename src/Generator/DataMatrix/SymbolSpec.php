<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataMatrix;

/**
 * One ECC200 symbol size.
 *
 * A Data Matrix is built from one or more equally sized data regions, each
 * wrapped in two modules of finder pattern — the solid L on the left and
 * bottom, the alternating clock track on the top and right. So the symbol is
 * (regionRows + 2) × regionsDown by (regionCols + 2) × regionsAcross, and the
 * usable interior is what the codewords are laid into.
 *
 * @internal
 */
final class SymbolSpec
{
    public function __construct(
        public readonly int $rows,
        public readonly int $cols,
        public readonly int $regionRows,
        public readonly int $regionCols,
        public readonly int $dataWords,
        public readonly int $eccWords,
        public readonly int $blocks,
    ) {
    }

    public function isSquare(): bool
    {
        return $this->rows === $this->cols;
    }

    public function regionsDown(): int
    {
        return intdiv($this->rows, $this->regionRows + 2);
    }

    public function regionsAcross(): int
    {
        return intdiv($this->cols, $this->regionCols + 2);
    }

    /** Height of the mapping matrix, i.e. the data regions stacked without their finders. */
    public function mappingRows(): int
    {
        return $this->regionRows * $this->regionsDown();
    }

    public function mappingCols(): int
    {
        return $this->regionCols * $this->regionsAcross();
    }

    public function totalWords(): int
    {
        return $this->dataWords + $this->eccWords;
    }

    public function eccPerBlock(): int
    {
        return intdiv($this->eccWords, $this->blocks);
    }

    /**
     * How many data codewords each interleaved block holds.
     *
     * Blocks are filled by striding through the codeword stream, so unequal
     * sizes fall out on their own — 144×144 ends up with eight blocks of 156
     * and two of 155 without a special case.
     *
     * @return list<int>
     */
    public function blockSizes(): array
    {
        $sizes = [];
        for ($block = 0; $block < $this->blocks; $block++) {
            $sizes[] = (int) ceil(($this->dataWords - $block) / $this->blocks);
        }

        return $sizes;
    }

    public function name(): string
    {
        return $this->rows . 'x' . $this->cols;
    }
}
