<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Encoding\MicroQr\Layout;
use CrazyGoat\ScanMePHP\Encoding\MicroQr\Segments;
use CrazyGoat\ScanMePHP\Encoding\MicroQr\Specs;
use CrazyGoat\ScanMePHP\Encoding\Mode;
use CrazyGoat\ScanMePHP\Encoding\Segment;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Generator\MicroQr\MicroQrOptions;
use CrazyGoat\ScanMePHP\Generator\MicroQr\Version;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Micro QR, module for module, against an encoder we did not write.
 *
 * The fixture comes from zint through `tools/micro_qr_reference.py`, with the
 * version and error correction level pinned on every row so that what is being
 * compared is the encoding and not a shared policy about which level to reach
 * for. The reader's opinion — zxing-cpp, a different project — is separate and
 * lives in {@see DecoderRoundTripTest}.
 *
 * The mask is compared rather than pinned. Micro QR's mask rule is not QR's
 * and is not open to interpretation the way QR's is: it counts dark modules
 * along the two edges furthest from the finder and takes the highest score,
 * with no penalty rules to read differently. Our automatic choice agrees with
 * zint's on every payload in the fixture, so the column is an assertion rather
 * than a recording — if that ever stops being true, this is where it shows.
 */
class MicroQrReferenceTest extends TestCase
{
    /** @return \Generator<string, array{string, string, string, int, string, string}> */
    public static function referenceProvider(): \Generator
    {
        $csv = __DIR__ . '/fixtures/micro_qr_reference.csv';
        $handle = fopen($csv, 'r');
        if ($handle === false) {
            return;
        }

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            [$data, $version, $ecc, $mask, $segments, $modules] = $row;
            yield "{$version}-{$ecc} {$data}" => [$data, $version, $ecc, (int) $mask, $segments, $modules];
        }

        fclose($handle);
    }

    /**
     * The modules, where the two encoders split the payload the same way.
     *
     * They usually do. Where they do not, there is nothing to compare module
     * for module and the claim that survives is the one in
     * {@see testOurSplitIsNeverLongerThanTheReferenceEncoders} — so the
     * disagreeing rows are not skipped, they are checked differently.
     */
    #[DataProvider('referenceProvider')]
    public function testTheModulesMatchAnIndependentEncoder(
        string $data,
        string $version,
        string $ecc,
        int $mask,
        string $segments,
        string $expected,
    ): void {
        $number = (int) substr($version, 1);

        $symbol = Defaults::registry()
            ->getGenerator(Symbology::MicroQr->value)
            ->generate($data, new MicroQrOptions(
                errorCorrection: $this->level($ecc),
                version: Version::from($number),
            ));

        // Whichever way the payload was split, the symbol is the same size:
        // the version was pinned, and a version is a size.
        $this->assertSame(
            \strlen($expected),
            \strlen($symbol->toModuleString()),
            "size for {$version}-{$ecc} {$data}",
        );

        if ($this->ourSegments($data, $number) !== $segments) {
            return;
        }

        $this->assertSame($expected, $symbol->toModuleString(), "modules for {$version}-{$ecc} {$data}");
        $this->assertSame($mask, $symbol->getMetadata()['mask'], "mask for {$version}-{$ecc} {$data}");
    }

    /**
     * Where the splits differ, ours is never the longer one.
     *
     * Mode selection is the one part of Micro QR encoding that is a choice
     * rather than a rule: both symbols carry the same characters and both
     * scan, and ISO/IEC 18004 says nothing about which segmentation to prefer.
     * zint uses a heuristic that lifts a run of digits out of an alphanumeric
     * segment; {@see Segments} runs a shortest path, so it cannot lose. Over
     * nine hundred random payloads the two agreed on all but eight, and on
     * those eight zint tied four times and was one bit longer four times.
     *
     * This is the assertion that would catch the search going wrong in the
     * direction that matters — a split that costs *more* than the obvious
     * single segment, which would cost capacity and sometimes a whole version.
     */
    #[DataProvider('referenceProvider')]
    public function testOurSplitIsNeverLongerThanTheReferenceEncoders(
        string $data,
        string $version,
        string $ecc,
        int $mask,
        string $segments,
    ): void {
        $number = (int) substr($version, 1);

        $this->assertLessThanOrEqual(
            $this->cost($segments, $number),
            Segments::bits($data, $number),
            "bits for {$version}-{$ecc} {$data}",
        );
    }

    /** Our own split, in the fixture's notation: `A:3|N:4`. */
    private function ourSegments(string $data, int $version): string
    {
        return implode('|', array_map(
            static fn (array $segment): string => match ($segment[0]) {
                Mode::Numeric => 'N',
                Mode::Alphanumeric => 'A',
                default => 'B',
            } . ':' . \strlen($segment[1]),
            Segments::optimal($data, $version),
        ));
    }

    /** What a split in the fixture's notation costs, headers included. */
    private function cost(string $segments, int $version): int
    {
        $bits = 0;

        foreach (explode('|', $segments) as $segment) {
            [$letter, $count] = explode(':', $segment);
            $count = (int) $count;
            $mode = match ($letter) {
                'N' => Mode::Numeric,
                'A' => Mode::Alphanumeric,
                default => Mode::Byte,
            };

            $bits += Specs::modeBits($version)
                + Specs::countBits($version, $mode)
                + match ($mode) {
                    Mode::Numeric => Segment::numericBits($count),
                    Mode::Alphanumeric => Segment::alphanumericBits($count),
                    default => Segment::byteBits($count),
                };
        }

        return $bits;
    }

    /**
     * The fixture contains rows where the two encoders split differently.
     *
     * Without this the branch above that handles a disagreement is dead code,
     * and a regenerated fixture that happened to contain only agreeing rows
     * would look like a stronger test than it is.
     */
    public function testTheFixtureContainsSplitsTheReferenceEncoderDisagreesWith(): void
    {
        $disagreements = 0;
        foreach (self::referenceProvider() as [$data, $version, , , $segments]) {
            if ($this->ourSegments($data, (int) substr($version, 1)) !== $segments) {
                $disagreements++;
            }
        }

        $this->assertGreaterThan(0, $disagreements, 'rows where the splits differ');
    }

    /**
     * Every legal pairing of version and level is drawn, and no illegal one is.
     *
     * There are eight, and they are not the twelve a version-times-level grid
     * would give: M1 has no level, and Q exists only at M4. A fixture that had
     * quietly lost one of the eight would still pass every row it did contain.
     */
    public function testTheFixtureDrawsEveryVersionAndLevel(): void
    {
        $drawn = [];
        foreach (self::referenceProvider() as [, $version, $ecc]) {
            $drawn["{$version}-{$ecc}"] = true;
        }

        $expected = ['M1-detect', 'M2-L', 'M2-M', 'M3-L', 'M3-M', 'M4-L', 'M4-M', 'M4-Q'];
        sort($expected);
        $found = array_keys($drawn);
        sort($found);

        $this->assertSame($expected, $found);
    }

    /**
     * Every symbol number is drawn at every mask.
     *
     * The format information is fifteen bits of BCH over five, and the five are
     * the symbol number and the mask together. Thirty-two values, and a
     * generator polynomial or an XOR constant that is right for the values
     * ordinary payloads reach is exactly the mistake a smaller fixture misses.
     * The pair is read out of zint's own modules, so this is a claim about the
     * fixture rather than about our encoder.
     */
    public function testTheFixtureDrawsEverySymbolNumberAtEveryMask(): void
    {
        $numbers = [
            'M1-detect' => 0, 'M2-L' => 1, 'M2-M' => 2, 'M3-L' => 3,
            'M3-M' => 4, 'M4-L' => 5, 'M4-M' => 6, 'M4-Q' => 7,
        ];

        $pairs = [];
        foreach (self::referenceProvider() as [, $version, $ecc, $mask]) {
            $pairs["{$numbers["{$version}-{$ecc}"]}:{$mask}"] = true;
        }

        $this->assertCount(32, $pairs, 'every symbol number at every one of the four masks');
    }

    /**
     * Both versions that end on a nibble are drawn with that nibble non-zero.
     *
     * This is the one property the fixture exists for that would otherwise go
     * unchecked: a right-aligned final nibble puts every module in the right
     * place and computes the error correction over a different message. A zero
     * nibble is the same either way, so the fixture has to contain rows where
     * it is not.
     *
     * The nibble is read back out of zint's own modules — unmasked, then
     * un-zigzagged — rather than out of our encoder, so this says something
     * about the fixture rather than restating what the row comparison already
     * covers.
     */
    public function testTheFixtureDrawsANonZeroFinalNibble(): void
    {
        $found = ['M1' => 0, 'M3' => 0];

        foreach (self::referenceProvider() as [, $version, $ecc, $mask, , $modules]) {
            if (!isset($found[$version])) {
                continue;
            }

            $number = (int) substr($version, 1);
            $bits = $this->dataBits($modules, $number, $mask);
            $nibble = substr($bits, Specs::dataBits($number, $this->level($ecc)) - 4, 4);

            if ($nibble !== '0000') {
                $found[$version]++;
            }
        }

        $this->assertGreaterThan(0, $found['M1'], 'M1 rows whose final nibble is not zero');
        $this->assertGreaterThan(0, $found['M3'], 'M3 rows whose final nibble is not zero');
    }

    /**
     * The bit stream a drawn symbol carries: unmask, then follow the zigzag.
     *
     * This is {@see Layout::place()} run backwards, and it uses the same two
     * public predicates — which function modules there are and which modules a
     * mask flips — so a symbol our encoder drew and a symbol zint drew are
     * read the same way.
     */
    private function dataBits(string $modules, int $version, int $mask): string
    {
        $size = Specs::size($version);
        $grid = [];
        for ($row = 0; $row < $size; $row++) {
            for ($column = 0; $column < $size; $column++) {
                $grid[$row][$column] = (int) $modules[$row * $size + $column];
            }
        }

        $layout = new Layout($version);
        for ($row = 0; $row < $size; $row++) {
            for ($column = 0; $column < $size; $column++) {
                if (!$layout->isFunction($row, $column) && Layout::masks($mask, $row, $column)) {
                    $grid[$row][$column] ^= 1;
                }
            }
        }

        $bits = '';
        $upwards = true;
        for ($column = $size - 1; $column >= 1; $column -= 2) {
            foreach ($upwards ? range($size - 1, 0) : range(0, $size - 1) as $row) {
                foreach ([$column, $column - 1] as $target) {
                    if (!$layout->isFunction($row, $target)) {
                        $bits .= $grid[$row][$target];
                    }
                }
            }

            $upwards = !$upwards;
        }

        return $bits;
    }

    private function level(string $ecc): ?ErrorCorrectionLevel
    {
        return match ($ecc) {
            'L' => ErrorCorrectionLevel::Low,
            'M' => ErrorCorrectionLevel::Medium,
            'Q' => ErrorCorrectionLevel::Quartile,
            default => null,
        };
    }
}
