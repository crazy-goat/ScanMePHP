<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Matrix;
use PHPUnit\Framework\TestCase;

/**
 * Covers the three module representations Matrix accepts (bool[], 0/1 int[],
 * '0'/'1' string) and that they behave identically through the public API.
 */
final class MatrixTest extends TestCase
{
    /** @return array{Matrix, Matrix, Matrix, list<bool>} */
    private function equivalentMatrices(): array
    {
        $size = 21;
        $bools = [];
        $string = '';
        for ($i = 0; $i < $size * $size; $i++) {
            $dark = ($i * 7 + ($i >> 3)) % 3 === 0;
            $bools[] = $dark;
            $string .= $dark ? '1' : '0';
        }
        $ints = array_map(intval(...), $bools);

        return [
            new Matrix(1, $bools),
            new Matrix(1, $ints, normalized: false),
            Matrix::fromModuleString(1, $string),
            $bools,
        ];
    }

    public function testStringBackedMatrixReadsLikeBoolArray(): void
    {
        [$fromBools, $fromInts, $fromString, $bools] = $this->equivalentMatrices();
        $size = $fromBools->getSize();

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $expected = $bools[$y * $size + $x];
                $this->assertSame($expected, $fromString->get($x, $y), "get($x,$y)");
                $this->assertSame($expected, $fromString->fastGet($x, $y), "fastGet($x,$y)");
                $this->assertSame($expected, $fromInts->fastGet($x, $y), "int fastGet($x,$y)");
            }
        }

        $this->assertFalse($fromString->get(-1, 0));
        $this->assertFalse($fromString->get(0, $size));
    }

    public function testRawAndNestedGettersNormalizeToBools(): void
    {
        [$fromBools, $fromInts, $fromString, $bools] = $this->equivalentMatrices();

        $this->assertSame($bools, $fromString->getRawData());
        $this->assertSame($bools, $fromInts->getRawData());
        $this->assertSame($fromBools->getData(), $fromString->getData());
        $this->assertSame($fromBools->getPackedRows(), $fromString->getPackedRows());
        $this->assertSame($fromBools->getPackedCols(), $fromString->getPackedCols());
    }

    public function testWritesToStringBackedMatrixArePersisted(): void
    {
        [, , $matrix] = $this->equivalentMatrices();

        $before = $matrix->get(10, 10);
        $matrix->set(10, 10, !$before);
        $this->assertSame(!$before, $matrix->get(10, 10));

        $matrix->fastSet(3, 4, true);
        $this->assertTrue($matrix->fastGet(3, 4));
        $matrix->fastSet(3, 4, false);
        $this->assertFalse($matrix->fastGet(3, 4));

        $this->assertContainsOnly('bool', $matrix->getRawData());
    }

    public function testApplyXorMaskOnStringBackedMatrix(): void
    {
        [$fromBools, , $fromString] = $this->equivalentMatrices();
        $size = $fromBools->getSize();
        $xorRows = array_fill(0, $size, (1 << $size) - 1); // flip everything

        $fromBools->applyXorMask($xorRows);
        $fromString->applyXorMask($xorRows);

        $this->assertSame($fromBools->getRawData(), $fromString->getRawData());
    }

    public function testCloneKeepsStringRepresentationIndependent(): void
    {
        [, , $matrix] = $this->equivalentMatrices();
        $clone = $matrix->clone();

        $clone->set(0, 0, !$matrix->get(0, 0));

        $this->assertNotSame($matrix->get(0, 0), $clone->get(0, 0));
        $this->assertSame($matrix->getPackedRows()[1], $clone->getPackedRows()[1]);
    }

    public function testFromModuleStringRejectsWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Matrix::fromModuleString(1, str_repeat('0', 21 * 21 - 1));
    }

    public function testToModuleStringIsSharedByAllRepresentationsAndTracksWrites(): void
    {
        [$fromBools, $fromInts, $fromString] = $this->equivalentMatrices();
        $expected = $fromString->toModuleString();

        $this->assertSame($expected, $fromBools->toModuleString());
        $this->assertSame($expected, $fromInts->toModuleString());
        $this->assertSame($fromBools->getSize() ** 2, \strlen($expected));

        foreach ([$fromBools, $fromInts, $fromString] as $matrix) {
            $matrix->set(3, 2, !$matrix->get(3, 2));
            $this->assertSame(!(bool) $expected[2 * $matrix->getSize() + 3], $matrix->get(3, 2));
            $this->assertSame(
                implode('', array_map(intval(...), $matrix->getRawData())),
                $matrix->toModuleString(),
                'cache must be invalidated by set()'
            );
        }

        $clone = $fromBools->clone();
        $clone->fastSet(0, 0, !$clone->fastGet(0, 0));
        $this->assertNotSame($fromBools->toModuleString(), $clone->toModuleString());
        $this->assertNotSame($fromBools->toModuleString()[0], $clone->toModuleString()[0]);
    }
}
