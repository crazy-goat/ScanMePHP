<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Generator\Gs1\ApplicationIdentifier;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GS1-128 against an implementation that is not ours, in two layers.
 *
 * The bars are Code 128 bars and are checked module for module like every
 * other symbology here. But a GS1-128 can have perfect bars and still be
 * wrong, because what makes it a GS1-128 is a table: which application
 * identifiers exist, how long each one's data may be, and whether an FNC1 has
 * to follow it. A missing separator does not corrupt the symbol — it makes the
 * next identifier read as more of the previous element's data, which is a
 * symbol that scans, as something else.
 *
 * So the table is a fixture too (tools/gs1_reference.py), swept exhaustively
 * over every two-, three- and four-digit string rather than transcribed from
 * the General Specifications.
 */
class Gs1ReferenceTest extends TestCase
{
    /** @return \Generator<string, array{string, string, string}> */
    public static function identifierProvider(): \Generator
    {
        $handle = fopen(__DIR__ . '/fixtures/gs1_ai_reference.csv', 'r');
        if ($handle === false) {
            return;
        }

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            [$ai, $lengths, $termination] = $row;
            yield $ai => [$ai, $lengths, $termination];
        }

        fclose($handle);
    }

    /** @return \Generator<string, array{string, string, string}> */
    public static function symbolProvider(): \Generator
    {
        $handle = fopen(__DIR__ . '/fixtures/gs1_128_reference.csv', 'r');
        if ($handle === false) {
            return;
        }

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            [$elements, $payloadHex, $modules] = $row;
            yield $elements => [$elements, $payloadHex, $modules];
        }

        fclose($handle);
    }

    #[DataProvider('identifierProvider')]
    public function testTheIdentifierTableMatchesAnIndependentEncoder(
        string $ai,
        string $lengths,
        string $termination
    ): void {
        $this->assertTrue(ApplicationIdentifier::exists($ai), "({$ai}) is missing from the table");

        $expected = str_contains($lengths, '-')
            ? range(...array_map(intval(...), explode('-', $lengths)))
            : array_map(intval(...), explode('|', $lengths));

        $this->assertSame($expected, ApplicationIdentifier::lengths($ai), "data lengths of ({$ai})");

        // The column that is not implied by the other two: predefined length
        // means the identifier is on GS1's published list, not that its length
        // is constant. (402) is seventeen digits and still needs a separator.
        $this->assertSame(
            $termination === 'separator',
            ApplicationIdentifier::needsSeparator($ai),
            "({$ai}) " . ($termination === 'separator' ? 'needs' : 'does not need') . ' an FNC1 after its data'
        );
    }

    #[DataProvider('symbolProvider')]
    public function testTheModulesMatchAnIndependentEncoder(
        string $elements,
        string $payloadHex,
        string $expected
    ): void {
        $symbol = Scanme::create()->generate($elements, Symbology::Gs1128);

        // The payload column is what a scanner reports: element strings run
        // together with FNC1 where one was needed. Checking it separately says
        // *why* a module mismatch happened when one does.
        $this->assertSame(
            $payloadHex,
            bin2hex(ElementString::parse($elements)->payload()),
            "separator placement in {$elements}"
        );

        $this->assertSame(\strlen($expected), $symbol->getWidth(), "width of {$elements}");
        $this->assertSame($expected, $symbol->toModuleString(), "modules for {$elements}");
    }

    public function testTheTableIsCompleteRatherThanASelection(): void
    {
        $rows = 0;
        foreach (self::identifierProvider() as $ignored) {
            $rows++;
        }

        // The sweep offers every two-, three- and four-digit string to the
        // reference encoder, so this is all of them and not a chosen subset.
        $this->assertSame($rows, \count(ApplicationIdentifier::all()));
        $this->assertGreaterThan(500, $rows);
    }

    /**
     * No identifier is a prefix of another.
     *
     * This is why a GS1 payload can be parsed at all: the identifiers run
     * straight into their data with nothing marking the boundary, so if (01)
     * and (0123) both existed there would be no way to tell which was meant.
     * The property holds for the whole table and nothing in this library
     * enforces it, so it is worth knowing if it ever stops holding.
     */
    public function testNoIdentifierIsAPrefixOfAnother(): void
    {
        $identifiers = ApplicationIdentifier::all();
        $known = array_flip($identifiers);
        $conflicts = [];

        foreach ($identifiers as $identifier) {
            // Only shorter identifiers can be prefixes, and there are three
            // possible lengths, so this is a lookup rather than a sweep.
            for ($length = ApplicationIdentifier::MIN_LENGTH; $length < \strlen($identifier); $length++) {
                $prefix = substr($identifier, 0, $length);
                if (isset($known[$prefix])) {
                    $conflicts[] = "({$prefix}) is a prefix of ({$identifier})";
                }
            }
        }

        $this->assertSame([], $conflicts);
    }

    public function testTheFixtureCoversBothTerminationRulesAndEveryIdentifierWidth(): void
    {
        $widths = [];
        $terminations = [];
        foreach (self::identifierProvider() as [$ai, , $termination]) {
            $widths[\strlen($ai)] = true;
            $terminations[$termination] = true;
        }

        $this->assertSame([2, 3, 4], array_keys($widths));
        $this->assertCount(2, $terminations, 'the fixture must exercise both termination rules');
    }
}
