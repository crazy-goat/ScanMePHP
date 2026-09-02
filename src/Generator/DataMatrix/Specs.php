<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataMatrix;

/**
 * The ECC200 symbol sizes, from ISO/IEC 16022 Table 7.
 *
 * Each row is verified structurally by the tests rather than trusted: the data
 * regions must tile the symbol exactly, and their area must account for every
 * codeword bit — with the four spare modules that the sizes ending in a 2×2
 * corner leave over.
 *
 * @internal
 */
final class Specs
{
    /** [rows, cols, regionRows, regionCols, dataWords, eccWords, blocks] */
    private const TABLE = [
        // Square
        [10, 10, 8, 8, 3, 5, 1],
        [12, 12, 10, 10, 5, 7, 1],
        [14, 14, 12, 12, 8, 10, 1],
        [16, 16, 14, 14, 12, 12, 1],
        [18, 18, 16, 16, 18, 14, 1],
        [20, 20, 18, 18, 22, 18, 1],
        [22, 22, 20, 20, 30, 20, 1],
        [24, 24, 22, 22, 36, 24, 1],
        [26, 26, 24, 24, 44, 28, 1],
        [32, 32, 14, 14, 62, 36, 1],
        [36, 36, 16, 16, 86, 42, 1],
        [40, 40, 18, 18, 114, 48, 1],
        [44, 44, 20, 20, 144, 56, 1],
        [48, 48, 22, 22, 174, 68, 1],
        [52, 52, 24, 24, 204, 84, 2],
        [64, 64, 14, 14, 280, 112, 2],
        [72, 72, 16, 16, 368, 144, 4],
        [80, 80, 18, 18, 456, 192, 4],
        [88, 88, 20, 20, 576, 224, 4],
        [96, 96, 22, 22, 696, 272, 4],
        [104, 104, 24, 24, 816, 336, 6],
        [120, 120, 18, 18, 1050, 408, 6],
        [132, 132, 20, 20, 1304, 496, 8],
        [144, 144, 22, 22, 1558, 620, 10],
        // Rectangular
        [8, 18, 6, 16, 5, 7, 1],
        [8, 32, 6, 14, 10, 11, 1],
        [12, 26, 10, 24, 16, 14, 1],
        [12, 36, 10, 16, 22, 18, 1],
        [16, 36, 14, 16, 32, 24, 1],
        [16, 48, 14, 22, 49, 28, 1],
    ];

    /** @var list<SymbolSpec>|null */
    private static ?array $specs = null;

    /** @return list<SymbolSpec> */
    public static function all(): array
    {
        return self::$specs ??= array_map(
            static fn (array $row): SymbolSpec => new SymbolSpec(...$row),
            self::TABLE
        );
    }

    /**
     * The smallest symbol holding $dataWords codewords.
     *
     * Square is the default because it is what every scanner is tuned for;
     * rectangular sizes exist for stamping on parts too narrow for a square
     * and are opted into.
     */
    public static function smallestFor(int $dataWords, bool $rectangular = false): ?SymbolSpec
    {
        $best = null;
        foreach (self::all() as $spec) {
            if ($spec->isSquare() === $rectangular || $spec->dataWords < $dataWords) {
                continue;
            }
            if ($best === null || $spec->dataWords < $best->dataWords) {
                $best = $spec;
            }
        }

        return $best;
    }

    public static function byName(string $name): ?SymbolSpec
    {
        foreach (self::all() as $spec) {
            if ($spec->name() === $name) {
                return $spec;
            }
        }

        return null;
    }

    /** Largest payload any ECC200 symbol can hold, in codewords. */
    public static function maxDataWords(): int
    {
        return 1558;
    }
}
