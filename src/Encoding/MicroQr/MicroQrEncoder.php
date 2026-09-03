<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\MicroQr;

use CrazyGoat\ScanMePHP\Encoding\Mode;
use CrazyGoat\ScanMePHP\Encoding\ReedSolomon256;
use CrazyGoat\ScanMePHP\Encoding\Segment;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;
use CrazyGoat\ScanMePHP\Exception\InvalidDataException;

/**
 * Micro QR, end to end: a payload in, a masked module grid out.
 *
 * Unlike the QR pipeline next door this is one class rather than four, and it
 * has to be. QR settles its version first and everything else follows;
 * Micro QR cannot, because the *cost* of a payload depends on the version it
 * is going into — the mode indicator and the character count are both narrower
 * in a smaller symbol, and the cheapest way to split the payload between modes
 * changes with them. `LOT4471` is two segments at M3 and one at M4. So the
 * search below re-costs the payload at each version in size order rather than
 * computing a length once and looking it up.
 *
 * There is no block interleaving here. Every Micro QR symbol is one Reed–
 * Solomon block, which is most of what makes this file short.
 *
 * @internal Backend of the micro-qr generator; use Scanme instead.
 */
final class MicroQrEncoder
{
    /** ISO/IEC 18004 pads with these two alternating, as QR does. */
    private const PAD_CODEWORDS = [0xec, 0x11];

    private ?ReedSolomon256 $reedSolomon = null;

    /**
     * @param int|null $version M1 to M4 as 1 to 4, or null for the smallest
     *        that fits.
     * @param ErrorCorrectionLevel|null $level null for the strongest level the
     *        chosen version can give this payload, which is what every encoder
     *        this library is checked against does.
     * @param int|null $mask 0 to 3, or null to score the four.
     * @return array{
     *     matrix: list<list<bool>>,
     *     version: int,
     *     level: ErrorCorrectionLevel|null,
     *     modes: list<Mode>,
     *     mask: int,
     * }
     */
    public function encode(
        string $data,
        ?int $version = null,
        ?ErrorCorrectionLevel $level = null,
        ?int $mask = null,
    ): array {
        if ($data === '') {
            throw InvalidDataException::emptyData();
        }

        [$version, $level] = $this->choose($data, $version, $level);
        $segments = Segments::optimal($data, $version);

        $layout = new Layout($version);
        $layout->place($this->codewords($segments, $version, $level), $level);

        $mask ??= $layout->bestMask();
        $layout->mask($mask);
        $layout->formatInformation(Specs::symbolNumber($version, $level), $mask);

        return [
            'matrix' => $layout->toBooleans(),
            'version' => $version,
            'level' => $level,
            'modes' => array_map(static fn (array $segment): Mode => $segment[0], $segments),
            'mask' => $mask,
        ];
    }

    /** Whether any Micro QR symbol holds this payload at this version and level. */
    public static function fits(string $data, ?int $version = null, ?ErrorCorrectionLevel $level = null): bool
    {
        if ($data === '') {
            return false;
        }

        foreach ($version !== null ? [$version] : Specs::versions() as $candidate) {
            $bits = Segments::bits($data, $candidate);

            foreach (self::levelsFor($candidate, $level) as $candidateLevel) {
                if ($bits <= Specs::dataBits($candidate, $candidateLevel)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The levels worth trying at a version, strongest first.
     *
     * Strongest first is the policy, not an accident: with the level left open
     * a payload that fits M4-Q is given M4-Q rather than M4-L, because the
     * capacity is there either way and the recovery is free. Pinning a level
     * narrows this to one.
     *
     * @return list<ErrorCorrectionLevel|null>
     */
    private static function levelsFor(int $version, ?ErrorCorrectionLevel $pinned): array
    {
        if ($version === 1) {
            // M1 has no level. A caller who pinned one did not mean M1.
            return $pinned instanceof ErrorCorrectionLevel ? [] : [null];
        }

        if ($pinned instanceof ErrorCorrectionLevel) {
            return Specs::supports($version, $pinned) ? [$pinned] : [];
        }

        return array_reverse(Specs::levels($version));
    }

    /**
     * @return array{0: int, 1: ErrorCorrectionLevel|null}
     */
    private function choose(string $data, ?int $version, ?ErrorCorrectionLevel $level): array
    {
        foreach ($version !== null ? [$version] : Specs::versions() as $candidate) {
            $bits = Segments::bits($data, $candidate);

            foreach (self::levelsFor($candidate, $level) as $candidateLevel) {
                if ($bits <= Specs::dataBits($candidate, $candidateLevel)) {
                    return [$candidate, $candidateLevel];
                }
            }
        }

        throw $this->refuse($data, $version, $level);
    }

    /**
     * Why a payload got no symbol, which is two different failures wearing one
     * shape.
     *
     * A version that cannot be in any mode the payload needs is not a capacity
     * problem and must not be reported as one: no length of "abc" ever fits
     * M2, because M2 has no byte mode, and telling a caller to shorten it
     * would send them somewhere there is nothing to find.
     */
    private function refuse(string $data, ?int $version, ?ErrorCorrectionLevel $level): \Exception
    {
        $versions = $version !== null ? [$version] : Specs::versions();

        $encodable = array_values(array_filter(
            $versions,
            static fn (int $candidate): bool => Segments::bits($data, $candidate) !== \PHP_INT_MAX
                && self::levelsFor($candidate, $level) !== [],
        ));

        if ($encodable === []) {
            return InvalidDataException::incompatibleMode($this->describe($version, $level), $data);
        }

        $largest = max($encodable);
        $levels = self::levelsFor($largest, $level);

        return DataTooLargeException::forSymbolSize(
            Segments::bits($data, $largest),
            Specs::dataBits($largest, end($levels) ?: null),
            $this->describe($largest, $level),
            'bits',
        );
    }

    /** How a version and level are written down: M1, M4-Q, or "Micro QR". */
    private function describe(?int $version, ?ErrorCorrectionLevel $level): string
    {
        if ($version === null) {
            return 'Micro QR';
        }

        if ($version === 1 || !$level instanceof ErrorCorrectionLevel) {
            return 'Micro QR M' . $version;
        }

        return sprintf('Micro QR M%d-%s', $version, ['L', 'M', 'Q', 'H'][$level->value]);
    }

    /**
     * The data codewords followed by the error correction codewords.
     *
     * At M1 and M3 the last data codeword is a nibble. It is a whole codeword
     * to Reed–Solomon — the byte goes into the block like any other — and half
     * a codeword to the matrix, which writes only its top four bits.
     *
     * @param list<array{Mode, string}> $segments
     * @return list<int>
     */
    private function codewords(array $segments, int $version, ?ErrorCorrectionLevel $level): array
    {
        $capacity = Specs::dataBits($version, $level);
        $bits = [];

        foreach ($segments as [$mode, $payload]) {
            $bits = [
                ...$bits,
                ...Segment::pack(Specs::modeValue($mode), Specs::modeBits($version)),
                ...Segment::pack(\strlen($payload), Specs::countBits($version, $mode)),
                ...match ($mode) {
                    Mode::Numeric => Segment::numeric($payload),
                    Mode::Alphanumeric => Segment::alphanumeric($payload),
                    default => Segment::byte($payload),
                },
            ];
        }

        // The terminator is as long as there is room for, and no longer.
        $bits = [...$bits, ...array_fill(0, min(Specs::terminatorBits($version), $capacity - \count($bits)), 0)];

        // Zeroes to the next codeword boundary, then the two pad codewords in
        // turn. A final nibble that is reached by padding is four zeroes
        // rather than half of 0xEC: half a pad codeword is not a pad codeword.
        while (\count($bits) % 8 !== 0 && \count($bits) < $capacity) {
            $bits[] = 0;
        }

        for ($pad = 0; $capacity - \count($bits) >= 8; $pad++) {
            $bits = [...$bits, ...Segment::pack(self::PAD_CODEWORDS[$pad % 2], 8)];
        }

        $bits = [...$bits, ...array_fill(0, $capacity - \count($bits), 0)];

        // A final nibble is left-aligned in its byte, and that is not a detail
        // of this representation: Reed–Solomon sees the byte, so the four bits
        // it does not use have to be the low four. Right-aligning the nibble
        // gives a symbol whose modules are all in the right place and whose
        // error correction is computed over a different message, which no
        // reader can repair and every reader can detect.
        $codewords = [];
        foreach (array_chunk($bits, 8) as $chunk) {
            $codewords[] = (int) bindec(str_pad(implode('', $chunk), 8, '0'));
        }

        $this->reedSolomon ??= ReedSolomon256::forQr();

        return [
            ...$codewords,
            ...$this->reedSolomon->encode($codewords, Specs::errorCorrectionCodewords($version, $level)),
        ];
    }
}
