<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

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
     * Encode using streaming approach - callback provides scanlines one at a time.
     * This reduces memory usage significantly for large images.
     *
     * @param callable(int $y): bool[] $scanlineCallback Function that returns scanline array for given Y
     * @param int $width Image width in pixels
     * @param int $height Image height in pixels
     * @return string Raw PNG binary data
     */
    public function encodeStreaming(callable $scanlineCallback, int $width, int $height): string
    {
        return self::PNG_SIGNATURE
            . $this->buildIhdrChunk($width, $height)
            . $this->buildIdatChunkStreaming($scanlineCallback, $width, $height)
            . $this->buildIendChunk();
    }

    /**
     * Encode pre-filtered scanlines: the caller supplies the raw deflate input,
     * i.e. for every row a PNG filter-type byte followed by ceil(width/8) bytes
     * of 1-bit pixels (MSB first, 0 = black, 1 = white).
     *
     * @param string $rawScanlines height × (1 + ceil(width/8)) bytes
     * @param int $compressionLevel zlib level 0–9 (-1 = zlib default)
     */
    public function encodeScanlines(string $rawScanlines, int $width, int $height, int $compressionLevel = -1): string
    {
        $expected = $height * (1 + intdiv($width + 7, 8));
        if (\strlen($rawScanlines) !== $expected) {
            throw new \InvalidArgumentException(sprintf(
                'Expected %d bytes of scanline data for a %d×%d 1-bit image, got %d',
                $expected,
                $width,
                $height,
                \strlen($rawScanlines)
            ));
        }

        $compressed = gzcompress($rawScanlines, $compressionLevel);
        if ($compressed === false) {
            throw new \RuntimeException('gzcompress() failed while encoding PNG data');
        }

        return self::PNG_SIGNATURE
            . $this->buildIhdrChunk($width, $height)
            . $this->buildChunk('IDAT', $compressed)
            . $this->buildIendChunk();
    }

    /**
     * Build IDAT chunk using streaming scanlines.
     */
    private function buildIdatChunkStreaming(callable $scanlineCallback, int $width, int $height): string
    {
        $bytesPerScanline = (int) ceil($width / 8);
        $rawData = [];

        for ($y = 0; $y < $height; $y++) {
            $row = $scanlineCallback($y);
            $scanline = "\x00"; // filter type: None

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
                $scanline .= chr($byte);
            }
            $rawData[] = $scanline;
        }

        $compressed = gzcompress(implode('', $rawData));

        return $this->buildChunk('IDAT', $compressed);
    }

    /**
     * IDAT chunk: compressed pixel data (legacy method for encode()).
     */
    private function buildIdatChunk(array $bitmap, int $width, int $height): string
    {
        $bytesPerScanline = (int) ceil($width / 8);
        $rawData = [];

        for ($y = 0; $y < $height; $y++) {
            $row = $bitmap[$y];
            $scanline = "\x00"; // filter type: None

            for ($byteIndex = 0; $byteIndex < $bytesPerScanline; $byteIndex++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $byteIndex * 8 + $bit;
                    if ($x < $width) {
                        if (!$row[$x]) {
                            $byte |= (0x80 >> $bit);
                        }
                    } else {
                        $byte |= (0x80 >> $bit);
                    }
                }
                $scanline .= chr($byte);
            }
            $rawData[] = $scanline;
        }

        $compressed = gzcompress(implode('', $rawData));

        return $this->buildChunk('IDAT', $compressed);
    }

    /**
     * Pack a string of '0'/'1' characters (length = 8 × $bytes) into bytes,
     * MSB first. GMP parses binary text ~2× faster than the bindec() fallback,
     * which works on 56-bit chunks to stay within a PHP int; both produce
     * identical bytes. Pass $useGmp explicitly only in tests.
     */
    public static function packBits(string $bits, int $bytes, ?bool $useGmp = null): string
    {
        if ($useGmp ?? \function_exists('gmp_init')) {
            return str_pad(gmp_export(gmp_init($bits, 2)), $bytes, "\0", STR_PAD_LEFT);
        }

        $out = '';
        for ($i = 0, $n = \strlen($bits); $i < $n; $i += 56) {
            $chunk = substr($bits, $i, 56);
            $out .= substr(pack('J', (int) bindec($chunk)), 8 - intdiv(\strlen($chunk), 8));
        }

        return $out;
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
