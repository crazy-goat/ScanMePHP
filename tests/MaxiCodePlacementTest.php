<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoding\MaxiCode\Placement;
use CrazyGoat\ScanMePHP\Encoding\MaxiCode\Specs;
use PHPUnit\Framework\TestCase;

/**
 * The measured module map, checked against the geometry it has to fit.
 *
 * Placement.php is generated — tools/maxicode_placement.py recovers it from an
 * independent encoder rather than transcribing ISO/IEC 16023 — so what is worth
 * asserting here is not its contents but its shape. A table that is a bijection
 * onto real lattice positions, keeps clear of the orientation patterns and
 * never touches a column that does not exist cannot be wrong in any of the ways
 * a hand-edited table goes wrong, and those are properties no amount of
 * comparing modules to a fixture would state.
 *
 * The ninety-six positions left over are the bullseye's, and counting them is
 * the check that nothing is missing: 974 lattice positions, 864 codeword bits,
 * 13 orientation modules, and the rest is where the rings go.
 */
class MaxiCodePlacementTest extends TestCase
{
    public function testEveryBitHasItsOwnPositionInTheLattice(): void
    {
        $seen = [];

        for ($codeword = 0; $codeword < Specs::CODEWORDS; $codeword++) {
            $cells = Placement::cells($codeword);
            $this->assertCount(Specs::CODEWORD_BITS, $cells, "codeword {$codeword}");

            foreach ($cells as $bit => [$row, $column]) {
                $this->assertGreaterThanOrEqual(0, $row);
                $this->assertLessThan(Specs::ROWS, $row);
                $this->assertGreaterThanOrEqual(0, $column);
                $this->assertLessThan(
                    Specs::columns($row),
                    $column,
                    sprintf('codeword %d bit %d is in a column row %d does not have', $codeword, $bit, $row)
                );

                $key = $row . ',' . $column;
                $this->assertArrayNotHasKey(
                    $key,
                    $seen,
                    sprintf('module %s carries both %s and codeword %d bit %d', $key, $seen[$key] ?? '', $codeword, $bit)
                );
                $seen[$key] = "codeword {$codeword} bit {$bit}";
            }
        }

        $this->assertCount(Specs::CODEWORDS * Specs::CODEWORD_BITS, $seen);
    }

    public function testTheOrientationPatternsAreNotWrittenOver(): void
    {
        $carried = [];
        for ($codeword = 0; $codeword < Specs::CODEWORDS; $codeword++) {
            foreach (Placement::cells($codeword) as [$row, $column]) {
                $carried[$row . ',' . $column] = true;
            }
        }

        $fixed = Placement::fixedDark();
        $this->assertNotSame([], $fixed);

        foreach ($fixed as [$row, $column]) {
            $this->assertArrayNotHasKey(
                $row . ',' . $column,
                $carried,
                sprintf('the orientation module at %d,%d is also a codeword bit', $row, $column)
            );
            $this->assertLessThan(Specs::columns($row), $column);
        }
    }

    /**
     * What is left over once the codewords and the orientation patterns are
     * placed is the bullseye's, and it is a connected blob at the centre rather
     * than modules scattered about.
     */
    public function testTheRemainingPositionsAreTheBullseye(): void
    {
        $free = [];
        foreach (Specs::positions() as [$row, $column]) {
            $free[$row . ',' . $column] = [$row, $column];
        }

        for ($codeword = 0; $codeword < Specs::CODEWORDS; $codeword++) {
            foreach (Placement::cells($codeword) as [$row, $column]) {
                unset($free[$row . ',' . $column]);
            }
        }
        foreach (Placement::fixedDark() as [$row, $column]) {
            unset($free[$row . ',' . $column]);
        }

        $this->assertSame(
            974 - Specs::CODEWORDS * Specs::CODEWORD_BITS - \count(Placement::fixedDark()),
            \count($free)
        );

        foreach ($free as [$row, $column]) {
            $this->assertLessThanOrEqual(
                7,
                max(abs($row - Specs::BULLSEYE_ROW), abs($column - Specs::BULLSEYE_COLUMN)),
                sprintf('the free module at %d,%d is nowhere near the bullseye', $row, $column)
            );
        }
    }

    public function testTheLatticeHas974Positions(): void
    {
        $this->assertCount(974, iterator_to_array(Specs::positions(), false));
    }

    /**
     * The data codewords of a symbol, read back out of its modules.
     *
     * The primary message's nine data codewords come first and the secondary
     * message's 84 after them, with the mode codeword and all three blocks of
     * error correction skipped — which is the same split the encoder makes,
     * read in the other direction.
     *
     * @return list<int>
     */
    public static function dataCodewords(string $modules): array
    {
        $indices = range(1, Specs::PRIMARY_CODEWORDS - 1);
        $first = Specs::PRIMARY_CODEWORDS + Specs::PRIMARY_CHECK_CODEWORDS;
        $indices = [...$indices, ...range($first, $first + Specs::SECONDARY_DATA_CODEWORDS - 1)];

        $data = [];
        foreach ($indices as $index) {
            $value = 0;
            foreach (Placement::cells($index) as $bit => [$row, $column]) {
                if ($modules[$row * Specs::COLUMNS + $column] === '1') {
                    $value |= 1 << $bit;
                }
            }
            $data[] = $value;
        }

        return $data;
    }
}
