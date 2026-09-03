<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Pdf417;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Converts the PDF417 encoder's module rows into the public Symbol.
 *
 * PDF417 is the first matrix symbology here whose rows are not one module
 * tall. Every other one encodes in both axes, so a row's height is fixed by the
 * data; PDF417's rows are independently readable stacked linear codes, and
 * their height exists only to give a scanner's sweep something to hit. So the
 * symbol carries one module row per codeword row and states the height in its
 * row heights, which is the same mechanism the linear symbologies use for bar
 * height and what the four-state postal codes will use for their ratios.
 */
final class Pdf417Symbols
{
    /**
     * ISO/IEC 15438 §5.8.3 asks for two modules on every side.
     */
    public const QUIET_ZONE = 2;

    /**
     * @param list<list<bool>> $matrix
     * @param array<string, int|string|bool> $metadata
     */
    public static function fromModules(array $matrix, int $rowHeight, array $metadata = []): Symbol
    {
        $modules = '';
        foreach ($matrix as $row) {
            foreach ($row as $module) {
                $modules .= $module ? '1' : '0';
            }
        }

        return new Symbol(
            width: \count($matrix[0]),
            height: \count($matrix),
            modules: $modules,
            dimension: Dimension::Matrix,
            quietZone: QuietZone::uniform(self::QUIET_ZONE),
            rowHeights: array_fill(0, \count($matrix), $rowHeight),
            metadata: [
                'symbology' => Symbology::Pdf417->value,
                ...$metadata,
            ],
        );
    }
}
