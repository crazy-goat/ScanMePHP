<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Rmqr;

use CrazyGoat\ScanMePHP\Encoding\Mode;
use CrazyGoat\ScanMePHP\Encoding\ReedSolomon256;
use CrazyGoat\ScanMePHP\Encoding\Segment;
use CrazyGoat\ScanMePHP\Encoding\Segmentation;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;
use CrazyGoat\ScanMePHP\Exception\InvalidDataException;

/**
 * rMQR, end to end: a payload in, a masked module grid out.
 *
 * The shape of this file is Micro QR's rather than QR's, and for the same
 * reason: the cost of a payload depends on the size it is going into, because
 * the character count indicator is a different width in every one of the
 * thirty-two sizes. So the search re-costs the payload at each candidate size
 * rather than measuring it once and looking the answer up.
 *
 * What is here and not in the Micro QR encoder is **block interleaving**. Half
 * the rMQR cells split their data into two to six Reed–Solomon blocks and
 * interleave them, exactly as QR does, so that a scuff across a long thin
 * symbol damages a few codewords in each block rather than destroying one
 * block outright. That matters more here than in a square: a symbol seventeen
 * modules tall and a hundred and thirty-nine wide is one where damage is a
 * band across the middle, not a spot.
 *
 * @internal Backend of the rmqr generator; use Scanme instead.
 */
final class RmqrEncoder
{
    /** ISO/IEC 23941 pads with these two alternating, as QR does. */
    private const PAD_CODEWORDS = [0xec, 0x11];

    private ?ReedSolomon256 $reedSolomon = null;

    /**
     * @param int|null $index One of the thirty-two sizes, or null for the
     *        smallest that fits.
     * @param ErrorCorrectionLevel|null $level null for the stronger of the two
     *        levels the chosen size can give this payload.
     * @return array{
     *     matrix: list<list<bool>>,
     *     index: int,
     *     level: ErrorCorrectionLevel,
     *     modes: list<Mode>,
     * }
     */
    public function encode(string $data, ?int $index = null, ?ErrorCorrectionLevel $level = null): array
    {
        if ($data === '') {
            throw InvalidDataException::emptyData();
        }

        [$index, $level] = $this->choose($data, $index, $level);
        $segments = Segmentation::optimal($data, self::header($index));

        $layout = new Layout($index);
        $layout->place($this->codewords($segments, $index, $level));
        $layout->formatInformation($level);

        return [
            'matrix' => $layout->toBooleans(),
            'index' => $index,
            'level' => $level,
            'modes' => array_map(static fn (array $segment): Mode => $segment[0], $segments),
        ];
    }

    /** Whether any rMQR symbol holds this payload at this size and level. */
    public static function fits(string $data, ?int $index = null, ?ErrorCorrectionLevel $level = null): bool
    {
        if ($data === '') {
            return false;
        }

        foreach ($index !== null ? [$index] : Specs::indexes() as $candidate) {
            $bits = Segmentation::bits($data, self::header($candidate));

            foreach (self::levelsFor($level) as $candidateLevel) {
                if ($bits <= Specs::dataBits($candidate, $candidateLevel)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The sizes worth trying, smallest area first.
     *
     * "Smallest" is a genuine choice here in a way it is not for QR, because
     * thirty-two rectangles are not totally ordered by anything a caller
     * cares about: R11x27 has fewer modules than R7x59 and is a completely
     * different shape. Area then height is the order, so a tie between two
     * shapes of the same area goes to the flatter one — which is the reason
     * anybody reaches for this symbology.
     *
     * @return list<int>
     */
    public static function order(): array
    {
        $indexes = Specs::indexes();

        usort($indexes, static function (int $a, int $b): int {
            $areaA = Specs::height($a) * Specs::width($a);
            $areaB = Specs::height($b) * Specs::width($b);

            return $areaA <=> $areaB ?: Specs::height($a) <=> Specs::height($b);
        });

        return $indexes;
    }

    /**
     * The levels worth trying, strongest first.
     *
     * Strongest first is the policy, not an accident: with the level left open
     * a payload that fits at H is given H rather than M, because the size is
     * the same either way and the recovery is free.
     *
     * @return list<ErrorCorrectionLevel>
     */
    private static function levelsFor(?ErrorCorrectionLevel $pinned): array
    {
        if ($pinned instanceof ErrorCorrectionLevel) {
            return Specs::supports($pinned) ? [$pinned] : [];
        }

        return array_reverse(Specs::levels());
    }

    /** @return callable(Mode): ?int */
    private static function header(int $index): callable
    {
        return static fn (Mode $mode): ?int => Specs::supportsMode($mode)
            ? Specs::MODE_BITS + Specs::countBits($index, $mode)
            : null;
    }

    /**
     * @return array{0: int, 1: ErrorCorrectionLevel}
     */
    private function choose(string $data, ?int $index, ?ErrorCorrectionLevel $level): array
    {
        foreach ($index !== null ? [$index] : self::order() as $candidate) {
            $bits = Segmentation::bits($data, self::header($candidate));

            foreach (self::levelsFor($level) as $candidateLevel) {
                if ($bits <= Specs::dataBits($candidate, $candidateLevel)) {
                    return [$candidate, $candidateLevel];
                }
            }
        }

        throw $this->refuse($data, $index, $level);
    }

    /**
     * Why the payload was refused.
     *
     * There is no "no mode covers this alphabet" case here, and that is a fact
     * about rMQR rather than an omission: byte mode exists at every one of the
     * thirty-two shapes, so any payload fits somewhere if it is short enough.
     * Micro QR needs that distinction because M1 and M2 have no byte mode at
     * all; this one only ever runs out of room.
     */
    private function refuse(string $data, ?int $index, ?ErrorCorrectionLevel $level): \Exception
    {
        if ($level instanceof ErrorCorrectionLevel && !Specs::supports($level)) {
            return new InvalidDataException(sprintf(
                'rMQR has no error correction level %s; it offers M and H',
                $level->name,
            ));
        }

        $largest = $index ?? self::order()[Specs::count() - 1];
        $weakest = $level ?? ErrorCorrectionLevel::Medium;

        return DataTooLargeException::forSymbolSize(
            Segmentation::bits($data, self::header($largest)),
            Specs::dataBits($largest, $weakest),
            $this->describe($largest, $weakest),
            'bits',
        );
    }

    private function describe(int $index, ErrorCorrectionLevel $level): string
    {
        return sprintf(
            'rMQR R%dx%d-%s',
            Specs::height($index),
            Specs::width($index),
            $level->name === 'High' ? 'H' : 'M',
        );
    }

    /**
     * The payload as an interleaved codeword stream, error correction included.
     *
     * @param list<array{Mode, string}> $segments
     * @return list<int>
     */
    private function codewords(array $segments, int $index, ErrorCorrectionLevel $level): array
    {
        $bits = [];

        foreach ($segments as [$mode, $chunk]) {
            $bits = [
                ...$bits,
                ...Segment::pack(Specs::modeValue($mode), Specs::MODE_BITS),
                ...Segment::pack(\strlen($chunk), Specs::countBits($index, $mode)),
                ...match ($mode) {
                    Mode::Numeric => Segment::numeric($chunk),
                    Mode::Alphanumeric => Segment::alphanumeric($chunk),
                    default => Segment::byte($chunk),
                },
            ];
        }

        $capacity = Specs::dataBits($index, $level);
        $bits = [...$bits, ...array_fill(0, min(Specs::TERMINATOR_BITS, $capacity - \count($bits)), 0)];

        while (\count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $data = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            $data[] = (int) bindec(implode('', $chunk));
        }

        $pad = 0;
        $wanted = Specs::dataCodewords($index, $level);
        while (\count($data) < $wanted) {
            $data[] = self::PAD_CODEWORDS[$pad++ % 2];
        }

        return $this->interleave($data, $index, $level);
    }

    /**
     * Splits the data into blocks, adds each block's check codewords, and
     * reads the whole lot back column by column.
     *
     * The split is QR's: the remainder is spread over the *last* blocks, so
     * a hundred and six codewords in three blocks come out as 35, 35, 36 and
     * never 36, 35, 35. Each block gets the same number of check codewords,
     * which is why the block count always divides the error correction total.
     *
     * @param list<int> $data
     * @return list<int>
     */
    private function interleave(array $data, int $index, ErrorCorrectionLevel $level): array
    {
        $blocks = Specs::blocks($index, $level);
        $check = intdiv(Specs::errorCorrectionCodewords($index, $level), $blocks);
        $short = intdiv(\count($data), $blocks);
        $long = \count($data) % $blocks;

        $this->reedSolomon ??= ReedSolomon256::forQr();

        $dataBlocks = [];
        $checkBlocks = [];
        $offset = 0;

        for ($block = 0; $block < $blocks; $block++) {
            $length = $short + ($block >= $blocks - $long ? 1 : 0);
            $chunk = \array_slice($data, $offset, $length);
            $offset += $length;

            $dataBlocks[] = $chunk;
            $checkBlocks[] = $this->reedSolomon->encode($chunk, $check);
        }

        $stream = [];
        for ($position = 0; $position < $short + ($long > 0 ? 1 : 0); $position++) {
            foreach ($dataBlocks as $chunk) {
                if (isset($chunk[$position])) {
                    $stream[] = $chunk[$position];
                }
            }
        }
        for ($position = 0; $position < $check; $position++) {
            foreach ($checkBlocks as $chunk) {
                $stream[] = $chunk[$position];
            }
        }

        return $stream;
    }
}
