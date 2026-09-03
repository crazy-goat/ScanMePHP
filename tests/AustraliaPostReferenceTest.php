<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Generator\AustraliaPost\AustraliaPostOptions;
use CrazyGoat\ScanMePHP\Generator\AustraliaPost\Bars;
use CrazyGoat\ScanMePHP\Generator\AustraliaPost\Format;
use CrazyGoat\ScanMePHP\Generator\FourState\Patterns;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Australia Post, bar for bar, against an encoder we did not write.
 *
 * No free decoder reads a four-state postal code, so this fixture and the
 * pixel read-back in {@see DecoderRoundTripTest} are the whole of the outside
 * opinion, and both of them ultimately compare against zint
 * (`tools/australia_post_reference.py`).
 *
 * It carries more of the symbology than the other four-state fixtures do,
 * because more of this symbology is arithmetic: two character tables that are
 * enumerations rather than lists, and four Reed-Solomon codewords that no
 * payload reaches directly. The coverage claims about both are asserted here
 * rather than trusted, and they are asserted on what the *fixture* says, not
 * on what our encoder does with it.
 */
class AustraliaPostReferenceTest extends TestCase
{
    /** @return \Generator<string, array{string, string, string}> */
    public static function referenceProvider(): \Generator
    {
        $csv = __DIR__ . '/fixtures/australia_post_reference.csv';
        $handle = fopen($csv, 'r');
        if ($handle === false) {
            return;
        }

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            [$data, $format, $states] = $row;
            yield "{$format} {$data}" => [$data, $format, $states];
        }

        fclose($handle);
    }

    #[DataProvider('referenceProvider')]
    public function testTheBarsMatchAnIndependentEncoder(string $data, string $format, string $expected): void
    {
        $symbol = Defaults::registry()
            ->getGenerator(Symbology::AustraliaPost->value)
            ->generate($data, new AustraliaPostOptions(Format::from($format)));

        $this->assertSame($expected, Patterns::states($symbol), "bars for {$format} {$data}");
    }

    /**
     * All six Format Control Codes are drawn.
     *
     * Three are the caller's choice and three follow from the width of the
     * customer field, and the fixture's own bars say which: the FCC is the two
     * digits after the start bars, and reading it back out of the drawing is
     * a claim about zint's symbols rather than about ours.
     */
    public function testTheFixtureDrawsEveryFormatControlCode(): void
    {
        $codes = [];

        foreach (self::referenceProvider() as [, , $states]) {
            $codes[$this->digitsAt($states, 2, 2)] = true;
        }

        ksort($codes);

        $this->assertSame(['11', '45', '59', '62', '87', '92'], array_map(strval(...), array_keys($codes)));
    }

    /**
     * Every character of both tables is drawn, in more than one position.
     *
     * The tables are enumerations, so an error in either is a run of adjacent
     * characters rather than one — and a character drawn correctly at the
     * front of a field and wrongly in the middle of it would be an encoder
     * that had grown a rule about position that the standard has not.
     */
    public function testTheFixtureDrawsEveryCharacterOfBothTables(): void
    {
        $characters = [];
        $digits = [];

        foreach (self::referenceProvider() as [$data, , ]) {
            $field = substr($data, 8);

            foreach (str_split($field) as $character) {
                if (\in_array(\strlen($field), [8, 15], true)) {
                    $digits[$character] = true;
                } else {
                    $characters[$character] = true;
                }
            }
        }

        $this->assertSame(
            str_split(Bars::CHARACTERS),
            $this->sorted($characters, Bars::CHARACTERS),
            'a C table character is never drawn'
        );
        $this->assertSame(
            str_split(Bars::DIGITS),
            $this->sorted($digits, Bars::DIGITS),
            'an N table digit is never drawn'
        );
    }

    /**
     * Every parity codeword value appears in every parity position.
     *
     * The four check codewords are the only part of the symbol a payload does
     * not reach, and a generator polynomial that is right for three of the
     * four positions draws symbols that pass any fixture of chosen payloads.
     * Sixty-four values in each of four positions is what makes that
     * impossible to miss.
     */
    public function testTheFixtureDrawsEveryParityValueInEveryPosition(): void
    {
        $seen = [[], [], [], []];

        foreach (self::referenceProvider() as [, , $states]) {
            $parity = substr($states, -14, 12);
            for ($position = 0; $position < 4; $position++) {
                $seen[$position][substr($parity, 3 * $position, 3)] = true;
            }
        }

        foreach ($seen as $position => $values) {
            $this->assertCount(64, $values, "parity position {$position} never takes every value");
        }
    }

    /** The digits a run of state letters spells in the N table. */
    private function digitsAt(string $states, int $bar, int $digits): string
    {
        $letters = array_column(Bars::STATES, 'value');
        $read = '';

        for ($index = 0; $index < $digits; $index++) {
            $pair = substr($states, $bar + 2 * $index, 2);
            $value = 4 * (int) array_search($pair[0], $letters, true) + (int) array_search($pair[1], $letters, true);

            foreach (str_split(Bars::DIGITS) as $digit) {
                if (Bars::value(Bars::numeric($digit)) === $value) {
                    $read .= $digit;
                }
            }
        }

        return $read;
    }

    /**
     * @param array<string, true> $seen
     * @return list<string>
     */
    private function sorted(array $seen, string $alphabet): array
    {
        // Cast: PHP turns the digit keys back into integers on the way out.
        $drawn = array_map(strval(...), array_keys($seen));
        usort($drawn, static fn (string $a, string $b): int => strpos($alphabet, $a) <=> strpos($alphabet, $b));

        return $drawn;
    }
}
