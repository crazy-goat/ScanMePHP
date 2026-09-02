<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Codabar\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Codabar\CodabarOptions;
use CrazyGoat\ScanMePHP\Generator\Codabar\Patterns;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Codabar in pure PHP.
 *
 * A table lookup per character. The only thing worth stating is where the
 * delimiters come from: the payload is the data alone, and the delimiters
 * arrive from the options and are wrapped around it here. A caller encoding a
 * membership number should not have to know that the symbology needs an 'A' at
 * each end — but a scanner reports them, so they are in the metadata and in
 * what a round trip expects back.
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
        $options = $options instanceof CodabarOptions ? $options : new CodabarOptions();

        if (!Patterns::isEncodable($data)) {
            throw new \InvalidArgumentException(sprintf(
                'Codabar accepts %s and nothing else, and cannot encode an empty payload, got: %s',
                Patterns::CHARACTERS,
                $data
            ));
        }

        $characters = $options->start->value . $data . $options->stop->value;

        return Symbol::linear(
            modules: Patterns::modules($characters, $options->wideRatio),
            quietZone: new QuietZone(left: Patterns::QUIET_ZONE, right: Patterns::QUIET_ZONE),
            barHeight: Patterns::BAR_HEIGHT,
            // The data alone. The delimiters are conventionally not printed —
            // they are addressed to the scanner, not to the person reading the
            // label — even though a scanner reports them as part of the text.
            text: $data,
            metadata: [
                'symbology' => Symbology::Codabar->value,
                'start' => $options->start->value,
                'stop' => $options->stop->value,
                // What a scanner will report, delimiters included, so a caller
                // comparing against a scan has it without reassembling it.
                'characters' => $characters,
                'wideRatio' => $options->wideRatio,
            ],
        );
    }
}
