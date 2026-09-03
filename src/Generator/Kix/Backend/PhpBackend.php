<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Kix\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\FourState\Alphabet;
use CrazyGoat\ScanMePHP\Generator\FourState\Patterns;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * KIX in pure PHP.
 *
 * The whole symbol, and this is not an abridgement:
 *
 *     C1 C1 C1 C1  C2 C2 C2 C2  ...  Cn Cn Cn Cn
 *
 * No start bar, no stop bar, no check character. KIX is its characters and
 * nothing else, which makes it the one symbology here where encoding is a
 * `map` — the bars of a payload are the concatenation of the bars of its
 * characters, and {@see \CrazyGoat\ScanMePHP\Tests\KixTest} asserts exactly
 * that, because it is the property every mistake available here would break.
 *
 * The missing pieces are a choice by PostNL rather than an oversight, and they
 * are worth being explicit about, since a caller reaching for a postal code
 * usually assumes the symbology is checking something. It is not:
 *
 *   * **There is no error detection over the symbol.** A dropped or added
 *     character produces another legal KIX symbol saying something else. What
 *     survives is the per-character parity — two ascenders and two descenders
 *     in every character — which catches a misread *bar*, not a misread
 *     *symbol*. Sorting machines read KIX beside an address they can compare
 *     it against; a reader without that context has nothing.
 *   * **There is no start or stop pattern**, so nothing marks where the symbol
 *     begins. The quiet zone is load-bearing here in a way it is not in
 *     RM4SCC, which is why it is applied on all four sides.
 *
 * Everything the characters themselves do is {@see Alphabet}'s, shared with
 * RM4SCC: KIX is RM4SCC's alphabet with the envelope taken off.
 */
final class PhpBackend implements BackendInterface
{
    /**
     * The longest payload KIX carries.
     *
     * A Dutch address is six characters of postcode, the house number and its
     * additions; eighteen is where the specification stops and where zint
     * stops with it.
     */
    public const MAX_LENGTH = 18;

    /**
     * Modules of quiet zone on every side.
     *
     * 2mm against a 0.5mm bar, as in RM4SCC. Four modules on all four sides
     * rather than just the ends, and here that is not only about finding the
     * tracker band: with no start pattern to recognise, white space is the
     * only thing telling a reader where the first character is.
     */
    public const QUIET_ZONE = 4;

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
        $payload = self::normalise($data);

        $bars = [];
        foreach (str_split($payload) as $character) {
            $bars = [...$bars, ...Alphabet::bars($character)];
        }

        return Patterns::symbol(
            bars: $bars,
            quietZone: QuietZone::uniform(self::QUIET_ZONE),
            metadata: [
                'symbology' => Symbology::Kix->value,
                'characters' => \strlen($payload),
            ],
        );
    }

    /**
     * The payload as it is encoded: capitals, no punctuation, no spaces.
     *
     * Lowercase is upper-cased rather than refused, as in RM4SCC — the
     * alphabet has no lowercase in it, so there is nothing else a lowercase
     * letter could have meant. A space is refused: a Dutch postcode is written
     * `2500 GG` and the space is not encodable, so dropping it quietly would
     * print a symbol saying something the caller did not ask for.
     *
     * @throws \InvalidArgumentException when the input is not encodable
     */
    public static function normalise(string $data): string
    {
        $payload = strtoupper($data);

        if ($payload === '') {
            throw new \InvalidArgumentException('KIX needs at least one character');
        }

        if (\strlen($payload) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'KIX carries at most %d characters, got %d',
                self::MAX_LENGTH,
                \strlen($payload)
            ));
        }

        if (!Alphabet::covers($payload)) {
            throw new \InvalidArgumentException(sprintf(
                'KIX carries digits and capital letters only, got "%s"',
                $data
            ));
        }

        return $payload;
    }

    /** Whether $data is a payload this symbology can carry. */
    public static function accepts(string $data): bool
    {
        try {
            self::normalise($data);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
