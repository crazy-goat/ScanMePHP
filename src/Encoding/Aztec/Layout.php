<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Aztec;

/**
 * Where every module of an Aztec symbol goes.
 *
 * Three things share the grid and none of them may overwrite another:
 *
 *  - the **bullseye**, concentric rings at the centre, dark on every even
 *    Chebyshev radius, which is what a scanner looks for first;
 *  - the **mode message ring** just outside it, whose four corners carry
 *    orientation marks instead of message bits — three dark at the top left,
 *    then two, then one, then none, which is how a scanner tells which way up
 *    the symbol is;
 *  - the **data spiral**, two modules thick per layer, wound from the outside
 *    in and interrupted by the reference grid on full symbols.
 *
 * The reference grid is why coordinates are indirected through
 * {@see gridMap()}. A full symbol grows a line of alternating modules every
 * sixteen modules from the centre, and the spiral does not place data there —
 * it steps over it. Working in a coordinate space that has no grid lines and
 * translating on the way out keeps that out of the placement loops, which are
 * hard enough to read as it is.
 *
 * @internal Part of the Aztec encoding pipeline.
 */
final class Layout
{
    public function __construct(
        private readonly int $layers,
        private readonly bool $compact,
    ) {
    }

    /**
     * @param list<int> $modeBits Exactly {@see Specs::modeMessageBits()} of them
     * @param list<int> $dataBits Exactly {@see Specs::totalBits()} of them
     * @return array<int, array<int, bool>>
     */
    public function build(array $modeBits, array $dataBits): array
    {
        $size = Specs::size($this->layers, $this->compact);
        $centre = intdiv($size, 2);
        $matrix = array_fill(0, $size, array_fill(0, $size, false));

        $this->drawBullseye($matrix, $centre);
        $this->drawReferenceGrid($matrix, $size, $centre);
        $this->drawOrientationMarks($matrix, $centre);
        $this->placeModeMessage($matrix, $centre, $modeBits);
        $this->placeData($matrix, $dataBits);

        return $matrix;
    }

    /** @param array<int, array<int, bool>> $matrix */
    private function drawBullseye(array &$matrix, int $centre): void
    {
        $radius = $this->compact ? 4 : 6;

        for ($dy = -$radius; $dy <= $radius; $dy++) {
            for ($dx = -$radius; $dx <= $radius; $dx++) {
                $matrix[$centre + $dy][$centre + $dx] = max(abs($dy), abs($dx)) % 2 === 0;
            }
        }
    }

    /** @param array<int, array<int, bool>> $matrix */
    private function drawReferenceGrid(array &$matrix, int $size, int $centre): void
    {
        if ($this->compact) {
            return;
        }

        for ($line = $centre % 16; $line < $size; $line += 16) {
            for ($other = 0; $other < $size; $other++) {
                $matrix[$line][$other] = ($line + $other) % 2 === 0;
                $matrix[$other][$line] = ($other + $line) % 2 === 0;
            }
        }
    }

    /**
     * The four corners of the mode ring, which say which way up the symbol is.
     *
     * Read clockwise from the top left the marks are three dark modules, then
     * two, then one, then none, so a scanner that has found the bullseye can
     * tell a symbol from the same symbol rotated by any multiple of ninety
     * degrees. The coordinates are spelled out rather than derived from a
     * rotation, because the asymmetry is the whole point and a loop that
     * rotated a pattern would be a loop that could rotate it wrongly.
     *
     * @param array<int, array<int, bool>> $matrix
     */
    private function drawOrientationMarks(array &$matrix, int $centre): void
    {
        $radius = Specs::modeRingRadius($this->compact);
        $low = $centre - $radius;
        $high = $centre + $radius;

        $matrix[$low][$low] = true;
        $matrix[$low][$low + 1] = true;
        $matrix[$low + 1][$low] = true;

        $matrix[$low][$high] = true;
        $matrix[$low][$high - 1] = false;
        $matrix[$low + 1][$high] = true;

        $matrix[$high][$high] = false;
        $matrix[$high][$high - 1] = false;
        $matrix[$high - 1][$high] = true;

        $matrix[$high][$low] = false;
        $matrix[$high][$low + 1] = false;
        $matrix[$high - 1][$low] = false;
    }

    /**
     * @param array<int, array<int, bool>> $matrix
     * @param list<int> $bits
     */
    private function placeModeMessage(array &$matrix, int $centre, array $bits): void
    {
        $index = 0;
        foreach ($this->modeMessageCells($centre) as [$y, $x]) {
            $matrix[$y][$x] = $bits[$index++] === 1;
        }
    }

    /**
     * The ring's message cells, clockwise from just past the top-left mark.
     *
     * @return list<array{int, int}>
     */
    private function modeMessageCells(int $centre): array
    {
        $radius = Specs::modeRingRadius($this->compact);
        $low = $centre - $radius;
        $high = $centre + $radius;

        $cells = [];
        for ($i = $low + 2; $i <= $high - 2; $i++) {
            $cells[] = [$low, $i];      // top, left to right
        }
        for ($i = $low + 2; $i <= $high - 2; $i++) {
            $cells[] = [$i, $high];     // right, top to bottom
        }
        for ($i = $high - 2; $i >= $low + 2; $i--) {
            $cells[] = [$high, $i];     // bottom, right to left
        }
        for ($i = $high - 2; $i >= $low + 2; $i--) {
            $cells[] = [$i, $low];      // left, bottom to top
        }

        // A full symbol's reference grid crosses the ring at the middle of each
        // side, so those four cells belong to the grid and carry no message.
        if (!$this->compact) {
            return array_values(array_filter(
                $cells,
                static fn (array $cell): bool => $cell[0] !== $centre && $cell[1] !== $centre,
            ));
        }

        return $cells;
    }

    /**
     * Base coordinates to real ones, stepping over the reference grid.
     *
     * @return list<int>
     */
    private function gridMap(): array
    {
        $base = ($this->compact ? 11 : 14) + 4 * $this->layers;
        if ($this->compact) {
            return range(0, $base - 1);
        }

        $size = Specs::size($this->layers, $this->compact);
        $baseCentre = intdiv($base, 2);
        $centre = intdiv($size, 2);

        $map = array_fill(0, $base, 0);
        for ($i = 0; $i < $baseCentre; $i++) {
            $offset = $i + intdiv($i, 15);
            $map[$baseCentre - $i - 1] = $centre - $offset - 1;
            $map[$baseCentre + $i] = $centre + $offset + 1;
        }

        return $map;
    }

    /**
     * The spiral, two modules thick per layer, outermost layer first.
     *
     * The walk goes anticlockwise from the top-left corner: down the left edge,
     * right along the bottom, up the right edge, then left along the top. Each
     * step places two bits across the thickness of the layer, outer module
     * first. The four edges are filled from four consecutive slices of the same
     * bit run, which is why the offsets below are multiples of the edge length
     * rather than one moving cursor.
     *
     * The direction and the starting corner were read off symbols an
     * independent encoder produced, not guessed: eight plausible variants place
     * the same modules in the same cells and only one of them is Aztec.
     *
     * @param array<int, array<int, bool>> $matrix
     * @param list<int> $bits
     */
    private function placeData(array &$matrix, array $bits): void
    {
        $map = $this->gridMap();
        $base = count($map);
        $offset = 0;

        for ($layer = 0; $layer < $this->layers; $layer++) {
            $edge = ($this->layers - $layer) * 4 + ($this->compact ? 9 : 12);
            $near = 2 * $layer;
            $far = $base - 1 - $near;

            for ($step = 0; $step < $edge; $step++) {
                for ($thickness = 0; $thickness < 2; $thickness++) {
                    $bit = $offset + $step * 2 + $thickness;
                    $in = $near + $thickness;
                    $out = $far - $thickness;

                    $matrix[$map[$near + $step]][$map[$in]] = $bits[$bit] === 1;
                    $matrix[$map[$out]][$map[$near + $step]] = $bits[$bit + $edge * 2] === 1;
                    $matrix[$map[$far - $step]][$map[$out]] = $bits[$bit + $edge * 4] === 1;
                    $matrix[$map[$in]][$map[$far - $step]] = $bits[$bit + $edge * 6] === 1;
                }
            }

            $offset += $edge * 8;
        }
    }
}
