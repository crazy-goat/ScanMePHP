<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding;

/**
 * @internal Part of the QR encoding pipeline.
 */
class DataEncoder
{
    /**
     * The mode indicator that marks a symbol as GS1, in first position.
     *
     * QR spells FNC1 as a mode rather than as data: the four bits sit ahead of
     * the first real segment and carry no character count and no payload of
     * their own. That is why this is not a case of Mode — a Mode is something
     * a segment is *in*, and nothing is ever encoded in FNC1. The separator
     * between element strings is a plain 0x1d byte inside the segment that
     * follows, exactly as in Code 128.
     *
     * ISO/IEC 18004:2015 Table 2.
     */
    private const FNC1_FIRST_POSITION = 0b0101;

    /** What the indicator costs, which is all a caller sizing a symbol needs. */
    public const GS1_OVERHEAD_BITS = 4;

    public function encode(string $data, Mode $mode, int $version): array
    {
        $bits = [];

        $modeIndicator = $mode->getModeIndicator();
        for ($i = 3; $i >= 0; $i--) {
            $bits[] = ($modeIndicator >> $i) & 1;
        }

        $charCountBits = $mode->getCharacterCountBits($version);
        $charCount = match ($mode) {
            Mode::Kanji => (int) (strlen($data) / 2),
            default => strlen($data),
        };

        for ($i = $charCountBits - 1; $i >= 0; $i--) {
            $bits[] = ($charCount >> $i) & 1;
        }

        $dataBits = match ($mode) {
            Mode::Numeric => $this->encodeNumeric($data),
            Mode::Alphanumeric => $this->encodeAlphanumeric($data),
            Mode::Byte => $this->encodeByte($data),
            Mode::Kanji => $this->encodeKanji($data),
        };

        return array_merge($bits, $dataBits);
    }

    /**
     * The same segment, announced as GS1 by four bits in front of it.
     *
     * @return list<int>
     */
    public function encodeGs1(string $data, Mode $mode, int $version): array
    {
        $bits = [];
        for ($i = 3; $i >= 0; $i--) {
            $bits[] = (self::FNC1_FIRST_POSITION >> $i) & 1;
        }

        return array_merge($bits, $this->encode($data, $mode, $version));
    }

    /** @return list<int> */
    private function encodeNumeric(string $data): array
    {
        return Segment::numeric($data);
    }

    /** @return list<int> */
    private function encodeAlphanumeric(string $data): array
    {
        return Segment::alphanumeric($data);
    }

    /** @return list<int> */
    private function encodeByte(string $data): array
    {
        return Segment::byte($data);
    }

    private function encodeKanji(string $data): array
    {
        $bits = [];

        // Simplified Kanji encoding - assumes Shift-JIS
        for ($i = 0; $i < strlen($data); $i += 2) {
            if ($i + 1 >= strlen($data)) {
                break;
            }

            $byte1 = ord($data[$i]);
            $byte2 = ord($data[$i + 1]);
            $value = ($byte1 << 8) | $byte2;

            // Convert to 13-bit value
            if ($value >= 0x8140 && $value <= 0x9ffc) {
                $value -= 0x8140;
            } elseif ($value >= 0xe040 && $value <= 0xebbf) {
                $value -= 0xc140;
            } else {
                continue; // Skip invalid Kanji
            }

            $value = (($value >> 8) * 0xc0) + ($value & 0xff);

            for ($j = 12; $j >= 0; $j--) {
                $bits[] = ($value >> $j) & 1;
            }
        }

        return $bits;
    }

    private function bitsToBytes(array $bits): array
    {
        $bytes = [];
        $count = count($bits);

        for ($i = 0; $i < $count; $i += 8) {
            $byte = 0;
            $bitsInThisByte = 0;
            for ($j = 0; $j < 8 && $i + $j < $count; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
                $bitsInThisByte++;
            }
            if ($bitsInThisByte < 8) {
                $byte <<= (8 - $bitsInThisByte);
            }
            $bytes[] = $byte;
        }

        return $bytes;
    }

    public function addTerminatorAndPadding(array $bits, int $totalCapacity): array
    {
        $totalBits = $totalCapacity * 8;

        $terminatorLength = min(4, $totalBits - count($bits));
        for ($i = 0; $i < $terminatorLength; $i++) {
            $bits[] = 0;
        }

        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $padBytes = [0xec, 0x11];
        $padIndex = 0;
        while (count($bits) < $totalBits) {
            $byte = $padBytes[$padIndex % 2];
            for ($i = 7; $i >= 0; $i--) {
                $bits[] = ($byte >> $i) & 1;
            }
            $padIndex++;
        }

        return $this->bitsToBytes($bits);
    }
}
