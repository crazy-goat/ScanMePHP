<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Rmqr;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;

/**
 * Where every module of an rMQR symbol goes.
 *
 * The function patterns are QR's, rearranged for a rectangle: one finder in
 * the top-left corner, a five-module sub-finder in the bottom-right one, and
 * timing patterns running the whole way along all four edges rather than QR's
 * two internal lines. Between them, one alignment pattern per twenty-odd
 * modules of width, each a three-by-three ring at the top and another at the
 * bottom joined by a vertical timing line — which is the part that makes a
 * long thin symbol readable at all, since without it a scanner has nothing to
 * hold onto across a hundred and thirty-nine modules of data.
 *
 * Two things here are worth stating because they are not what QR does:
 *
 *  - **There is one mask, not eight.** rMQR fixes the pattern QR numbers 4,
 *    so there is no scoring pass and no mask number in the format information.
 *    The nine bits QR spends saying which mask it chose are spent here on
 *    saying which of the thirty-two sizes the symbol is, which a reader cannot
 *    work out from a rectangle the way it can from a square.
 *
 *  - **The format information is written twice**, once beside each of the two
 *    finders, and the two copies are masked with *different* patterns. That is
 *    not redundancy for its own sake: a symbol seventeen modules tall and a
 *    hundred and thirty-nine wide is one that gets bent, and the two copies
 *    are as far apart as the symbol allows.
 *
 * @internal Part of the rMQR encoding pipeline.
 */
final class Layout
{
    /** BCH(18,6) generator for the format information. */
    private const FORMAT_GENERATOR = 0b1_1111_0010_0101;

    /** The two copies are masked differently; copy one first. */
    private const FORMAT_MASK = [0b01_1111_1010_1011_0010, 0b10_0000_1010_0111_1011];

    private const FORMAT_BITS = 18;

    private const FORMAT_DATA_BITS = 6;

    private readonly int $height;

    private readonly int $width;

    /** @var list<list<int>> 0 light, 1 dark. */
    private array $modules;

    /** @var array<int, true> Function and format cells, keyed row * width + column. */
    private array $reserved = [];

    public function __construct(private readonly int $index)
    {
        $this->height = Specs::height($index);
        $this->width = Specs::width($index);
        $this->modules = array_fill(0, $this->height, array_fill(0, $this->width, 0));

        $this->drawTiming();
        $this->drawSubFinder();
        $this->drawAlignment();
        $this->drawCorners();
        $this->drawFinder();
        $this->reserveFormat();
    }

    /** Modules available to the codeword stream. */
    public function capacity(): int
    {
        return $this->height * $this->width - \count($this->reserved);
    }

    /**
     * Writes the interleaved codeword stream, masking as it goes.
     *
     * The order is QR's zigzag read on a rectangle: column pairs taken from
     * the right edge two at a time, each pair traversed in the opposite
     * direction to the one before it, and the right module of a pair written
     * before the left. Pairs that turn out to hold no data at all — the ones
     * swallowed by the sub-finder and the second format copy — still flip the
     * direction, which is why the first pair that does hold data runs upward
     * in some sizes and downward in others.
     *
     * @param list<int> $codewords
     */
    public function place(array $codewords): void
    {
        $bits = [];
        foreach ($codewords as $codeword) {
            for ($bit = 7; $bit >= 0; $bit--) {
                $bits[] = $codeword >> $bit & 1;
            }
        }

        $index = 0;
        $upward = true;

        for ($right = $this->width - 1; $right > 0; $right -= 2) {
            $rows = $upward
                ? range($this->height - 1, 0)
                : range(0, $this->height - 1);

            foreach ($rows as $row) {
                foreach ([$right, $right - 1] as $column) {
                    if (isset($this->reserved[$row * $this->width + $column])) {
                        continue;
                    }

                    // Anything past the end of the stream is a remainder
                    // module, which is a light module before masking.
                    $value = $bits[$index++] ?? 0;
                    $this->modules[$row][$column] = $value ^ (self::mask($row, $column) ? 1 : 0);
                }
            }

            $upward = !$upward;
        }
    }

    /** The only mask rMQR defines, which is the one QR numbers 4. */
    public static function mask(int $row, int $column): bool
    {
        return (intdiv($row, 2) + intdiv($column, 3)) % 2 === 0;
    }

    /** Writes both copies of the format information. */
    public function formatInformation(ErrorCorrectionLevel $level): void
    {
        $format = self::format($this->index, $level);

        foreach ([$this->firstFormatCells(), $this->secondFormatCells()] as $copy => $cells) {
            $masked = $format ^ self::FORMAT_MASK[$copy];
            foreach ($cells as $bit => [$row, $column]) {
                $this->modules[$row][$column] = $masked >> $bit & 1;
            }
        }
    }

    /**
     * The eighteen format bits: the size number and the level, BCH-encoded.
     *
     * The six data bits are the size index with the level as their top bit, so
     * an H symbol reads as its size plus thirty-two. They sit in the top six
     * bits of the codeword, the twelve check bits below them, and the whole
     * thing is XORed with a different constant in each of the two copies.
     */
    public static function format(int $index, ErrorCorrectionLevel $level): int
    {
        $data = $index | ($level === ErrorCorrectionLevel::High ? 1 << 5 : 0);
        $value = $data << (self::FORMAT_BITS - self::FORMAT_DATA_BITS);

        for ($bit = self::FORMAT_BITS - 1; $bit >= self::FORMAT_BITS - self::FORMAT_DATA_BITS; $bit--) {
            if ($value >> $bit & 1) {
                $value ^= self::FORMAT_GENERATOR << ($bit - (self::FORMAT_BITS - self::FORMAT_DATA_BITS));
            }
        }

        return ($data << (self::FORMAT_BITS - self::FORMAT_DATA_BITS)) | $value;
    }

    /** @return list<list<bool>> */
    public function toBooleans(): array
    {
        return array_map(
            static fn (array $row): array => array_map(
                static fn (int $module): bool => $module === 1,
                $row,
            ),
            $this->modules,
        );
    }

    /**
     * Copy one, beside the finder: three columns of five running down, then
     * three more modules in a fourth column. Bit nought first.
     *
     * @return list<array{int, int}>
     */
    private function firstFormatCells(): array
    {
        $cells = [];
        foreach ([8, 9, 10] as $column) {
            for ($row = 1; $row <= 5; $row++) {
                $cells[] = [$row, $column];
            }
        }
        for ($row = 1; $row <= 3; $row++) {
            $cells[] = [$row, 11];
        }

        return $cells;
    }

    /**
     * Copy two, beside the sub-finder: the same three columns of five, but
     * the three left over run along a row rather than down a column.
     *
     * @return list<array{int, int}>
     */
    private function secondFormatCells(): array
    {
        $cells = [];
        foreach ([$this->width - 8, $this->width - 7, $this->width - 6] as $column) {
            for ($row = $this->height - 6; $row <= $this->height - 2; $row++) {
                $cells[] = [$row, $column];
            }
        }
        foreach ([$this->width - 5, $this->width - 4, $this->width - 3] as $column) {
            $cells[] = [$this->height - 6, $column];
        }

        return $cells;
    }

    private function reserveFormat(): void
    {
        foreach ([...$this->firstFormatCells(), ...$this->secondFormatCells()] as [$row, $column]) {
            $this->reserved[$row * $this->width + $column] = true;
        }
    }

    private function set(int $row, int $column, int $value): void
    {
        $this->modules[$row][$column] = $value;
        $this->reserved[$row * $this->width + $column] = true;
    }

    /** All four edges, dark on the even index. */
    private function drawTiming(): void
    {
        for ($column = 0; $column < $this->width; $column++) {
            $this->set(0, $column, 1 - $column % 2);
            $this->set($this->height - 1, $column, 1 - $column % 2);
        }

        for ($row = 0; $row < $this->height; $row++) {
            $this->set($row, 0, 1 - $row % 2);
            $this->set($row, $this->width - 1, 1 - $row % 2);
        }
    }

    /** The seven-module finder in the top-left corner, with its separator. */
    private function drawFinder(): void
    {
        for ($row = 0; $row < min(Specs::FINDER_SIZE, $this->height); $row++) {
            for ($column = 0; $column < Specs::FINDER_SIZE; $column++) {
                $ring = max(abs($row - 3), abs($column - 3));
                $this->set($row, $column, $ring === 2 ? 0 : 1);
            }
        }

        for ($row = 0; $row < min(Specs::FINDER_SIZE + 1, $this->height); $row++) {
            $this->set($row, Specs::FINDER_SIZE, 0);
        }

        if ($this->height > Specs::FINDER_SIZE) {
            for ($column = 0; $column <= Specs::FINDER_SIZE; $column++) {
                $this->set(Specs::FINDER_SIZE, $column, 0);
            }
        }
    }

    /** The five-module sub-finder in the bottom-right corner. */
    private function drawSubFinder(): void
    {
        $top = $this->height - Specs::SUB_FINDER_SIZE;
        $left = $this->width - Specs::SUB_FINDER_SIZE;

        for ($row = $top; $row < $this->height; $row++) {
            for ($column = $left; $column < $this->width; $column++) {
                $ring = max(abs($row - ($this->height - 3)), abs($column - ($this->width - 3)));
                $this->set($row, $column, $ring === 1 ? 0 : 1);
            }
        }
    }

    /** A three-by-three ring top and bottom, joined by a vertical timing line. */
    private function drawAlignment(): void
    {
        foreach (Specs::alignment($this->width) as $centre) {
            for ($row = 0; $row < $this->height; $row++) {
                $this->set($row, $centre, 1 - $row % 2);
            }

            foreach ([$centre - 1, $centre, $centre + 1] as $column) {
                foreach ([0, 1, 2, $this->height - 3, $this->height - 2, $this->height - 1] as $row) {
                    $this->set($row, $column, 1);
                }
            }

            $this->set(1, $centre, 0);
            $this->set($this->height - 2, $centre, 0);
        }
    }

    /**
     * The two corners the timing patterns do not finish off.
     *
     * The top-right and bottom-left corners each carry three dark modules and
     * one light one that the alternating edges would otherwise get wrong. They
     * are what tells a reader which end of a rectangle it is looking at, in a
     * symbology where the two long edges look alike.
     */
    private function drawCorners(): void
    {
        $this->set(0, $this->width - 2, 1);
        $this->set(1, $this->width - 1, 1);
        $this->set(1, $this->width - 2, 0);

        $this->set($this->height - 1, 1, 1);
        $this->set($this->height - 2, 0, 1);
        $this->set($this->height - 2, 1, 0);
    }
}
