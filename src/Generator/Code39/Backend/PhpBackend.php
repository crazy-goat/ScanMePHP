<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code39\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Code39\Charset;
use CrazyGoat\ScanMePHP\Generator\Code39\Code39Options;
use CrazyGoat\ScanMePHP\Generator\Code39\Mode;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Code 39 in pure PHP, in either reading mode.
 *
 * One character is nine elements wide and characters do not interlock, so the
 * encoder is a table lookup per character with no state at all — no code-set
 * switching as in Code 128, no error correction, nothing for a native backend
 * to accelerate.
 *
 * The one thing worth care is the order of operations: the payload is expanded
 * into Code 39 characters first, and only then is the check character computed
 * over the expansion. Computing it over the caller's bytes would produce a
 * character a scanner disagrees with on every extended symbol.
 */
final class PhpBackend implements BackendInterface
{
    public function __construct(private readonly Mode $mode = Mode::Standard)
    {
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
        $options = $options instanceof Code39Options ? $options : new Code39Options();

        if ($data === '') {
            throw new \InvalidArgumentException('Code 39 cannot encode an empty payload');
        }

        $encoded = $this->encodeCharacters($data);
        $check = $options->checkCharacter ? Charset::checkCharacter($encoded) : null;

        $metadata = [
            'symbology' => $this->symbology()->value,
            'mode' => $this->mode->value,
            // The characters actually drawn between the guards. For an
            // extended symbol this is not the payload, and comparing the two
            // is the quickest way to see why the symbol is as wide as it is.
            'characters' => $encoded . ($check ?? ''),
            'wideRatio' => $options->wideRatio,
        ];
        if ($check !== null) {
            $metadata['checkCharacter'] = $check;
        }

        return Symbol::linear(
            modules: Charset::modules($encoded . ($check ?? ''), $options->wideRatio),
            quietZone: new QuietZone(left: Charset::QUIET_ZONE, right: Charset::QUIET_ZONE),
            barHeight: Charset::BAR_HEIGHT,
            // The payload as the caller gave it, without the guards and
            // without the check character: ISO/IEC 16388 keeps both out of the
            // human-readable interpretation. A scanner that is not configured
            // to verify the check character will nonetheless report it, so the
            // printed line and the scanned string differ by design.
            text: $data,
            metadata: $metadata,
        );
    }

    /** @throws \InvalidArgumentException when the payload is not encodable in this mode */
    private function encodeCharacters(string $data): string
    {
        if ($this->mode === Mode::Extended) {
            return Charset::toExtended($data);
        }

        if (!Charset::isEncodable($data)) {
            throw new \InvalidArgumentException(sprintf(
                'Code 39 accepts only %s; use Code 39 Extended for the rest of ASCII, got: %s',
                Charset::CHARACTERS,
                $data
            ));
        }

        return $data;
    }

    private function symbology(): Symbology
    {
        return $this->mode === Mode::Extended ? Symbology::Code39Extended : Symbology::Code39;
    }
}
