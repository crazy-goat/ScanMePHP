<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\IntelligentMail\Backend;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\FourState\Patterns;
use CrazyGoat\ScanMePHP\Generator\FourState\State;
use CrazyGoat\ScanMePHP\Generator\IntelligentMail\BarMap;
use CrazyGoat\ScanMePHP\Generator\IntelligentMail\Codewords;
use CrazyGoat\ScanMePHP\Generator\IntelligentMail\Payload;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Intelligent Mail in pure PHP.
 *
 * The other four-state codes in this library draw a character at a time:
 * RM4SCC's bars come in fours and each four is one letter, so a symbol can be
 * read left to right with a pencil. Intelligent Mail does not work like that
 * at all, and the difference is the whole symbology.
 *
 * A payload here becomes one 102-bit number, that number becomes ten
 * thirteen-bit characters, and those hundred and thirty bits are then
 * *scattered* across the sixty-five bars — {@see BarMap} — so that no bar
 * belongs to a digit and no digit lives in one place. Every bar carries one
 * bit of one character in its descender and one bit of another in its
 * ascender. Nothing about the symbol is local: change one digit of the
 * tracking code and most of the sixty-five bars move.
 *
 * That scattering is not obfuscation, it is the error tolerance. USPS mail is
 * read at speed off envelopes that are folded, smudged and machine-stamped,
 * and spreading each character over the full width means damage in one place
 * costs a bit from many characters rather than destroying one of them. What
 * catches the damage is an eleven-bit CRC folded into the value itself
 * {@see Codewords} — detection, not correction: a symbol either reads or it
 * does not, and this encoder's job is to be exactly right.
 *
 * The bar count is fixed. Every Intelligent Mail symbol in the world is
 * sixty-five bars wide, whether it carries a routing code or not, because the
 * number is padded to 102 bits rather than to its own length.
 */
final class PhpBackend implements BackendInterface
{
    /** Bars in every symbol, whatever the payload. */
    public const BARS = 65;

    /**
     * Modules of quiet zone on every side.
     *
     * USPS states the clear zone in inches — an eighth of one — where the rest
     * of the family states it in millimetres. Bars run at 22 to the inch and a
     * module is half a bar pitch, so an eighth of an inch is five and a half
     * modules; six is that rounded up. On all four sides, as everywhere else
     * in the family: a four-state reader finds the tracker band by finding
     * where the bars stop.
     */
    public const QUIET_ZONE = 6;

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
        $payload = Payload::of($data);

        $value = Codewords::value($payload);
        $frameCheck = Codewords::frameCheck($value);
        $characters = Codewords::characters(Codewords::of($value, $frameCheck), $frameCheck);

        $bars = [];
        foreach (BarMap::BARS as [$descender, $descenderBit, $ascender, $ascenderBit]) {
            $bars[] = State::of(
                (($characters[$ascender] >> $ascenderBit) & 1) === 1,
                (($characters[$descender] >> $descenderBit) & 1) === 1,
            );
        }

        return Patterns::symbol(
            bars: $bars,
            quietZone: QuietZone::uniform(self::QUIET_ZONE),
            metadata: [
                'symbology' => Symbology::IntelligentMail->value,
                'trackingCode' => $payload->tracking,
                'routingCode' => $payload->routing,
                'frameCheckSequence' => $frameCheck,
            ],
        );
    }

    /** Whether $data is a payload this symbology can carry. */
    public static function accepts(string $data): bool
    {
        return Payload::accepts($data);
    }
}
