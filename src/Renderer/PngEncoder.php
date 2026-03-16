<?php

declare(strict_types=1);

namespace ScanMePHP\Renderer;

/**
 * Minimal PNG encoder for 1-bit monochrome images.
 *
 * Builds a valid PNG file from a boolean pixel grid:
 *   PNG Signature (8B) + IHDR (25B) + IDAT (variable) + IEND (12B)
 *
 * Uses color type 0 (grayscale), bit depth 1.
 * Each scanline: filter byte (0x00 = None) + packed pixel bits (MSB first).
 * In 1-bit grayscale: 0 = black, 1 = white.
 */
final class PngEncoder
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Encode a boolean pixel grid into a PNG binary string.
     *
     * @param bool[][] $bitmap 2D array [y][x], true = dark (black), false = light (white)
     * @param int $width Image width in pixels
     * @param int $height Image height in pixels
     * @return string Raw PNG binary data
     */
    public function encode(array $bitmap, int $width, int $height): string
    {
        return self::PNG_SIGNATURE
            . $this->buildIhdrChunk($width, $height)
            . $this->buildIdatChunk($bitmap, $width, $height)
            . $this->buildIendChunk();
    }

    /**
     * IHDR chunk: 13 bytes of image metadata.
     *
     * Layout: width(4B) + height(4B) + bitDepth(1B) + colorType(1B)
     *       + compression(1B) + filter(1B) + interlace(1B)
     */
    private function buildIhdrChunk(int $width, int $height): string
    {
        $data = pack('NN', $width, $height)  // width, height as 4-byte big-endian unsigned
            . "\x01"   // bit depth: 1
            . "\x00"   // color type: 0 (grayscale)
            . "\x00"   // compression method: 0 (deflate)
            . "\x00"   // filter method: 0 (adaptive, only option)
            . "\x00";  // interlace method: 0 (no interlace)

        return $this->buildChunk('IHDR', $data);
    }

    /**
     * IDAT chunk: compressed pixel data.
     *
     * Each scanline is prefixed with a filter byte (0x00 = None),
     * followed by pixel bits packed 8 per byte, MSB first.
     * In 1-bit grayscale: bit 0 = black, bit 1 = white.
     * The last byte of each scanline is padded with 1 bits (white) if width % 8 != 0.
     */
    private function buildIdatChunk(array $bitmap, int $width, int $height): string
    {
        $bytesPerScanline = (int) ceil($width / 8);
        $rawData = '';

        for ($y = 0; $y < $height; $y++) {
            $rawData .= "\x00"; // filter type: None

            $row = $bitmap[$y];
            for ($byteIndex = 0; $byteIndex < $bytesPerScanline; $byteIndex++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $byteIndex * 8 + $bit;
                    if ($x < $width) {
                        // true = dark = black = bit value 0
                        // false = light = white = bit value 1
                        if (!$row[$x]) {
                            $byte |= (0x80 >> $bit);
                        }
                    } else {
                        // Padding bits: set to 1 (white) for clean background
                        $byte |= (0x80 >> $bit);
                    }
                }
                $rawData .= chr($byte);
            }
        }

        $compressed = gzcompress($rawData);

        return $this->buildChunk('IDAT', $compressed);
    }

    /**
     * IEND chunk: marks end of PNG. Empty data.
     */
    private function buildIendChunk(): string
    {
        return $this->buildChunk('IEND', '');
    }

    /**
     * Build a PNG chunk: length(4B) + type(4B) + data + CRC(4B).
     *
     * CRC is calculated over type + data (not length).
     */
    private function buildChunk(string $type, string $data): string
    {
        $length = pack('N', strlen($data));
        $crc = pack('N', crc32($type . $data));

        return $length . $type . $data . $crc;
    }
}
