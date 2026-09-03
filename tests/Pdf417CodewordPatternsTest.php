<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoding\Pdf417\CodewordPatterns;
use CrazyGoat\ScanMePHP\Encoding\Pdf417\Specs;
use PHPUnit\Framework\TestCase;

/**
 * The one table in this library that is measured rather than derived.
 *
 * `tools/pdf417_codeword_table.py` records how it was measured and why it had
 * to be: within a cluster, the 929 values are assigned to the 929 patterns in
 * no order that can be computed, and the tool checks the four obvious
 * candidate orderings and finds each of them places at most two known values
 * correctly out of several hundred.
 *
 * So the table is guarded here instead, by the properties a codeword pattern
 * has to have. Together they are strong enough that a corrupted entry cannot
 * slip through: the cluster is re-derived from the bar widths for all 2787
 * entries, every pattern is checked to be seventeen modules of eight
 * alternating elements one to six wide starting with a bar, and each cluster is
 * checked to be a bijection. A wrong entry would have to be a *different*
 * valid codeword pattern in the same cluster that no other value already uses,
 * and the bijection closes that door.
 */
class Pdf417CodewordPatternsTest extends TestCase
{
    /** The reverse lookup, for tests that need to read a symbol back. */
    public static function valueOf(int $cluster, int $pattern): int
    {
        $value = array_search($pattern, CodewordPatterns::cluster($cluster), true);
        if (!\is_int($value)) {
            throw new \RuntimeException(sprintf(
                'No codeword in cluster %d has the pattern %05X',
                $cluster,
                $pattern,
            ));
        }

        return $value;
    }

    public function testEveryPatternIsShapedLikeACodeword(): void
    {
        foreach ([0, 3, 6] as $cluster) {
            foreach (CodewordPatterns::cluster($cluster) as $value => $pattern) {
                $where = sprintf('cluster %d value %d', $cluster, $value);
                $widths = $this->widths($pattern);

                $this->assertCount(8, $widths, $where . ': four bars and four spaces');
                $this->assertSame(
                    Specs::CODEWORD_MODULES,
                    array_sum($widths),
                    $where . ': seventeen modules',
                );
                foreach ($widths as $element => $width) {
                    $this->assertGreaterThanOrEqual(1, $width, sprintf('%s: element %d', $where, $element));
                    $this->assertLessThanOrEqual(6, $width, sprintf('%s: element %d', $where, $element));
                }
                $this->assertSame(
                    1,
                    $pattern >> (Specs::CODEWORD_MODULES - 1) & 1,
                    $where . ': a codeword starts with a bar',
                );
            }
        }
    }

    /**
     * The cluster, re-derived from the pattern rather than trusted.
     *
     * This is the part of the table that *is* computable: a pattern's cluster
     * is the alternating sum of its four bar widths, modulo nine, and only
     * three of the nine residues are ever used. It holds for all 2787 entries
     * with no exceptions, which is also what confirmed the measurement.
     */
    public function testEveryPatternIsInTheClusterItIsFiledUnder(): void
    {
        foreach ([0, 3, 6] as $cluster) {
            foreach (CodewordPatterns::cluster($cluster) as $value => $pattern) {
                $widths = $this->widths($pattern);
                $bars = [$widths[0], $widths[2], $widths[4], $widths[6]];

                $this->assertSame(
                    $cluster,
                    ($bars[0] - $bars[1] + $bars[2] - $bars[3] + 9) % 9,
                    sprintf('cluster %d value %d', $cluster, $value),
                );
            }
        }
    }

    public function testEachClusterHoldsEveryValueExactlyOnce(): void
    {
        $everything = [];
        foreach ([0, 3, 6] as $cluster) {
            $patterns = CodewordPatterns::cluster($cluster);

            $this->assertCount(CodewordPatterns::VALUES, $patterns);
            $this->assertCount(
                CodewordPatterns::VALUES,
                array_unique($patterns),
                sprintf('cluster %d reuses a pattern for two values', $cluster),
            );

            $everything = [...$everything, ...$patterns];
        }

        // Across clusters too: a pattern belongs to exactly one cluster, so no
        // seventeen-module pattern may appear twice in the whole table.
        $this->assertCount(3 * CodewordPatterns::VALUES, array_unique($everything));
    }

    /** @return list<int> */
    private function widths(int $pattern): array
    {
        $widths = [];
        $run = 0;
        $current = 1;

        for ($bit = Specs::CODEWORD_MODULES - 1; $bit >= 0; $bit--) {
            $module = $pattern >> $bit & 1;
            if ($module === $current) {
                $run++;

                continue;
            }
            $widths[] = $run;
            $current = $module;
            $run = 1;
        }
        $widths[] = $run;

        return $widths;
    }
}
