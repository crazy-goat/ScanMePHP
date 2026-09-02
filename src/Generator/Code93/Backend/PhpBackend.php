<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code93\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Code93\Charset;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Code 93 in pure PHP.
 *
 * A table lookup per byte, two weighted sums, and a concatenation — nothing a
 * native backend could usefully accelerate. What it does have that Code 39
 * does not is a pair of mandatory check characters, and they are computed in
 * order: C over the data, then K over the data with C already appended.
 */
final class PhpBackend implements BackendInterface
{
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
        if ($data === '') {
            throw new \InvalidArgumentException('Code 93 cannot encode an empty payload');
        }

        $values = Charset::symbolValues($data);
        $characters = \count($values);

        return Symbol::linear(
            modules: Charset::modules($values),
            quietZone: new QuietZone(left: Charset::QUIET_ZONE, right: Charset::QUIET_ZONE),
            barHeight: Charset::BAR_HEIGHT,
            // The payload alone. The check characters are not printed — a
            // scanner verifies and discards them, so unlike Code 39's optional
            // one they never reach the caller on the reading side either.
            text: $data,
            metadata: [
                'symbology' => Symbology::Code93->value,
                // Characters actually drawn, check characters included. For an
                // all-uppercase payload this is the byte count plus two; every
                // byte outside the 43 costs one more.
                'characters' => $characters,
                // Either can be one of the four shift characters, which have
                // no printable form — see Charset::characterName().
                'checkC' => Charset::characterName($values[$characters - 2]),
                'checkK' => Charset::characterName($values[$characters - 1]),
            ],
        );
    }
}
