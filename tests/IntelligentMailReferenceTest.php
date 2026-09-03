<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Generator\FourState\Patterns;
use CrazyGoat\ScanMePHP\Generator\IntelligentMail\Payload;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Intelligent Mail, bar for bar, against an encoder we did not write.
 *
 * As with RM4SCC and KIX, there is no second gate behind this one: no free
 * decoder reads a four-state postal code, so what stands behind the symbology
 * is this file and the pixel read-back in DecoderRoundTripTest, both of them
 * ultimately comparing against zint (`tools/intelligent_mail_reference.py`).
 *
 * The fixture carries more weight here than it does for the other two. RM4SCC
 * draws a character at a time, so a bug in it is visible as a wrong group of
 * four bars with the rest intact; Intelligent Mail scatters ten characters
 * across sixty-five bars, and a symbol that is wrong is wrong nearly
 * everywhere at once. Reading a mismatch tells you almost nothing about which
 * of the four steps produced it — which is why the encoder was verified
 * against zint one step at a time before it was written, in
 * `tools/intelligent_mail_placement.py`.
 */
class IntelligentMailReferenceTest extends TestCase
{
    /** @return \Generator<string, array{string, string}> */
    public static function referenceProvider(): \Generator
    {
        $csv = __DIR__ . '/fixtures/intelligent_mail_reference.csv';
        $handle = fopen($csv, 'r');
        if ($handle === false) {
            return;
        }

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            [$data, $states] = $row;
            yield $data => [$data, $states];
        }

        fclose($handle);
    }

    #[DataProvider('referenceProvider')]
    public function testTheBarsMatchAnIndependentEncoder(string $data, string $expected): void
    {
        $symbol = Defaults::registry()->getGenerator(Symbology::IntelligentMail->value)->generate($data);

        $this->assertSame($expected, Patterns::states($symbol), "bars for {$data}");
    }

    /**
     * The fixture reaches the rules that are not visible in a single symbol.
     *
     * Both of these are properties of the payload rather than of our encoder,
     * so this is a claim about coverage that our own code cannot make true by
     * being wrong: the routing code lengths are the four the standard defines,
     * and the endorsement digit is the one digit in the tracking code that is
     * worth five rather than ten.
     */
    public function testTheFixtureReachesEveryRoutingLengthAndEveryEndorsementDigit(): void
    {
        $lengths = [];
        $endorsements = [];

        foreach (self::referenceProvider() as [$data]) {
            $payload = Payload::of($data);

            $lengths[\strlen($payload->routing)] = true;
            $endorsements[$payload->tracking[1]] = true;
        }

        $drawn = array_keys($lengths);
        sort($drawn);

        $this->assertSame(Payload::ROUTING_LENGTHS, $drawn, 'a routing code length is never drawn');
        $this->assertCount(
            Payload::MAX_ENDORSEMENT + 1,
            $endorsements,
            'an endorsement digit is never drawn'
        );
    }

    /**
     * Every bit of the frame check sequence is drawn both ways.
     *
     * Ten of the eleven invert a character; a bit that is never set leaves a
     * character that is never inverted, and inversion is the only thing in the
     * symbology that can turn a legal pattern into another legal pattern. The
     * eleventh is the one worth sweeping for: it is spent in two places at
     * once, and the first codeword crossing 659 is where it shows.
     */
    public function testTheFixtureSetsAndClearsEveryCheckBit(): void
    {
        $seen = [];

        foreach (self::referenceProvider() as [$data]) {
            $fcs = Defaults::registry()
                ->getGenerator(Symbology::IntelligentMail->value)
                ->generate($data)
                ->getMetadataValue('frameCheckSequence');

            self::assertIsInt($fcs);
            for ($bit = 0; $bit < 11; $bit++) {
                $seen["{$bit}:" . (($fcs >> $bit) & 1)] = true;
            }
        }

        $this->assertCount(22, $seen, 'a check bit is never drawn both ways');
    }
}
