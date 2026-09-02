<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Itf14\Backend;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Ean\Patterns as CheckDigit;
use CrazyGoat\ScanMePHP\Generator\Itf\Patterns;
use CrazyGoat\ScanMePHP\Generator\Itf14\Itf14Options;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * ITF-14 in pure PHP: fourteen digits of ITF inside a bearer bar.
 *
 * The bars are ordinary ITF and a decoder reports them as ITF, exactly as a
 * UPC-A's bars are reported as an EAN-13. What makes the symbol an ITF-14 is
 * the fixed digit count, the mandatory check digit, and the frame — and the
 * frame is structural rather than decorative, so it is drawn as modules.
 *
 * That makes this a three-row symbol: a solid row, the bars with a solid
 * segment either side of them, and a solid row again. The row heights carry
 * the thickness, so a renderer scales the frame with the bars rather than
 * drawing a hairline around a large symbol.
 */
final class PhpBackend implements BackendInterface
{
    /** Digits before the check digit. */
    public const PAYLOAD = 13;

    /**
     * Bearer bar thickness in narrow modules.
     *
     * GS1 asks for at least 4.5X. Five is the smallest whole number that
     * satisfies it, and is what reference encoders draw.
     */
    public const BEARER = 5;

    public function getName(): string
    {
        return 'php';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function encode(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        $options = $options instanceof Itf14Options ? $options : new Itf14Options();

        $digits = self::normalise($data);
        $bars = Patterns::modules($digits, $options->wideRatio);

        $metadata = [
            'symbology' => Symbology::Itf14->value,
            'checkDigit' => (int) $digits[self::PAYLOAD],
            'wideRatio' => $options->wideRatio,
            'bearerBar' => $options->bearerBar,
        ];

        if (!$options->bearerBar) {
            return Symbol::linear(
                modules: $bars,
                quietZone: new QuietZone(left: Patterns::QUIET_ZONE, right: Patterns::QUIET_ZONE),
                barHeight: Patterns::BAR_HEIGHT,
                text: $digits,
                metadata: $metadata,
            );
        }

        // Bearer, quiet zone, bars, quiet zone, bearer. The order matters and
        // is easy to get backwards: GS1 measures the 10X quiet zone from the
        // bars, and the bearer bar surrounds it. A frame drawn flush against
        // the bars leaves no quiet zone at all, and the symbol does not scan —
        // which is how this was found, and why the round trip is not optional.
        $quiet = str_repeat('0', Patterns::QUIET_ZONE);
        $bearer = str_repeat('1', self::BEARER);
        $width = \strlen($bars) + 2 * (Patterns::QUIET_ZONE + self::BEARER);
        $solid = str_repeat('1', $width);

        return new Symbol(
            width: $width,
            height: 3,
            modules: $solid . $bearer . $quiet . $bars . $quiet . $bearer . $solid,
            dimension: Dimension::Linear,
            // None outside: the quiet zone this symbology requires is the one
            // drawn above, inside the frame. The bearer bar is the edge of the
            // symbol and may sit against the edge of the image.
            quietZone: QuietZone::none(),
            rowHeights: [self::BEARER, Patterns::BAR_HEIGHT, self::BEARER],
            text: $digits,
            metadata: $metadata,
        );
    }

    /**
     * The full fourteen digits, computing the check digit when thirteen were
     * given and verifying one that was supplied.
     *
     * The algorithm is GS1's modulo 10, the same one behind EAN-13, EAN-8 and
     * UPC — hence the shared implementation. A GTIN-14 is not a different kind
     * of number from a GTIN-13, only a longer one.
     *
     * @throws \InvalidArgumentException when the input is not encodable
     */
    public static function normalise(string $data): string
    {
        return CheckDigit::normalise($data, self::PAYLOAD, 'ITF-14');
    }
}
