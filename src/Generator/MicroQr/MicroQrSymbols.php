<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\MicroQr;

use CrazyGoat\ScanMePHP\Encoding\MicroQr\Specs;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Region;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Converts the Micro QR encoder's module grid into the public Symbol.
 */
final class MicroQrSymbols
{
    /**
     * @param list<list<bool>> $matrix
     * @param array<string, mixed> $metadata
     */
    public static function fromModules(array $matrix, array $metadata = []): Symbol
    {
        $modules = '';
        foreach ($matrix as $row) {
            foreach ($row as $module) {
                $modules .= $module ? '1' : '0';
            }
        }

        return Symbol::square(
            size: \count($matrix),
            modules: $modules,
            quietZone: QuietZone::uniform(Specs::QUIET_ZONE),
            // One finder, in the top-left corner, and that is the whole of the
            // orientation story: QR uses three of them and the missing fourth
            // corner to say which way up it is, while a Micro QR symbol has
            // its timing patterns running away from the single finder along
            // the top and left edges, so those two edges are the ones a
            // scanner locks onto.
            finderRegions: [new Region(0, 0, Specs::FINDER_SIZE, Specs::FINDER_SIZE)],
            metadata: [
                'symbology' => Symbology::MicroQr->value,
                ...$metadata,
            ],
        );
    }
}
