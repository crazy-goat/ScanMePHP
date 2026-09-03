<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Rmqr;

use CrazyGoat\ScanMePHP\Encoding\Rmqr\Specs;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Region;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Converts the rMQR encoder's module grid into the public Symbol.
 */
final class RmqrSymbols
{
    /**
     * @param list<list<bool>> $matrix
     * @param array<string, mixed> $metadata
     */
    public static function fromModules(array $matrix, array $metadata = []): Symbol
    {
        $height = \count($matrix);
        $width = \count($matrix[0]);

        $modules = '';
        foreach ($matrix as $row) {
            foreach ($row as $module) {
                $modules .= $module ? '1' : '0';
            }
        }

        return new Symbol(
            width: $width,
            height: $height,
            modules: $modules,
            quietZone: QuietZone::uniform(Specs::QUIET_ZONE),
            // Two finders on the diagonal rather than QR's three: a full one
            // in the top-left corner and a five-module one in the
            // bottom-right. Together with the timing patterns running along
            // all four edges, that is what tells a scanner which way round a
            // rectangle is — the corners are the only asymmetry a symbol seven
            // modules tall has to offer.
            finderRegions: [
                new Region(0, 0, Specs::FINDER_SIZE, Specs::FINDER_SIZE),
                new Region(
                    $width - Specs::SUB_FINDER_SIZE,
                    $height - Specs::SUB_FINDER_SIZE,
                    Specs::SUB_FINDER_SIZE,
                    Specs::SUB_FINDER_SIZE,
                ),
            ],
            metadata: [
                'symbology' => Symbology::Rmqr->value,
                ...$metadata,
            ],
        );
    }
}
