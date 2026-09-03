<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Encoding\Mode;
use CrazyGoat\ScanMePHP\Encoding\Rmqr\Specs;
use CrazyGoat\ScanMePHP\Encoding\Segmentation;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Generator\Rmqr\RmqrOptions;
use CrazyGoat\ScanMePHP\Generator\Rmqr\Version;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * rMQR, module for module, against an encoder we did not write.
 *
 * This fixture is the whole of the outside opinion on rMQR, which is a weaker
 * position than Micro QR's and a stronger one than the four-state postal
 * codes'. zint draws every symbol here, reached through `pyzint` with the size
 * and the level pinned rather than left to its own policy, so what is compared
 * is the encoding rather than a shared choice of shape. There is no reader:
 * zxing-cpp 3.1.1 lists RMQRCode among its formats and decodes neither our
 * symbols nor zint's own, so {@see DecoderRoundTripTest} exempts the symbology
 * and fails the day that stops being true.
 *
 * With one opinion rather than two, the fixture is exhaustive rather than
 * representative: all sixty-four cells, in all three modes, at the lengths
 * where an encoding goes wrong. {@see RmqrTest} carries the other half of the
 * argument — that the tables are coherent with the geometry, which is a thing
 * zint cannot tell us.
 */
class RmqrReferenceTest extends TestCase
{
    /** @return \Generator<string, array{string, string, string, string}> */
    public static function referenceProvider(): \Generator
    {
        $csv = __DIR__ . '/fixtures/rmqr_reference.csv';
        $handle = fopen($csv, 'r');
        if ($handle === false) {
            return;
        }

        fgetcsv($handle, 0, ',', '"', '');
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            [$data, $size, $ecc, $segments, $modules] = $row;
            yield "{$size}-{$ecc} {$data}" => [$data, $size, $ecc, $segments, $modules];
        }

        fclose($handle);
    }

    /**
     * Every module of every symbol, with nothing skipped.
     *
     * The Micro QR fixture next door has to skip the rows where zint's
     * segmentation heuristic and our shortest path choose differently. Here
     * there are no such rows: over all sixty-four cells and all three modes
     * the two encoders pick the same split every time, so the comparison is
     * unconditional. {@see testOurSplitAgreesWithTheReferenceEncoder} is what
     * keeps that honest — the day a divergence appears it fails, rather than
     * this one failing with a diff of two legitimate encodings.
     */
    #[DataProvider('referenceProvider')]
    public function testTheModulesMatchAnIndependentEncoder(
        string $data,
        string $size,
        string $ecc,
        string $segments,
        string $expected,
    ): void {
        $index = $this->index($size);
        $symbol = Defaults::registry()
            ->getGenerator(Symbology::Rmqr->value)
            ->generate($data, new RmqrOptions($this->level($ecc), Version::from($index)));

        $this->assertSame(Specs::width($index), $symbol->getWidth(), "width of {$size}");
        $this->assertSame(Specs::height($index), $symbol->getHeight(), "height of {$size}");

        $this->assertSame($expected, $symbol->toModuleString(), "modules for {$size}-{$ecc} {$data}");
    }

    /**
     * The two encoders choose the same split, everywhere.
     *
     * zint splits with a heuristic that pulls a run of digits out of an
     * alphanumeric segment, and {@see Segmentation} runs a shortest path; in
     * Micro QR the two part company on about one payload in a hundred. Here
     * they never do, which is what lets the module comparison above be
     * unconditional. Should that change, this fails first and says so, and the
     * repair is to reinstate the skip — not to weaken this.
     *
     * The cost is asserted alongside the split rather than instead of it,
     * because the interesting failure is a split of ours that is *worse* than
     * zint's: a shortest path cannot lose, so a longer encoding is a bug in
     * the step costs rather than a difference of opinion.
     */
    #[DataProvider('referenceProvider')]
    public function testOurSplitAgreesWithTheReferenceEncoder(
        string $data,
        string $size,
        string $ecc,
        string $segments,
    ): void {
        $index = $this->index($size);

        $this->assertLessThanOrEqual(
            $this->costOf($segments, $index),
            Segmentation::bits($data, $this->header($index)),
            "our split of {$data} at {$size} costs more than zint's",
        );
        $this->assertSame(
            $segments,
            $this->ourSegments($data, $index),
            "our split of {$data} at {$size}-{$ecc}",
        );
    }

    /**
     * All sixty-four cells are drawn, in all three modes.
     *
     * The count indicator is a different width in every one of the ninety-six
     * combinations, and a fixture that skipped one would be a fixture that
     * cannot tell a right width from a wrong one there.
     */
    public function testTheFixtureDrawsEveryCellInEveryMode(): void
    {
        $seen = [];

        foreach (self::referenceProvider() as [$data, $size, $ecc, $segments]) {
            foreach (explode('|', $segments) as $segment) {
                if ($segment !== '') {
                    $seen["{$size}-{$ecc}"][explode(':', $segment)[0]] = true;
                }
            }
        }

        $this->assertCount(64, $seen, 'a cell of the size table is never drawn');

        foreach ($seen as $cell => $modes) {
            ksort($modes);
            $this->assertSame(['A', 'B', 'N'], array_keys($modes), "{$cell} is missing a mode");
        }
    }

    /**
     * Every cell that interleaves more than one block is drawn at its longest.
     *
     * Interleaving is invisible when a payload leaves any block short — the
     * stream is the same either way for a single block, and a partly-filled
     * multi-block symbol can look right by accident. The longest payload is
     * the only length where every block is full, so it is the length that
     * catches a wrong block count.
     */
    public function testTheFixtureFillsEveryInterleavedCell(): void
    {
        $longest = [];

        foreach (self::referenceProvider() as [$data, $size, $ecc]) {
            $key = "{$size}-{$ecc}";
            $longest[$key] = max($longest[$key] ?? 0, \strlen($data));
        }

        $checked = 0;

        foreach (Specs::indexes() as $index) {
            foreach (Specs::levels() as $level) {
                if (Specs::blocks($index, $level) === 1) {
                    continue;
                }

                $checked++;
                $key = sprintf(
                    'R%dx%d-%s',
                    Specs::height($index),
                    Specs::width($index),
                    $level === ErrorCorrectionLevel::High ? 'H' : 'M',
                );

                // The numeric capacity is the longest payload the cell holds
                // in any mode, so reaching it means some symbol in this cell
                // filled every one of its blocks.
                $capacity = intdiv(Specs::dataBits($index, $level) - Specs::MODE_BITS
                    - Specs::countBits($index, Mode::Numeric), 10) * 3;

                $this->assertGreaterThanOrEqual(
                    $capacity,
                    $longest[$key] ?? 0,
                    "{$key} interleaves " . Specs::blocks($index, $level)
                        . ' blocks and is never drawn full',
                );
            }
        }

        $this->assertGreaterThan(25, $checked, 'the block table has stopped interleaving anything');
    }

    /** The split we would choose, spelled the way the fixture spells zint's. */
    private function ourSegments(string $data, int $index): string
    {
        return implode('|', array_map(
            static fn (array $segment): string => sprintf(
                '%s:%d',
                match ($segment[0]) {
                    Mode::Numeric => 'N',
                    Mode::Alphanumeric => 'A',
                    default => 'B',
                },
                \strlen($segment[1]),
            ),
            Segmentation::optimal($data, $this->header($index)),
        ));
    }

    /** What the reference encoder's own split costs, in bits, at this size. */
    private function costOf(string $segments, int $index): int
    {
        $bits = 0;

        foreach (explode('|', $segments) as $segment) {
            if ($segment === '') {
                continue;
            }

            [$letter, $count] = explode(':', $segment);
            $count = (int) $count;
            $mode = match ($letter) {
                'N' => Mode::Numeric,
                'A' => Mode::Alphanumeric,
                default => Mode::Byte,
            };

            $bits += Specs::MODE_BITS + Specs::countBits($index, $mode) + match ($mode) {
                Mode::Numeric => intdiv($count, 3) * 10 + match ($count % 3) {
                    1 => 4,
                    2 => 7,
                    default => 0,
                },
                Mode::Alphanumeric => intdiv($count, 2) * 11 + ($count % 2) * 6,
                default => $count * 8,
            };
        }

        return $bits;
    }

    /** @return callable(Mode): ?int */
    private function header(int $index): callable
    {
        return static fn (Mode $mode): ?int => Specs::supportsMode($mode)
            ? Specs::MODE_BITS + Specs::countBits($index, $mode)
            : null;
    }

    private function index(string $size): int
    {
        foreach (Specs::indexes() as $index) {
            if (sprintf('R%dx%d', Specs::height($index), Specs::width($index)) === $size) {
                return $index;
            }
        }

        throw new \LogicException("the fixture names a size that does not exist: {$size}");
    }

    private function level(string $ecc): ErrorCorrectionLevel
    {
        return $ecc === 'H' ? ErrorCorrectionLevel::High : ErrorCorrectionLevel::Medium;
    }
}
