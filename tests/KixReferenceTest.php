<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Generator\FourState\Alphabet;
use CrazyGoat\ScanMePHP\Generator\FourState\Patterns;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * KIX, bar for bar, against an encoder we did not write.
 *
 * Like RM4SCC's fixture, this one carries more weight than the rest of the
 * suite: no free decoder reads a four-state postal code, so there is no second
 * gate behind it. What stands behind KIX is this file and the pixel read-back
 * in DecoderRoundTripTest, and both compare against zint
 * (`tools/kix_reference.py`).
 *
 * The symbols are held as state letters — D, A, F, T, one per bar — which is
 * what a four-state symbol is legible as, and is the alphabet zint's own DAFT
 * symbology speaks.
 */
class KixReferenceTest extends TestCase
{
    /** @return \Generator<string, array{string, string}> */
    public static function referenceProvider(): \Generator
    {
        $csv = __DIR__ . '/fixtures/kix_reference.csv';
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
        $symbol = Defaults::registry()->getGenerator(Symbology::Kix->value)->generate($data);

        $this->assertSame($expected, Patterns::states($symbol), "bars for {$data}");
    }

    /**
     * Every character is drawn, in every position a character can sit in.
     *
     * KIX has nothing to get wrong except its alphabet, so the fixture's only
     * job is to cover it — and to cover it somewhere other than the front of a
     * symbol, since an encoder that had grown an RM4SCC-shaped start bar would
     * still get a one-character symbol right at every character.
     */
    public function testTheFixtureReachesEveryCharacterAwayFromTheEnds(): void
    {
        $rows = iterator_to_array(self::referenceProvider());

        $inside = [];
        foreach (array_keys($rows) as $data) {
            $data = (string) $data;
            foreach (str_split(substr($data, 1, -1)) as $character) {
                $inside[$character] = true;
            }
        }

        foreach (str_split(Alphabet::CHARACTERS) as $character) {
            $this->assertArrayHasKey($character, $inside, "{$character} is never drawn mid-symbol");
        }
    }
}
