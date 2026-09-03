<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\MicroQr;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;

/**
 * Where a Micro QR symbol's modules go.
 *
 * The top-left nine by nine is entirely function pattern — finder, separator
 * and format information — and so are the top row and the left column, which
 * carry the two timing patterns. Everything else is data, and it turns out
 * that "everything else" is exactly the capacity the version tables promise:
 * an M1 symbol has thirty-six free modules and holds thirty-six bits, an M3
 * has a hundred and thirty-two and holds a hundred and thirty-two. That
 * agreement is asserted in the tests rather than assumed here, because it is
 * the one property that ties {@see Specs}' tables to this file's geometry, and
 * a mistake in either one alone would break it.
 *
 * The zigzag that fills those modules is QR's, unchanged: two columns at a
 * time from the right-hand edge, upwards then downwards, with the right module
 * of each pair written first. What is not QR's is that no column is skipped
 * on the way — QR steps over its vertical timing pattern at column six, and
 * Micro QR's timing runs down column zero instead, which the loop never
 * reaches.
 *
 * @internal Part of the Micro QR encoding pipeline.
 */
final class Layout
{
    /**
     * BCH(15,5) generator and the constant every format information value is
     * XORed with. The XOR is what stops an all-light symbol from reading as a
     * valid format, and it differs from QR's 0x5412 — reusing QR's would
     * produce a symbol that looks perfectly well-formed and decodes as the
     * wrong version.
     */
    private const FORMAT_GENERATOR = 0b101_0011_0111;

    private const FORMAT_XOR = 0b100_0100_0100_0101;

    private const FORMAT_BITS = 15;

    /** @var list<list<int>> Row-major modules, 0 light and 1 dark. */
    private array $modules;

    private readonly int $size;

    public function __construct(private readonly int $version)
    {
        $this->size = Specs::size($version);
        $this->modules = array_fill(0, $this->size, array_fill(0, $this->size, 0));

        $this->drawFinder();
        $this->drawTiming();
    }

    /**
     * Whether this module belongs to a function pattern and so carries neither
     * data nor a mask.
     */
    public function isFunction(int $row, int $column): bool
    {
        return $row === 0
            || $column === 0
            || ($row <= 8 && $column <= 8);
    }

    /** Free modules, which is what the data and its error correction fill. */
    public function capacity(): int
    {
        $free = 0;
        for ($row = 0; $row < $this->size; $row++) {
            for ($column = 0; $column < $this->size; $column++) {
                if (!$this->isFunction($row, $column)) {
                    $free++;
                }
            }
        }

        return $free;
    }

    /**
     * Lay the codewords into the zigzag, most significant bit first.
     *
     * @param list<int> $codewords Data codewords then error correction ones.
     */
    public function place(array $codewords, ?ErrorCorrectionLevel $level): void
    {
        $bits = [];
        $dataCodewords = Specs::dataCodewords($this->version, $level);

        foreach ($codewords as $index => $codeword) {
            // The last data codeword is four bits at M1 and M3; the error
            // correction codewords that follow it are always eight.
            // Every codeword is a byte; a final nibble is the top half of one.
            $width = $index === $dataCodewords - 1 && Specs::endsOnANibble($this->version) ? 4 : 8;
            for ($bit = 7; $bit >= 8 - $width; $bit--) {
                $bits[] = ($codeword >> $bit) & 1;
            }
        }

        $next = 0;
        $upwards = true;

        for ($column = $this->size - 1; $column >= 1; $column -= 2) {
            $rows = $upwards ? range($this->size - 1, 0) : range(0, $this->size - 1);

            foreach ($rows as $row) {
                foreach ([$column, $column - 1] as $target) {
                    if ($this->isFunction($row, $target)) {
                        continue;
                    }

                    $this->modules[$row][$target] = $bits[$next++] ?? 0;
                }
            }

            $upwards = !$upwards;
        }
    }

    /**
     * The mask ISO/IEC 18004 clause 7.8.3.2 chooses.
     *
     * QR scores four penalties and takes the lowest; Micro QR counts the dark
     * modules along the two edges furthest from the finder and takes the
     * *highest* score. The reasoning is the mirror image: QR is avoiding
     * patterns that confuse a scanner mid-symbol, while a Micro QR symbol has
     * only one finder and the two far edges are all a scanner has to work out
     * where the symbol ends, so dark modules there help rather than hurt.
     */
    public function bestMask(): int
    {
        $best = 0;
        $bestScore = -1;

        for ($mask = 0; $mask < Specs::MASKS; $mask++) {
            $this->mask($mask);
            $score = $this->edgeScore();
            $this->mask($mask);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $mask;
            }
        }

        return $best;
    }

    /** Apply a mask; applying it twice undoes it. */
    public function mask(int $mask): void
    {
        for ($row = 0; $row < $this->size; $row++) {
            for ($column = 0; $column < $this->size; $column++) {
                if (!$this->isFunction($row, $column) && self::masks($mask, $row, $column)) {
                    $this->modules[$row][$column] ^= 1;
                }
            }
        }
    }

    /**
     * Micro QR's four masks, which are QR's numbers 1, 4, 6 and 7 renumbered
     * 0 to 3. The renumbering is the trap: a symbol masked with QR's pattern 2
     * and labelled 2 in its format information is legal-looking and wrong.
     */
    public static function masks(int $mask, int $row, int $column): bool
    {
        return match ($mask) {
            0 => $row % 2 === 0,
            1 => (intdiv($row, 2) + intdiv($column, 3)) % 2 === 0,
            2 => ((($row * $column) % 2) + (($row * $column) % 3)) % 2 === 0,
            default => ((($row + $column) % 2) + (($row * $column) % 3)) % 2 === 0,
        };
    }

    /**
     * Write the fifteen format bits: the first eight along row eight from
     * column one, the remaining seven up column eight from row seven.
     */
    public function formatInformation(int $symbolNumber, int $mask): void
    {
        $format = self::format($symbolNumber, $mask);

        for ($i = 0; $i < 8; $i++) {
            $this->modules[8][1 + $i] = ($format >> (self::FORMAT_BITS - 1 - $i)) & 1;
        }

        for ($i = 0; $i < 7; $i++) {
            $this->modules[7 - $i][8] = ($format >> (6 - $i)) & 1;
        }
    }

    /** The fifteen-bit format information for a symbol number and mask. */
    public static function format(int $symbolNumber, int $mask): int
    {
        $data = ($symbolNumber << 2) | $mask;

        $remainder = $data << 10;
        for ($bit = 14; $bit >= 10; $bit--) {
            if ((($remainder >> $bit) & 1) === 1) {
                $remainder ^= self::FORMAT_GENERATOR << ($bit - 10);
            }
        }

        return (($data << 10) | $remainder) ^ self::FORMAT_XOR;
    }

    /** @return list<list<bool>> */
    public function toBooleans(): array
    {
        return array_map(
            static fn (array $row): array => array_map(static fn (int $module): bool => $module === 1, $row),
            $this->modules,
        );
    }

    /**
     * Dark modules along the right-hand column and the bottom row, combined
     * the way the standard combines them: the smaller count is worth sixteen
     * times the larger, so a symbol dark on both edges beats one dark on
     * either alone.
     */
    private function edgeScore(): int
    {
        $right = 0;
        $bottom = 0;
        for ($i = 1; $i < $this->size; $i++) {
            $right += $this->modules[$i][$this->size - 1];
            $bottom += $this->modules[$this->size - 1][$i];
        }

        return $right <= $bottom ? $right * 16 + $bottom : $bottom * 16 + $right;
    }

    private function drawFinder(): void
    {
        for ($row = 0; $row < Specs::FINDER_SIZE; $row++) {
            for ($column = 0; $column < Specs::FINDER_SIZE; $column++) {
                $ring = max(abs($row - 3), abs($column - 3));
                $this->modules[$row][$column] = $ring === 2 ? 0 : 1;
            }
        }
    }

    private function drawTiming(): void
    {
        for ($i = 8; $i < $this->size; $i++) {
            $this->modules[0][$i] = 1 - ($i % 2);
            $this->modules[$i][0] = 1 - ($i % 2);
        }
    }
}
