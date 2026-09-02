<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Itf\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Ean\Patterns as CheckDigit;
use CrazyGoat\ScanMePHP\Generator\Itf\ItfOptions;
use CrazyGoat\ScanMePHP\Generator\Itf\Patterns;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * ITF in pure PHP.
 *
 * Two table lookups per digit pair and an interleave. The only decision is the
 * order the check digit is applied in: it is appended to the payload before
 * anything is drawn, because it is a digit of the encoded number and not an
 * annotation on it — an ITF with a check digit is one digit longer, and the
 * parity of the digit count that ITF insists on is the parity *after* it.
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
        $options = $options instanceof ItfOptions ? $options : new ItfOptions();

        if (preg_match('/^\d+$/', $data) !== 1) {
            throw new \InvalidArgumentException(sprintf('ITF encodes digits only, got: %s', $data));
        }

        $digits = $data;
        $metadata = [
            'symbology' => Symbology::Itf->value,
            'wideRatio' => $options->wideRatio,
        ];

        if ($options->checkDigit) {
            $check = CheckDigit::checkDigit($data);
            $digits .= $check;
            // Not printed by the standard, but a scanner that does not verify
            // it will report it, so a caller comparing the two needs to see it.
            $metadata['checkDigit'] = $check;
        }

        $metadata['digits'] = \strlen($digits);

        return Symbol::linear(
            modules: Patterns::modules($digits, $options->wideRatio),
            quietZone: new QuietZone(left: Patterns::QUIET_ZONE, right: Patterns::QUIET_ZONE),
            barHeight: Patterns::BAR_HEIGHT,
            // Every digit that is drawn, the check digit included: unlike Code
            // 39's, this one is part of the number and belongs under it.
            text: $digits,
            metadata: $metadata,
        );
    }
}
