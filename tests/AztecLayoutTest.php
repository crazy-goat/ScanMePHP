<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoding\Aztec\Layout;
use CrazyGoat\ScanMePHP\Encoding\Aztec\Specs;
use PHPUnit\Framework\TestCase;

/**
 * The parts of an Aztec symbol that do not depend on the payload.
 *
 * Separating these from the data is what made the encoder debuggable. Build the
 * same symbol twice, once with every data bit clear and once with every data
 * bit set: the cells that come out the same both times are the fixed ones — the
 * bullseye, the orientation marks and the reference grid — and they can be
 * compared against an independent encoder's symbols without knowing anything
 * about codewords. When that comparison passed and the whole symbol still did
 * not match, the fault was known to be in the spiral rather than the frame.
 *
 * Counting the fixed cells matters as much as comparing them. A compact symbol
 * has exactly ninety-three: eighty-one for the nine-by-nine bullseye and twelve
 * for the orientation marks. If the number came out lower, the data spiral
 * would be writing over something it should have stepped around, and the
 * comparison would silently skip those cells instead of failing.
 */
class AztecLayoutTest extends TestCase
{
    public function testTheFixedModulesMatchAnIndependentEncoder(): void
    {
        $checked = 0;

        foreach ($this->sizesInTheFixture() as $size => [$layers, $compact, $modules]) {
            $layout = new Layout($layers, $compact);
            $totalBits = Specs::totalBits($layers, $compact);
            $modeBits = Specs::modeMessageBits($compact);

            $clear = $layout->build(array_fill(0, $modeBits, 0), array_fill(0, $totalBits, 0));
            $set = $layout->build(array_fill(0, $modeBits, 1), array_fill(0, $totalBits, 1));

            $fixed = 0;
            for ($y = 0; $y < $size; $y++) {
                for ($x = 0; $x < $size; $x++) {
                    if ($clear[$y][$x] !== $set[$y][$x]) {
                        continue;
                    }

                    $fixed++;
                    $this->assertSame(
                        $modules[$y * $size + $x] === '1',
                        $clear[$y][$x],
                        sprintf('%d-module symbol, fixed module at row %d column %d', $size, $y, $x)
                    );
                }
            }

            $this->assertSame(
                $this->countFixedModules($size, $compact),
                $fixed,
                sprintf('%d-module symbol: the data spiral overwrote a fixed module', $size)
            );

            $checked++;
        }

        $this->assertGreaterThanOrEqual(9, $checked, 'the fixture should cover both kinds and several sizes');
    }

    public function testTheModeMessageRingHasRoomForExactlyTheModeMessage(): void
    {
        // The ring's perimeter less its fixed cells has to come to the mode
        // message's length exactly: twenty-eight bits for a compact symbol,
        // forty for a full one. It is the cheapest check there is that the
        // orientation marks and the grid crossings were counted right.
        foreach ([[true, 5, 12, 28], [false, 7, 16, 40]] as [$compact, $radius, $fixedInRing, $message]) {
            $perimeter = 4 * (2 * $radius + 1) - 4;

            $this->assertSame(
                $message,
                $perimeter - $fixedInRing,
                sprintf('%s ring: %d cells less %d fixed', $compact ? 'compact' : 'full', $perimeter, $fixedInRing)
            );
            $this->assertSame($message, Specs::modeMessageBits($compact));
            $this->assertSame($radius, Specs::modeRingRadius($compact));
        }
    }

    /**
     * The fixed modules, counted from the geometry rather than from Layout.
     *
     * Deliberately a second, independent statement of where the fixed parts
     * are: if this and Layout agreed only because they share code, the count
     * would prove nothing about the spiral staying out of their way.
     */
    private function countFixedModules(int $size, bool $compact): int
    {
        $centre = intdiv($size, 2);
        $bullseye = $compact ? 4 : 6;
        $ring = Specs::modeRingRadius($compact);
        $low = $centre - $ring;
        $high = $centre + $ring;

        $marks = [
            [$low, $low], [$low, $low + 1], [$low + 1, $low],
            [$low, $high], [$low, $high - 1], [$low + 1, $high],
            [$high, $high], [$high, $high - 1], [$high - 1, $high],
            [$high, $low], [$high, $low + 1], [$high - 1, $low],
        ];

        $fixed = 0;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $chebyshev = max(abs($y - $centre), abs($x - $centre));
                $onGrid = !$compact
                    && (($y - $centre) % 16 === 0 || ($x - $centre) % 16 === 0);

                if ($chebyshev <= $bullseye || $onGrid) {
                    $fixed++;

                    continue;
                }

                if ($chebyshev === $ring && \in_array([$y, $x], $marks, true)) {
                    $fixed++;
                }
            }
        }

        return $fixed;
    }

    /** @return \Generator<int, array{int, bool, string}> */
    private function sizesInTheFixture(): \Generator
    {
        $handle = fopen(__DIR__ . '/fixtures/aztec_reference.csv', 'r');
        if ($handle === false) {
            return;
        }

        fgetcsv($handle, 0, ',', '"', '');
        $seen = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            [, $size, $layers, $kind, , , $modules] = $row;
            if (isset($seen[$size])) {
                continue;
            }
            $seen[$size] = true;

            yield (int) $size => [(int) $layers, $kind === 'compact', $modules];
        }

        fclose($handle);
    }
}
