<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Gs1128\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Code128\Encoder;
use CrazyGoat\ScanMePHP\Generator\Code128\Patterns;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * GS1-128 in pure PHP.
 *
 * Nothing here draws anything. The payload becomes a byte string with FNC1
 * where the application identifier table says a separator is needed, one more
 * FNC1 goes in front to mark the symbol as GS1, and Code 128's encoder does the
 * rest — including choosing the character sets, which is why the FNC1s have to
 * be part of the string it sees rather than spliced in afterwards. A separator
 * sitting in the middle of a digit run changes whether leaving set C for it is
 * worth the switch, and only the encoder is in a position to weigh that.
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
        $elements = ElementString::parse($data);
        $payload = $elements->payload();

        // The leading FNC1 is what a reader reports as ']C1'. It is a symbol
        // character rather than data and never reaches the host, which is why
        // it is not part of payload().
        $values = $this->encoder->symbolValues(Encoder::FNC1 . $payload);

        return Symbol::linear(
            modules: Patterns::modules($values),
            quietZone: new QuietZone(left: Patterns::QUIET_ZONE, right: Patterns::QUIET_ZONE),
            barHeight: Patterns::BAR_HEIGHT,
            // The parentheses are printed for people and are not in the bars.
            text: $elements->humanReadable(),
            metadata: [
                'symbology' => Symbology::Gs1128->value,
                'characters' => \count($values),
                'elements' => \count($elements->elements),
                // What a scanner hands back, FNC1 separators included.
                'payload' => $payload,
            ],
        );
    }
}
