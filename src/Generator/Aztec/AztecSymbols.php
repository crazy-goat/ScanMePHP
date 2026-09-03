<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Aztec;

use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Region;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Converts the Aztec encoder's module grid into the public Symbol.
 */
final class AztecSymbols
{
    /**
     * ISO/IEC 24778 requires none, which is one of the reasons to reach for
     * Aztec: the bullseye is at the centre, so a scanner does not need clear
     * space around the edge to find the symbol. Renderers add their own margin
     * when a design calls for one.
     */
    public const QUIET_ZONE = 0;

    /**
     * @param array<int, array<int, bool>> $matrix
     * @param array<string, mixed> $metadata
     */
    public static function fromModules(
        array $matrix,
        int $layers,
        bool $compact,
        array $metadata = [],
        string $symbology = Symbology::Aztec->value,
    ): Symbol {
        $size = \count($matrix);
        $modules = '';
        foreach ($matrix as $row) {
            foreach ($row as $module) {
                $modules .= $module ? '1' : '0';
            }
        }

        // The bullseye, so a renderer can style it. One region and not three:
        // where QR puts a finder in three corners and leaves the fourth empty
        // to give away its orientation, Aztec puts concentric rings in the
        // middle and tells a scanner which way up it is with the four
        // orientation marks around them.
        $finder = $compact ? 9 : 13;
        $offset = intdiv($size - $finder, 2);

        return Symbol::square(
            size: $size,
            modules: $modules,
            quietZone: QuietZone::uniform(self::QUIET_ZONE),
            finderRegions: [new Region($offset, $offset, $finder, $finder)],
            metadata: [
                'symbology' => $symbology,
                'layers' => $layers,
                'compact' => $compact,
                ...$metadata,
            ],
        );
    }
}
