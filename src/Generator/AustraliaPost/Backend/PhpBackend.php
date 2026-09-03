<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\AustraliaPost\Backend;

use CrazyGoat\ScanMePHP\Encoding\ReedSolomonGf2m;
use CrazyGoat\ScanMePHP\Generator\AustraliaPost\AustraliaPostOptions;
use CrazyGoat\ScanMePHP\Generator\AustraliaPost\Bars;
use CrazyGoat\ScanMePHP\Generator\AustraliaPost\Payload;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\FourState\Patterns;
use CrazyGoat\ScanMePHP\Generator\FourState\State;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Australia Post in pure PHP.
 *
 * The layout is the plainest in the four-state family:
 *
 *     start   FCC   sorting code   customer field   parity   stop
 *       2      4         16          1, 16 or 31      12       2
 *
 * — thirty-seven, fifty-two or sixty-seven bars, and every field at a fixed
 * offset. Nothing is scattered and nothing is interleaved, which is the
 * opposite of the symbology before it here: an Intelligent Mail symbol has to
 * be read whole before any of it means anything, and an Australia Post symbol
 * can be read field by field off a ruler.
 *
 * What it spends instead is the parity. Every other postal code in this
 * library detects: RM4SCC's check character catches a misread bar, Intelligent
 * Mail's CRC catches a misread symbol, and neither can repair one. The four
 * Reed–Solomon codewords here **correct** — two symbol errors, or four if a
 * reader can say which bars it doubts — over GF(64), which is the field the
 * six-bit codewords live in and the same one MaxiCode uses. That is the whole
 * reason the bars are grouped in threes: three bars are six bits are one
 * codeword, and the filler bar exists to make the count divide.
 */
final class PhpBackend implements BackendInterface
{
    /** Four codewords, the standard's fixed Reed–Solomon parity. */
    public const CHECK_CODEWORDS = 4;

    /** Bits per codeword, and therefore the field the parity is taken in. */
    public const CODEWORD_BITS = 6;

    /**
     * Modules of quiet zone on every side.
     *
     * Australia Post asks for 6mm of clear space at the ends of the symbol
     * against a 1.2mm bar pitch, and a module here is half a pitch — so ten
     * modules, on every side rather than just the ends, because a four-state
     * reader finds the tracker band by finding where the bars stop.
     */
    public const QUIET_ZONE = 10;

    /**
     * The two bars at each end, and the same two at each end.
     *
     * An ascender and a tracker, not mirrored: the symbol reads the same way
     * round from either end, so a reader that has found the frame still has to
     * read the Format Control Code to know which way up the article is.
     */
    public const FRAME = [State::Ascender, State::Tracker];

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
        $options = $options instanceof AustraliaPostOptions ? $options : new AustraliaPostOptions();
        $payload = Payload::of($data, $options->format);

        $states = [];
        foreach (str_split($payload->formatControlCode() . $payload->sortingCode) as $digit) {
            $states = [...$states, ...Bars::numeric($digit)];
        }
        $states = [...$states, ...$payload->customerBars()];

        $bars = [...self::FRAME];
        foreach ([...$states, ...self::parity($states)] as $state) {
            $bars[] = Bars::STATES[$state];
        }
        $bars = [...$bars, ...self::FRAME];

        return Patterns::symbol(
            bars: $bars,
            quietZone: QuietZone::uniform(self::QUIET_ZONE),
            metadata: [
                'symbology' => Symbology::AustraliaPost->value,
                'format' => $payload->format->value,
                'formatControlCode' => $payload->formatControlCode(),
                'sortingCode' => $payload->sortingCode,
                'customerInformation' => $payload->customerInformation,
            ],
        );
    }

    /**
     * The twelve parity bars for a symbol's data bars.
     *
     * The data is read three bars at a time as a six-bit codeword, most
     * significant bar first, and the four check codewords are written back the
     * same way. The count divides because the customer field is sized in bars
     * and padded to width — see {@see Payload::customerBars()}.
     *
     * @param list<int> $states the data bars, FCC through customer field
     * @return list<int>
     */
    public static function parity(array $states): array
    {
        $codewords = [];
        foreach (array_chunk($states, Bars::CHARACTER_BARS) as $chunk) {
            $codewords[] = Bars::value($chunk);
        }

        $bars = [];
        foreach ((new ReedSolomonGf2m(self::CODEWORD_BITS))->encode($codewords, self::CHECK_CODEWORDS) as $check) {
            $bars = [...$bars, ...Bars::states($check, Bars::CHARACTER_BARS)];
        }

        return $bars;
    }

    /** Whether $data is a payload this symbology can carry. */
    public static function accepts(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        $options = $options instanceof AustraliaPostOptions ? $options : new AustraliaPostOptions();

        return Payload::accepts($data, $options->format);
    }
}
