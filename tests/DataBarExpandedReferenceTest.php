<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Generator\DataBar\Patterns;
use CrazyGoat\ScanMePHP\Generator\DataBarExpanded\Backend\PhpBackend;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GS1 DataBar Expanded, module for module, against an encoder we did not write.
 *
 * This is the fixture the symbology rests on, and it carries more weight here
 * than in the rest of the suite. Expanded has no pattern table to transcribe:
 * a character is a value walked out of an enumeration, the value comes from a
 * bit stream, the bit stream comes from a mode machine whose latch thresholds
 * are asymmetric and unguessable, and the check character comes from a
 * weighting sequence that is a different scramble for every symbol length.
 * Each of those was measured from this writer rather than read, and a mistake
 * in any of them produces a symbol that scans and says something else.
 *
 * So the payloads are chosen against the parts rather than against the data:
 * every symbol length from four characters to twenty-two, which is every finder
 * sequence and every weighting row; every character group boundary, driven
 * through the last data character; both encodation methods; and the latch
 * thresholds either side of each mode. tools/databar_expanded_reference.py says
 * what each one is for.
 */
class DataBarExpandedReferenceTest extends TestCase
{
    /** @return \Generator<string, array{string, string}> */
    public static function referenceProvider(): \Generator
    {
        $csv = __DIR__ . '/fixtures/databar_expanded_reference.csv';
        $handle = fopen($csv, 'r');
        if ($handle === false) {
            return;
        }

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            [$data, $modules] = $row;
            yield $data => [$data, $modules];
        }

        fclose($handle);
    }

    #[DataProvider('referenceProvider')]
    public function testTheModulesMatchAnIndependentEncoder(string $data, string $expected): void
    {
        $symbol = Defaults::registry()->getGenerator(Symbology::DataBarExpanded->value)->generate($data);

        $this->assertSame($expected, $symbol->toModuleString(), "modules for {$data}");
    }

    /**
     * Every length, which is every finder sequence and every weighting row.
     *
     * Both tables are indexed by length and neither has a rule behind it, so a
     * length the fixture never draws is a row of literals nothing has checked.
     */
    public function testTheFixtureDrawsEverySymbolLength(): void
    {
        $lengths = [];
        foreach (self::referenceProvider() as [, $modules]) {
            $lengths[$this->charactersIn(\strlen($modules))] = true;
        }

        ksort($lengths);

        $this->assertSame(range(4, 22), array_keys($lengths), 'a symbol length is never drawn');
        $this->assertSame(
            range(2, 11),
            array_keys(Patterns::EXPANDED_FINDER_SEQUENCES),
            'the finder sequences no longer cover two to eleven pairs'
        );
        $this->assertSame(
            range(3, 21),
            array_keys(Patterns::EXPANDED_WEIGHTS),
            'the weighting sequences no longer cover three to twenty-one characters'
        );
    }

    /** Every character group, and every one of them in a real symbol. */
    public function testTheFixtureReachesEveryCharacterGroup(): void
    {
        $groups = [];

        foreach (self::referenceProvider() as [$data, ]) {
            $symbol = Defaults::registry()->getGenerator(Symbology::DataBarExpanded->value)->generate($data);
            $modules = $symbol->toModuleString();

            foreach ($this->characterWidths($modules) as $widths) {
                $groups[array_sum([$widths[0], $widths[2], $widths[4], $widths[6]])] = true;
            }
        }

        ksort($groups);

        $this->assertSame(
            Patterns::EXPANDED['barModules'],
            array_reverse(array_keys($groups)),
            'a character group is never drawn'
        );
    }

    /** Both encodation methods, told apart by the first bits of the stream. */
    public function testTheFixtureUsesBothEncodationMethods(): void
    {
        $gtinFirst = 0;
        $general = 0;

        foreach (self::referenceProvider() as [$data, ]) {
            if (str_starts_with($data, '(01)')) {
                $gtinFirst++;
            } else {
                $general++;
            }
        }

        $this->assertGreaterThan(20, $gtinFirst, 'the AI 01 method is barely exercised');
        $this->assertGreaterThan(20, $general, 'the general purpose method is barely exercised');
    }

    /** The width is a function of the length and nothing else. */
    #[DataProvider('referenceProvider')]
    public function testEveryReferenceWidthIsTheLengthFormula(string $data, string $modules): void
    {
        $characters = $this->charactersIn(\strlen($modules));

        $this->assertSame(
            PhpBackend::GUARD_MODULES
                + PhpBackend::CHARACTER_MODULES * $characters
                + PhpBackend::FINDER_MODULES * intdiv($characters + 1, 2),
            \strlen($modules),
            "width for {$data}"
        );
    }

    private function charactersIn(int $width): int
    {
        for ($characters = 4; $characters <= 22; $characters++) {
            $modules = PhpBackend::GUARD_MODULES
                + PhpBackend::CHARACTER_MODULES * $characters
                + PhpBackend::FINDER_MODULES * intdiv($characters + 1, 2);

            if ($modules === $width) {
                return $characters;
            }
        }

        throw new \LogicException("{$width} modules is no symbol length");
    }

    /**
     * Every data character's widths, read back out of a drawn symbol.
     *
     * @return list<list<int>>
     */
    private function characterWidths(string $modules): array
    {
        $characters = $this->charactersIn(\strlen($modules));
        $widths = [];
        $position = 2;

        for ($index = 0; $index < $characters; $index++) {
            $character = $this->runLengths(substr($modules, $position, PhpBackend::CHARACTER_MODULES));
            $position += PhpBackend::CHARACTER_MODULES;

            // The right-hand character of a pair is drawn backwards, and the
            // canonical form is the one whose odd elements sum to an even
            // number — which is exactly one of the two directions.
            if (($character[0] + $character[2] + $character[4] + $character[6]) % 2 !== 0) {
                $character = array_reverse($character);
            }

            $widths[] = $character;

            if ($index % 2 === 0) {
                $position += PhpBackend::FINDER_MODULES;
            }
        }

        return $widths;
    }

    /** @return list<int> */
    private function runLengths(string $modules): array
    {
        $runs = [];
        $length = \strlen($modules);

        for ($i = 0; $i < $length;) {
            $run = 1;
            while ($i + $run < $length && $modules[$i + $run] === $modules[$i]) {
                $run++;
            }

            $runs[] = $run;
            $i += $run;
        }

        return $runs;
    }
}
