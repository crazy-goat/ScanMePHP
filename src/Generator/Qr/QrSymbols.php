<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Qr;

use CrazyGoat\ScanMePHP\Matrix;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Region;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Converts the QR encoders' internal Matrix into the public Symbol.
 *
 * This is the whole adapter between the existing QR pipeline — four backends,
 * a C++ core, an extension — and the symbology-agnostic renderer boundary. The
 * module string passes straight through, so a symbol coming from the native
 * backends still costs no per-module PHP work.
 */
final class QrSymbols
{
    /** Modules of blank margin ISO/IEC 18004 requires on every side. */
    public const QUIET_ZONE = 4;

    /** Side of each of the three finder patterns, in modules. */
    private const FINDER_SIZE = 7;

    /**
     * @param array<string, mixed> $metadata Anything the symbology adds on top
     *        of the name and version every QR symbol reports. GS1 QR uses it
     *        for the element count and the payload a scanner will hand back.
     */
    public static function fromMatrix(
        Matrix $matrix,
        string $symbology = Symbology::QrCode->value,
        array $metadata = []
    ): Symbol {
        $size = $matrix->getSize();
        $last = $size - self::FINDER_SIZE;

        return Symbol::square(
            size: $size,
            modules: $matrix->toModuleString(),
            quietZone: QuietZone::uniform(self::QUIET_ZONE),
            // Top-left, top-right, bottom-left. Reported so a renderer can
            // style them — rounded corners in SVG — without knowing QR's
            // layout; there is deliberately no fourth, which is what tells a
            // scanner the symbol's orientation.
            finderRegions: [
                new Region(0, 0, self::FINDER_SIZE, self::FINDER_SIZE),
                new Region($last, 0, self::FINDER_SIZE, self::FINDER_SIZE),
                new Region(0, $last, self::FINDER_SIZE, self::FINDER_SIZE),
            ],
            metadata: [
                'symbology' => $symbology,
                'version' => $matrix->getVersion(),
                ...$metadata,
            ],
        );
    }
}
