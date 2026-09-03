<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Generator\FourState\Alphabet;
use CrazyGoat\ScanMePHP\Generator\FourState\Patterns;
use CrazyGoat\ScanMePHP\Generator\Rm4scc\Characters;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RM4SCC, bar for bar, against an encoder we did not write.
 *
 * This fixture carries more weight than the others in the suite. Every
 * symbology before it is checked twice — module for module against zxing-cpp,
 * and then by handing a rendered PNG back to zxing-cpp and requiring the
 * payload. No free decoder reads a four-state postal code at all, so there is
 * no second gate here: what stands behind RM4SCC is this file and the pixel
 * read-back in DecoderRoundTripTest, and both of them ultimately compare
 * against zint (`tools/rm4scc_reference.py`).
 *
 * The symbols are held as state letters — D, A, F, T, one per bar — because
 * that is what a four-state symbol is legible as, and because it is the
 * alphabet zint's own DAFT symbology speaks, which is what makes the reading
 * of its drawings checkable at all.
 */
class Rm4sccReferenceTest extends TestCase
{
    /** @return \Generator<string, array{string, string}> */
    public static function referenceProvider(): \Generator
    {
        $csv = __DIR__ . '/fixtures/rm4scc_reference.csv';
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
        $symbol = Defaults::registry()->getGenerator(Symbology::Rm4scc->value)->generate($data);

        $this->assertSame($expected, Patterns::states($symbol), "bars for {$data}");
    }

    /**
     * Every character in the alphabet is drawn, and every check character.
     *
     * Neither is decoration. The alphabet is an enumeration rather than a
     * table, so an error in it is a run of consecutive characters rather than
     * one; and the check character is the only part of the symbol a payload
     * cannot exercise directly, so it is reached by sweeping until nothing is
     * missing.
     */
    public function testTheFixtureReachesEveryCharacterAndEveryCheckCharacter(): void
    {
        $characters = [];
        $checks = [];

        foreach (self::referenceProvider() as [$data, $states]) {
            foreach (str_split(strtoupper($data)) as $character) {
                $characters[$character] = true;
            }

            $checks[substr($states, -5, 4)] = true;
        }

        // Cast: PHP turns the digit keys back into integers on the way out.
        $drawn = array_map(strval(...), array_keys($characters));
        sort($drawn);

        $alphabet = str_split(Alphabet::CHARACTERS);
        sort($alphabet);

        $this->assertSame($alphabet, $drawn, 'a character is never drawn');
        $this->assertCount(36, $checks, 'a check character is never drawn');
    }
}
