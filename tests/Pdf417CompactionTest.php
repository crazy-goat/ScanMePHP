<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoding\Pdf417\Compaction;
use CrazyGoat\ScanMePHP\Encoding\Pdf417\HighLevelEncoder;
use CrazyGoat\ScanMePHP\Encoding\Pdf417\ReedSolomonGf929;
use CrazyGoat\ScanMePHP\Encoding\Pdf417\TextSubmodes;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic and the mode decisions, checked away from any symbol.
 *
 * These are the parts the reference fixture cannot isolate. A wrong submode
 * route or an off-by-one in a base conversion shows up there as "the modules
 * differ" and nothing more, and three of the forty fixture rows are ties where
 * the modules legitimately differ anyway, so the interesting claims are worth
 * stating directly.
 */
class Pdf417CompactionTest extends TestCase
{
    /**
     * The closed form for a numeric group's size, checked rather than assumed.
     *
     * The encoder's optimiser depends on knowing what a digit costs without
     * converting anything, and that only works because the count is
     * ceil((n + 1) / 3) for every group size and — the surprising half — does
     * not depend on the digits. In general the number of base-900 digits of a
     * number depends on the number; here the guard digit pins the value into
     * the window [10^n, 2 * 10^n), and no power of 900 falls inside any of
     * those windows for a group of one to forty-four digits.
     */
    public function testAGroupOfDigitsIsTheSameSizeWhateverTheDigitsAre(): void
    {
        for ($length = 1; $length <= Compaction::NUMERIC_GROUP; $length++) {
            $smallest = Compaction::numeric(str_repeat('0', $length));
            $largest = Compaction::numeric(str_repeat('9', $length));

            $this->assertCount(
                Compaction::codewordsForDigits($length),
                $smallest,
                sprintf('%d zeroes', $length),
            );
            $this->assertCount(
                Compaction::codewordsForDigits($length),
                $largest,
                sprintf('%d nines', $length),
            );
        }
    }

    public function testDigitsAreGroupedFromTheLeft(): void
    {
        // A forty-fifth digit opens a second group and costs a whole codeword
        // of its own, which is exactly why the optimiser sometimes leaves a
        // trailing digit in text compaction instead.
        $this->assertCount(15, Compaction::numeric(str_repeat('1', 44)));
        $this->assertCount(16, Compaction::numeric(str_repeat('1', 45)));
        $this->assertCount(30, Compaction::numeric(str_repeat('1', 88)));
    }

    public function testALeadingZeroSurvivesTheConversion(): void
    {
        // The guard digit exists for this: without it the base conversion
        // would lose leading zeroes and a payload would come back shorter.
        $this->assertNotSame(
            Compaction::numeric('0001'),
            Compaction::numeric('1'),
        );
    }

    public function testSixBytesBecomeFiveCodewordsAndATailDoesNot(): void
    {
        $this->assertCount(5, Compaction::bytes('abcdef'));
        $this->assertCount(6, Compaction::bytes('abcdefg'));
        $this->assertCount(10, Compaction::bytes('abcdefghijkl'));

        // The tail is not converted at all — one codeword per byte, which is
        // why six is the group size the optimiser cares about.
        $this->assertSame([\ord('a'), \ord('b')], Compaction::bytes('ab'));
    }

    /** @return \Generator<string, array{int, int, list<int>}> */
    public static function submodeRouteProvider(): \Generator
    {
        yield 'Lower to Alpha goes the long way round' => [
            TextSubmodes::LOWER,
            TextSubmodes::ALPHA,
            [28, 28],
        ];
        yield 'Alpha to Punctuation goes through Mixed' => [
            TextSubmodes::ALPHA,
            TextSubmodes::PUNCT,
            [28, 25],
        ];
        yield 'Alpha to Mixed is one latch' => [TextSubmodes::ALPHA, TextSubmodes::MIXED, [28]];
        yield 'Punctuation to Alpha is one latch' => [TextSubmodes::PUNCT, TextSubmodes::ALPHA, [29]];
        yield 'Punctuation to Lower goes through Alpha' => [
            TextSubmodes::PUNCT,
            TextSubmodes::LOWER,
            [29, 27],
        ];
    }

    /**
     * The route table, which is derived and therefore worth checking.
     *
     * The Lower-to-Alpha case is the one that catches a transcription: there
     * is no latch from Lower back to Alpha, only a shift, so staying there
     * costs two latches out through Mixed. A reader who assumes the table is
     * symmetric writes one code where two belong.
     *
     * @param list<int> $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('submodeRouteProvider')]
    public function testTheLatchRoutesComeOutShortest(int $from, int $to, array $expected): void
    {
        $this->assertSame($expected, TextSubmodes::latchRoute($from, $to));
    }

    public function testAShiftIsAvailableWhereTheStandardSaysAndNotElsewhere(): void
    {
        $this->assertSame(27, TextSubmodes::shift(TextSubmodes::LOWER, TextSubmodes::ALPHA));
        $this->assertSame(29, TextSubmodes::shift(TextSubmodes::ALPHA, TextSubmodes::PUNCT));

        // There is no shift into Lower from anywhere, which is the asymmetry
        // that makes one capital inside a lower-case word cheap and one
        // lower-case letter inside a capitalised word dear.
        $this->assertNull(TextSubmodes::shift(TextSubmodes::ALPHA, TextSubmodes::LOWER));
        $this->assertNull(TextSubmodes::shift(TextSubmodes::MIXED, TextSubmodes::LOWER));
    }

    /**
     * Which bytes text compaction cannot carry at all.
     *
     * Everything printable and three of the control characters have a code in
     * some submode; the rest have to go through byte compaction, and knowing
     * exactly how many there are is what makes "this payload needs byte
     * compaction" a statement rather than a guess.
     */
    public function testExactlyTheseBytesNeedByteCompaction(): void
    {
        $needsBytes = [];
        for ($byte = 0; $byte < 256; $byte++) {
            if (!TextSubmodes::isTextual(\chr($byte))) {
                $needsBytes[] = $byte;
            }
        }

        $expected = [...range(0, 8), 11, 12, ...range(14, 31), ...range(127, 255)];

        $this->assertSame($expected, $needsBytes);
        $this->assertCount(158, $needsBytes);
    }

    public function testASingleForeignByteCostsAShiftRatherThanAModeLatch(): void
    {
        $codewords = (new HighLevelEncoder())->encode('AB' . \chr(200) . 'CD');

        // Byte shift, the byte, and the letters around it in text compaction:
        // latching into byte compaction and back would cost two more codewords
        // for one byte.
        $this->assertContains(HighLevelEncoder::SHIFT_BYTE, $codewords);
        $this->assertNotContains(HighLevelEncoder::LATCH_BYTE, $codewords);
    }

    public function testARunOfForeignBytesLatchesInsteadOfShiftingEachOne(): void
    {
        $codewords = (new HighLevelEncoder())->encode(str_repeat(\chr(200), 12));

        // Twelve bytes is two whole groups, so the grouped latch applies.
        $this->assertSame(HighLevelEncoder::LATCH_BYTE_GROUPED, $codewords[0]);
        $this->assertNotContains(HighLevelEncoder::SHIFT_BYTE, $codewords);
        $this->assertCount(1 + 10, $codewords);
    }

    public function testAByteRunThatIsNotAWholeNumberOfGroupsUsesThePlainLatch(): void
    {
        $codewords = (new HighLevelEncoder())->encode(str_repeat(\chr(200), 13));

        $this->assertSame(HighLevelEncoder::LATCH_BYTE, $codewords[0]);
    }

    public function testALongDigitRunGoesThroughNumericCompaction(): void
    {
        $codewords = (new HighLevelEncoder())->encode(str_repeat('7', 30));

        $this->assertSame(HighLevelEncoder::LATCH_NUMERIC, $codewords[0]);
        $this->assertCount(1 + Compaction::codewordsForDigits(30), $codewords);
    }

    public function testAShortDigitRunStaysInTextCompaction(): void
    {
        // Five digits cost three codewords either way — a latch to Mixed and
        // five slots, or a mode latch and two converted codewords — and the
        // encoder prefers not to change mode when the price is the same.
        $codewords = (new HighLevelEncoder())->encode('AB 10001');

        $this->assertNotContains(HighLevelEncoder::LATCH_NUMERIC, $codewords);
    }

    /**
     * Reed–Solomon over a prime field, anchored to a symbol we did not make.
     *
     * The stream is zxing-cpp's own encoding of "12345678901234567890" at
     * error correction level 0, read back out of its symbol: ten data
     * codewords and two check codewords. Getting the sign convention wrong
     * produces plausible check codewords that no reader accepts, so this is
     * worth pinning away from the fixture as well as in it.
     */
    public function testTheCheckCodewordsMatchAnIndependentEncoder(): void
    {
        $data = [10, 902, 211, 358, 354, 304, 269, 753, 190, 900];

        $this->assertSame([870, 423], (new ReedSolomonGf929())->encode($data, 2));
    }

    public function testTheNumberOfCheckCodewordsDoublesWithEachLevel(): void
    {
        $reedSolomon = new ReedSolomonGf929();
        $data = [10, 902, 211, 358, 354, 304, 269, 753, 190, 900];

        foreach (range(0, 8) as $level) {
            $this->assertCount(1 << ($level + 1), $reedSolomon->encode($data, 1 << ($level + 1)));
        }
    }

    public function testEveryCheckCodewordIsInTheField(): void
    {
        $codewords = (new ReedSolomonGf929())->encode(range(1, 100), 32);

        foreach ($codewords as $codeword) {
            $this->assertGreaterThanOrEqual(0, $codeword);
            $this->assertLessThan(ReedSolomonGf929::MODULUS, $codeword);
        }
    }
}
