<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code128\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Code128\Encoder;
use CrazyGoat\ScanMePHP\Generator\Code128\Patterns;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Code 128 in pure PHP.
 *
 * There is nothing here for a native backend to accelerate: a symbol is a
 * handful of table lookups and a modulo, with no error correction and no mask
 * evaluation, so it encodes in microseconds and the C++ core stays QR-only.
 */
final class PhpBackend implements BackendInterface
{
    private readonly Encoder $encoder;

    public function __construct(?Encoder $encoder = null)
    {
        $this->encoder = $encoder ?? new Encoder();
    }

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
        $values = $this->encoder->symbolValues($data);

        return Symbol::linear(
            modules: Patterns::modules($values),
            quietZone: new QuietZone(left: Patterns::QUIET_ZONE, right: Patterns::QUIET_ZONE),
            barHeight: Patterns::BAR_HEIGHT,
            text: $data,
            metadata: [
                'symbology' => Symbology::Code128->value,
                // Start code, payload and check character; the stop pattern is
                // drawn but is not a value.
                'characters' => \count($values),
            ],
        );
    }
}
