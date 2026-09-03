<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Rm4scc\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\FourState\Patterns;
use CrazyGoat\ScanMePHP\Generator\Rm4scc\Characters;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * RM4SCC in pure PHP.
 *
 * The first symbology here that carries its data in the *height* of a bar
 * rather than in the width of a bar or a space. Every bar is one module wide
 * with one module of gap after it; what varies is whether it reaches above or
 * below the tracker band, two bits per bar, four bars per character.
 *
 *     start  C1 C1 C1 C1  ...  K K K K  stop
 *
 * A start bar (ascender), the characters, a check character, a stop bar (full
 * height). Nothing is mirrored, nothing is interleaved, and the width follows
 * from the payload length alone — this is the simplest layout in the library,
 * and all of its difficulty is in the arithmetic {@see Characters} does.
 */
final class PhpBackend implements BackendInterface
{
    /**
     * Modules of quiet zone on every side.
     *
     * Royal Mail asks for 2mm of clear space around the symbol, and the
     * nominal bar is 0.5mm, so four modules — on every side rather than just
     * the ends, because a four-state reader locates the tracker band by
     * finding where the bars stop.
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
        $check = Characters::checkCharacter($payload);

        $bars = [Characters::START];
        foreach (str_split($payload . $check) as $character) {
            $bars = [...$bars, ...Characters::bars($character)];
        }
        $bars[] = Characters::STOP;

        return Patterns::symbol(
            bars: $bars,
            quietZone: QuietZone::uniform(self::QUIET_ZONE),
            metadata: [
                'symbology' => Symbology::Rm4scc->value,
                'characters' => \strlen($payload),
                'checkCharacter' => $check,
            ],
        );
    }

    /**
     * The payload as it is encoded: capitals, no punctuation, no spaces.
     *
     * Lowercase is accepted and upper-cased rather than refused. The alphabet
     * has no lowercase in it at all, so there is nothing a lowercase letter
     * could mean instead — refusing it would be a rule with no reader behind
     * it. A space is a different matter: postcodes are written with one and it
     * is not encodable, so leaving it in silently would print a symbol that
     * says something other than what was asked for.
     *
     * @throws \InvalidArgumentException when the input is not encodable
     */
    public static function normalise(string $data): string
    {
        $payload = strtoupper($data);

        if ($payload === '') {
            throw new \InvalidArgumentException('RM4SCC needs at least one character');
        }

        if (\strlen($payload) > Characters::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'RM4SCC carries at most %d characters, got %d',
                Characters::MAX_LENGTH,
                \strlen($payload)
            ));
        }

        if (strspn($payload, Characters::ALPHABET) !== \strlen($payload)) {
            throw new \InvalidArgumentException(sprintf(
                'RM4SCC carries digits and capital letters only, got "%s"',
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
