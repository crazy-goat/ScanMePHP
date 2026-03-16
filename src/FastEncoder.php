<?php

declare(strict_types=1);

namespace ScanMePHP;

use ScanMePHP\Exception\DataTooLargeException;
use ScanMePHP\Exception\InvalidDataException;

/**
 * High-performance monolithic QR encoder for URLs (Byte mode, v1-v11).
 *
 * Requires 64-bit PHP (PHP_INT_SIZE === 8). Trades readability for raw speed:
 * all encoding, Reed-Solomon, matrix building, mask selection, and data placement
 * are inlined with zero internal method calls in the hot path.
 *
 * Works entirely in int-packed representation (one 64-bit int per row/column).
 * Only creates a Matrix object at the very end for renderer compatibility.
 *
 * Falls back to the standard Encoder for URLs exceeding v11 capacity (321 chars at Medium ECL).
 */
class FastEncoder implements EncoderInterface
{
    // Byte-mode capacity: [version-1][ecl] = max URL length
    private const BYTE_CAPACITY = [
        [ 17,  14,  11,   7], // v1
        [ 32,  26,  20,  14], // v2
        [ 53,  42,  32,  24], // v3
        [ 78,  62,  46,  34], // v4
        [106,  84,  60,  44], // v5
        [134, 106,  74,  58], // v6
        [154, 122,  86,  64], // v7
        [192, 152, 108,  84], // v8
        [230, 180, 130,  98], // v9
        [271, 213, 151, 119], // v10
        [321, 251, 177, 137], // v11
    ];

    private const ECC_COUNT = [
        [  7,  10,  13,  17], // v1
        [ 10,  16,  22,  28], // v2
        [ 15,  26,  36,  44], // v3
        [ 20,  36,  52,  64], // v4
        [ 26,  48,  72,  88], // v5
        [ 36,  64,  96, 112], // v6
        [ 40,  72, 108, 130], // v7
        [ 48,  88, 132, 156], // v8
        [ 60, 110, 160, 192], // v9
        [ 72, 130, 192, 224], // v10
        [ 80, 150, 224, 264], // v11
    ];

    private const TOTAL_CODEWORDS = [0, 26, 44, 70, 100, 134, 172, 196, 242, 292, 346, 404];

    private const ALIGNMENT_POSITIONS = [
        [], [],
        [6, 18], [6, 22], [6, 26], [6, 30], [6, 34],
        [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50], [6, 30, 54],
    ];

    /** @var int[] Galois field exp table (512 entries) */
    private static array $exp = [];

    /** @var int[] Galois field log table (256 entries) */
    private static array $log = [];

    /** @var int[] Byte popcount LUT (256 entries) */
    private static array $pop = [];

    /**
     * Per-version cached data, keyed by version.
     * @var array<int, array{
     *   baseRows: int[],
     *   baseCols: int[],
     *   zigX: int[],
     *   zigY: int[],
     *   zigRowBit: int[],
     *   zigColBit: int[],
     *   maskRows: array<int, int[]>,
     *   maskCols: array<int, int[]>,
     * }>
     */
    private static array $versionCache = [];

    /**
     * Per-(version, ecl) cached format info as full int rows/cols per mask.
     * @var array<string, array{fmtRows: array<int, int[]>, fmtCols: array<int, int[]>}>
     */
    private static array $formatCache = [];

    /**
     * Per-(eccCount) cached RS factor tables.
     * @var array<int, array<int, int[]>>
     */
    private static array $rsCache = [];

    private ?Encoder $fallback = null;

    public function encode(
        string $url,
        ErrorCorrectionLevel $errorCorrectionLevel,
    ): Matrix {
        $dataLen = strlen($url);
        if ($dataLen === 0) {
            throw InvalidDataException::emptyData();
        }

        // Determine version
        $eclVal = $errorCorrectionLevel->value;
        $version = 0;
        for ($v = 1; $v <= 11; $v++) {
            if ($dataLen <= self::BYTE_CAPACITY[$v - 1][$eclVal]) {
                $version = $v;
                break;
            }
        }

        // Fall back to standard encoder for URLs too long for v11
        if ($version === 0) {
            $this->fallback ??= new Encoder();
            return $this->fallback->encode($url, $errorCorrectionLevel);
        }

        // === Initialize static tables on first use ===
        if (self::$exp === []) {
            self::initTables();
        }

        $size = 17 + ($version << 2);
        $sizeM1 = $size - 1;
        $eccCount = self::ECC_COUNT[$version - 1][$eclVal];
        $totalCodewords = self::TOTAL_CODEWORDS[$version];
        $dataCodewords = $totalCodewords - $eccCount;

        // === Ensure version cache ===
        if (!isset(self::$versionCache[$version])) {
            self::buildVersionCache($version, $size);
        }
        $vc = self::$versionCache[$version];

        // === Ensure format info cache ===
        $fmtKey = $version . ':' . $eclVal;
        if (!isset(self::$formatCache[$fmtKey])) {
            self::buildFormatCache($version, $errorCorrectionLevel, $size);
        }
        $fc = self::$formatCache[$fmtKey];

        // === Ensure RS factor table cache ===
        if (!isset(self::$rsCache[$eccCount])) {
            self::buildRsCache($eccCount);
        }
        $factorTable = self::$rsCache[$eccCount];

        // =====================================================================
        // HOT PATH — everything below is inlined, zero method calls
        // =====================================================================

        $pop = self::$pop;

        // === 1. Byte-mode encode: URL bytes → codeword array ===
        // Mode indicator (0100 = Byte) + character count + data bytes + terminator + padding
        // All packed directly into byte array (no intermediate bit array)

        $charCountBits = $version <= 9 ? 8 : 16;
        $overheadBits = 4 + $charCountBits; // mode indicator + char count

        // Build the bit stream as bytes directly
        // First: mode indicator (0100) + char count + data bytes
        $codewords = [];

        if ($charCountBits === 8) {
            // v1-v9: 4-bit mode + 8-bit count = 12 bits overhead
            // First byte: 0100 LLLL (mode + upper 4 bits of length)
            // Second byte: remaining data starts
            // Actually: 0100 CCCC CCCC DDDD DDDD DDDD ...
            // 4 bits mode + 8 bits count = 12 bits, then data bytes
            // Byte 0: 0100 cccc  (mode + top 4 of count)
            // Byte 1: cccc dddd  (bottom 4 of count + top 4 of data[0])
            // This is messy with bit packing. Let me use a simpler approach:
            // Build as bits, then pack to bytes.

            // Actually for speed, let's just do the bit math inline:
            // Total bits = 4 + 8 + dataLen*8 = 12 + dataLen*8
            // Byte 0: bits 0-7 = 0100 LLLL  where L = top 4 bits of dataLen (dataLen < 256)
            // Wait, mode=0100, count is 8 bits for v1-9
            // Stream: 0100 CCCCCCCC D0D0D0D0D0D0D0D0 D1D1D1D1D1D1D1D1 ...
            // Byte 0: 0100 CCCC  (4 mode bits + top 4 of 8-bit count)
            // Byte 1: CCCC D0D0  (bottom 4 of count + top 4 of data[0])
            // Byte 2: D0D0 D1D1  (bottom 4 of data[0] + top 4 of data[1])
            // ... this is a 4-bit shift of all data bytes

            // First codeword: 0100_LLLL where LLLL = (dataLen >> 4) & 0xF
            // But mode is 0100 = 0x4, so first byte = (0x4 << 4) | (($dataLen >> 4) & 0xF)
            $codewords[0] = 0x40 | (($dataLen >> 4) & 0x0F);
            // Second codeword: lower 4 of count + upper 4 of first data byte
            $prev4 = ($dataLen & 0x0F) << 4;
            for ($i = 0; $i < $dataLen; $i++) {
                $b = ord($url[$i]);
                $codewords[$i + 1] = $prev4 | (($b >> 4) & 0x0F);
                $prev4 = ($b & 0x0F) << 4;
            }
            // Last partial byte (lower 4 bits of last data byte + 0000 terminator start)
            $codewords[$dataLen + 1] = $prev4; // terminator bits fill the rest

            $usedCodewords = $dataLen + 2;
        } else {
            // v10-v11: 4-bit mode + 16-bit count = 20 bits overhead
            // Stream: 0100 CCCCCCCCCCCCCCCC D0D0D0D0D0D0D0D0 ...
            // Byte 0: 0100 CCCC  (mode + top 4 of 16-bit count)
            // Byte 1: CCCCCCCC  (middle 8 of count)
            // Byte 2: CCCC D0D0  (bottom 4 of count + top 4 of data[0])
            // ... then same 4-bit shift pattern

            $codewords[0] = 0x40 | (($dataLen >> 12) & 0x0F);
            $codewords[1] = ($dataLen >> 4) & 0xFF;
            $prev4 = ($dataLen & 0x0F) << 4;
            for ($i = 0; $i < $dataLen; $i++) {
                $b = ord($url[$i]);
                $codewords[$i + 2] = $prev4 | (($b >> 4) & 0x0F);
                $prev4 = ($b & 0x0F) << 4;
            }
            $codewords[$dataLen + 2] = $prev4;

            $usedCodewords = $dataLen + 3;
        }

        // Pad to dataCodewords with alternating 0xEC, 0x11
        $padByte = 0xEC;
        for ($i = $usedCodewords; $i < $dataCodewords; $i++) {
            $codewords[$i] = $padByte;
            $padByte = $padByte === 0xEC ? 0x11 : 0xEC;
        }

        // === 2. Reed-Solomon ECC (inlined with factor table) ===
        $ecc = array_fill(0, $eccCount, 0);
        for ($i = 0; $i < $dataCodewords; $i++) {
            $factor = $codewords[$i] ^ array_shift($ecc);
            $ecc[] = 0;
            if ($factor !== 0) {
                $ft = $factorTable[$factor];
                for ($j = 0; $j < $eccCount; $j++) {
                    $ecc[$j] ^= $ft[$j];
                }
            }
        }

        // === 3. Place data into int-packed rows/cols ===
        // Start with base matrix (function patterns) as int rows/cols
        $rows = $vc['baseRows'];
        $cols = $vc['baseCols'];

        // Merge data + ecc into one codeword stream
        $allCount = $dataCodewords + $eccCount;
        for ($i = 0; $i < $eccCount; $i++) {
            $codewords[$dataCodewords + $i] = $ecc[$i];
        }

        // Place data bits using pre-computed zigzag positions
        $zigX = $vc['zigX'];
        $zigY = $vc['zigY'];
        $zigRowBit = $vc['zigRowBit'];
        $zigColBit = $vc['zigColBit'];
        $zigCount = count($zigX);

        $bitIndex = 0;
        for ($p = 0; $p < $zigCount; $p++) {
            $byteIndex = $bitIndex >> 3;
            if ($byteIndex < $allCount && (($codewords[$byteIndex] >> (7 - ($bitIndex & 7))) & 1)) {
                $rows[$zigY[$p]] |= $zigRowBit[$p];
                $cols[$zigX[$p]] |= $zigColBit[$p];
            }
            $bitIndex++;
        }

        // === 4. Select best mask (all 8 masks, bitwise penalty rules) ===
        $maskRows = $vc['maskRows'];
        $maskCols = $vc['maskCols'];
        $fmtRows = $fc['fmtRows'];
        $fmtCols = $fc['fmtCols'];

        $sizeMask = (1 << $size) - 1;
        $sizeM1Mask = (1 << $sizeM1) - 1;
        $runMask = $sizeM1Mask;
        $sizeM10 = $size - 10;
        $r3ValidMask = (1 << $sizeM10) - 1;
        $totalModules = $size * $size;

        $bestMask = 0;
        $bestScore = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $xorR = $maskRows[$mask];
            $xorC = $maskCols[$mask];
            $fmtR = $fmtRows[$mask];
            $fmtC = $fmtCols[$mask];

            // Apply mask XOR + format info delta
            $mr = $rows;
            $mc = $cols;
            for ($i = 0; $i < $size; $i++) {
                $mr[$i] ^= $xorR[$i] ^ $fmtR[$i];
                $mc[$i] ^= $xorC[$i] ^ $fmtC[$i];
            }

            $penalty = 0;
            $darkCount = 0;

            // Rule 1 horizontal + dark count (bitwise cascade)
            for ($y = 0; $y < $size; $y++) {
                $row = $mr[$y];
                $darkCount += $pop[$row & 0xff] + $pop[($row >> 8) & 0xff]
                    + $pop[($row >> 16) & 0xff] + $pop[($row >> 24) & 0xff]
                    + $pop[($row >> 32) & 0xff] + $pop[($row >> 40) & 0xff]
                    + $pop[($row >> 48) & 0xff] + $pop[($row >> 56) & 0xff];
                $inv = (~($row ^ ($row >> 1))) & $runMask;
                $r4 = $inv & ($inv >> 1) & ($inv >> 2) & ($inv >> 3);
                if ($r4 !== 0) {
                    $penalty += $pop[$r4 & 0xff] + $pop[($r4 >> 8) & 0xff]
                        + $pop[($r4 >> 16) & 0xff] + $pop[($r4 >> 24) & 0xff]
                        + $pop[($r4 >> 32) & 0xff] + $pop[($r4 >> 40) & 0xff]
                        + $pop[($r4 >> 48) & 0xff] + $pop[($r4 >> 56) & 0xff];
                    $starts = $r4 & ~($r4 << 1);
                    $penalty += 2 * ($pop[$starts & 0xff] + $pop[($starts >> 8) & 0xff]
                        + $pop[($starts >> 16) & 0xff] + $pop[($starts >> 24) & 0xff]
                        + $pop[($starts >> 32) & 0xff] + $pop[($starts >> 40) & 0xff]
                        + $pop[($starts >> 48) & 0xff] + $pop[($starts >> 56) & 0xff]);
                }
            }

            if ($penalty >= $bestScore) {
                continue;
            }

            // Rule 1 vertical (bitwise cascade on columns)
            for ($x = 0; $x < $size; $x++) {
                $col = $mc[$x];
                $inv = (~($col ^ ($col >> 1))) & $runMask;
                $r4 = $inv & ($inv >> 1) & ($inv >> 2) & ($inv >> 3);
                if ($r4 !== 0) {
                    $penalty += $pop[$r4 & 0xff] + $pop[($r4 >> 8) & 0xff]
                        + $pop[($r4 >> 16) & 0xff] + $pop[($r4 >> 24) & 0xff]
                        + $pop[($r4 >> 32) & 0xff] + $pop[($r4 >> 40) & 0xff]
                        + $pop[($r4 >> 48) & 0xff] + $pop[($r4 >> 56) & 0xff];
                    $starts = $r4 & ~($r4 << 1);
                    $penalty += 2 * ($pop[$starts & 0xff] + $pop[($starts >> 8) & 0xff]
                        + $pop[($starts >> 16) & 0xff] + $pop[($starts >> 24) & 0xff]
                        + $pop[($starts >> 32) & 0xff] + $pop[($starts >> 40) & 0xff]
                        + $pop[($starts >> 48) & 0xff] + $pop[($starts >> 56) & 0xff]);
                }
            }

            if ($penalty >= $bestScore) {
                continue;
            }

            // Rule 2: 2×2 blocks (LUT popcount)
            for ($y = 0; $y < $sizeM1; $y++) {
                $same = ~($mr[$y] ^ $mr[$y + 1]) & $sizeMask;
                $hSame = ~($mr[$y] ^ ($mr[$y] >> 1)) & $sizeM1Mask;
                $blocks = ($same & ($same >> 1)) & $hSame & $sizeM1Mask;
                if ($blocks !== 0) {
                    $penalty += 3 * ($pop[$blocks & 0xff] + $pop[($blocks >> 8) & 0xff]
                        + $pop[($blocks >> 16) & 0xff] + $pop[($blocks >> 24) & 0xff]
                        + $pop[($blocks >> 32) & 0xff] + $pop[($blocks >> 40) & 0xff]
                        + $pop[($blocks >> 48) & 0xff] + $pop[($blocks >> 56) & 0xff]);
                }
            }

            if ($penalty >= $bestScore) {
                continue;
            }

            // Rule 3 horizontal: bitwise parallel finder pattern matching
            for ($y = 0; $y < $size; $y++) {
                $row = $mr[$y];
                $r1 = $row >> 1; $r2 = $row >> 2; $r3 = $row >> 3; $r4p = $row >> 4;
                $r5 = $row >> 5; $r6 = $row >> 6; $r7 = $row >> 7; $r8 = $row >> 8;
                $r9 = $row >> 9; $r10 = $row >> 10;
                $m1 = $r10 & ~$r9 & $r8 & $r7 & $r6 & ~$r5 & $r4p & ~$r3 & ~$r2 & ~$r1 & ~$row;
                $m2 = ~$r10 & ~$r9 & ~$r8 & ~$r7 & $r6 & ~$r5 & $r4p & $r3 & $r2 & ~$r1 & $row;
                $matches = ($m1 | $m2) & $r3ValidMask;
                if ($matches !== 0) {
                    $penalty += 40 * ($pop[$matches & 0xff] + $pop[($matches >> 8) & 0xff]
                        + $pop[($matches >> 16) & 0xff] + $pop[($matches >> 24) & 0xff]
                        + $pop[($matches >> 32) & 0xff] + $pop[($matches >> 40) & 0xff]
                        + $pop[($matches >> 48) & 0xff] + $pop[($matches >> 56) & 0xff]);
                }
            }

            if ($penalty >= $bestScore) {
                continue;
            }

            // Rule 3 vertical: bitwise parallel
            for ($x = 0; $x < $size; $x++) {
                $col = $mc[$x];
                $r1 = $col >> 1; $r2 = $col >> 2; $r3 = $col >> 3; $r4p = $col >> 4;
                $r5 = $col >> 5; $r6 = $col >> 6; $r7 = $col >> 7; $r8 = $col >> 8;
                $r9 = $col >> 9; $r10 = $col >> 10;
                $m1 = $r10 & ~$r9 & $r8 & $r7 & $r6 & ~$r5 & $r4p & ~$r3 & ~$r2 & ~$r1 & ~$col;
                $m2 = ~$r10 & ~$r9 & ~$r8 & ~$r7 & $r6 & ~$r5 & $r4p & $r3 & $r2 & ~$r1 & $col;
                $matches = ($m1 | $m2) & $r3ValidMask;
                if ($matches !== 0) {
                    $penalty += 40 * ($pop[$matches & 0xff] + $pop[($matches >> 8) & 0xff]
                        + $pop[($matches >> 16) & 0xff] + $pop[($matches >> 24) & 0xff]
                        + $pop[($matches >> 32) & 0xff] + $pop[($matches >> 40) & 0xff]
                        + $pop[($matches >> 48) & 0xff] + $pop[($matches >> 56) & 0xff]);
                }
            }

            // Rule 4: Dark/light balance
            $percentage = ($darkCount * 100) / $totalModules;
            $deviation = abs($percentage - 50);
            $penalty += ((int)($deviation / 5)) * 50;

            if ($penalty < $bestScore) {
                $bestScore = $penalty;
                $bestMask = $mask;
            }
        }

        // === 5. Apply best mask to get final int rows ===
        $finalXorR = $maskRows[$bestMask];
        $finalFmtR = $fmtRows[$bestMask];
        for ($i = 0; $i < $size; $i++) {
            $rows[$i] ^= $finalXorR[$i] ^ $finalFmtR[$i];
        }

        // === 6. Convert int rows → flat bool[] → Matrix ===
        $flat = array_fill(0, $totalModules, false);
        for ($y = 0; $y < $size; $y++) {
            $row = $rows[$y];
            if ($row === 0) {
                continue;
            }
            $rowOffset = $y * $size;
            for ($x = 0; $x < $size; $x++) {
                if (($row >> ($sizeM1 - $x)) & 1) {
                    $flat[$rowOffset + $x] = true;
                }
            }
        }

        $matrix = new Matrix($version);
        $matrix->setRawData($flat);
        return $matrix;
    }

    // =========================================================================
    // Static table initialization (runs once, cached forever)
    // =========================================================================

    private static function initTables(): void
    {
        // Galois field GF(256) with primitive polynomial 0x11d
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d;
            }
        }
        self::$exp[255] = self::$exp[0];
        for ($i = 256; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }

        // Popcount LUT
        for ($i = 0; $i < 256; $i++) {
            $c = 0;
            $v = $i;
            while ($v) {
                $c++;
                $v &= ($v - 1);
            }
            self::$pop[$i] = $c;
        }
    }

    /**
     * Build and cache all version-specific data:
     * - Base matrix as int rows/cols (function patterns with mask=0 format info)
     * - Zigzag traversal positions as flat arrays
     * - Mask XOR patterns as int rows/cols
     */
    private static function buildVersionCache(int $version, int $size): void
    {
        $sizeM1 = $size - 1;
        $totalModules = $size * $size;

        // === Build reserved bitmap ===
        $reserved = array_fill(0, $totalModules, false);

        // Finder patterns + separators (9×9 regions)
        for ($y = 0; $y < 9; $y++) {
            for ($x = 0; $x < 9; $x++) {
                $reserved[$y * $size + $x] = true;
            }
            for ($x = $size - 8; $x < $size; $x++) {
                $reserved[$y * $size + $x] = true;
            }
        }
        for ($y = $size - 8; $y < $size; $y++) {
            for ($x = 0; $x < 9; $x++) {
                $reserved[$y * $size + $x] = true;
            }
        }

        // Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $reserved[6 * $size + $i] = true;
            $reserved[$i * $size + 6] = true;
        }

        // Dark module
        $reserved[(4 * $version + 9) * $size + 8] = true;

        // Format info
        for ($i = 0; $i < 9; $i++) {
            $reserved[8 * $size + $i] = true;
            $reserved[$i * $size + 8] = true;
        }
        for ($i = $size - 8; $i < $size; $i++) {
            $reserved[8 * $size + $i] = true;
            $reserved[$i * $size + 8] = true;
        }

        // Version info (v >= 7)
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = $size - 11; $j < $size - 8; $j++) {
                    $reserved[$j * $size + $i] = true;
                    $reserved[$i * $size + $j] = true;
                }
            }
        }

        // Alignment patterns
        if ($version >= 2) {
            $positions = self::ALIGNMENT_POSITIONS[$version];
            $sizeM8 = $size - 8;
            foreach ($positions as $cy) {
                foreach ($positions as $cx) {
                    if ($cx <= 8 && $cy <= 8) continue;
                    if ($cx >= $sizeM8 && $cy <= 8) continue;
                    if ($cx <= 8 && $cy >= $sizeM8) continue;
                    for ($dy = -2; $dy <= 2; $dy++) {
                        $rowOffset = ($cy + $dy) * $size;
                        for ($dx = -2; $dx <= 2; $dx++) {
                            $reserved[$rowOffset + $cx + $dx] = true;
                        }
                    }
                }
            }
        }

        // === Build base matrix as flat bool[] ===
        $data = array_fill(0, $totalModules, false);

        // Finder patterns (7×7)
        $fp = [0b1111111, 0b1000001, 0b1011101, 0b1011101, 0b1011101, 0b1000001, 0b1111111];
        for ($y = 0; $y < 7; $y++) {
            $bits = $fp[$y];
            for ($x = 0; $x < 7; $x++) {
                $val = (bool)(($bits >> (6 - $x)) & 1);
                $data[$y * $size + $x] = $val;
                $data[$y * $size + $size - 7 + $x] = $val;
                $data[($size - 7 + $y) * $size + $x] = $val;
            }
        }

        // Timing patterns
        for ($i = 8; $i < $size - 8; $i++) {
            $val = ($i & 1) === 0;
            $data[6 * $size + $i] = $val;
            $data[$i * $size + 6] = $val;
        }

        // Dark module
        $data[(4 * $version + 9) * $size + 8] = true;

        // Alignment patterns (5×5)
        if ($version >= 2) {
            $ap = [0b11111, 0b10001, 0b10101, 0b10001, 0b11111];
            $positions = self::ALIGNMENT_POSITIONS[$version];
            $sizeM8 = $size - 8;
            foreach ($positions as $cy) {
                foreach ($positions as $cx) {
                    if ($cx <= 8 && $cy <= 8) continue;
                    if ($cx >= $sizeM8 && $cy <= 8) continue;
                    if ($cx <= 8 && $cy >= $sizeM8) continue;
                    for ($dy = -2; $dy <= 2; $dy++) {
                        $bits = $ap[$dy + 2];
                        $py = $cy + $dy;
                        for ($dx = -2; $dx <= 2; $dx++) {
                            $data[$py * $size + $cx + $dx] = (bool)(($bits >> (2 - $dx)) & 1);
                        }
                    }
                }
            }
        }

        // Format info is NOT placed in the base matrix — it's ECL-dependent.
        // Full format info per mask is stored in the format cache instead.

        // Version info (v >= 7)
        if ($version >= 7) {
            $versionBits = self::computeVersionBits($version);
            for ($i = 0; $i < 18; $i++) {
                $bit = (bool)(($versionBits >> $i) & 1);
                $row = (int)($i / 3);
                $col = $i % 3;
                $data[$row * $size + $size - 11 + $col] = $bit;
                $data[($size - 11 + $col) * $size + $row] = $bit;
            }
        }

        // === Pack base matrix into int rows and cols ===
        $baseRows = array_fill(0, $size, 0);
        $baseCols = array_fill(0, $size, 0);
        for ($y = 0; $y < $size; $y++) {
            $val = 0;
            $rowOffset = $y * $size;
            for ($x = 0; $x < $size; $x++) {
                if ($data[$rowOffset + $x]) {
                    $val |= (1 << ($sizeM1 - $x));
                }
            }
            $baseRows[$y] = $val;
        }
        for ($x = 0; $x < $size; $x++) {
            $val = 0;
            for ($y = 0; $y < $size; $y++) {
                if ($data[$y * $size + $x]) {
                    $val |= (1 << ($sizeM1 - $y));
                }
            }
            $baseCols[$x] = $val;
        }

        // === Compute zigzag traversal positions ===
        $zigX = [];
        $zigY = [];
        $zigRowBit = [];
        $zigColBit = [];

        for ($col = $size - 1; $col > 0; $col -= 2) {
            if ($col === 6) {
                $col--;
            }
            $up = ((($sizeM1 - $col) >> 1) & 1) === 0;
            for ($row = $up ? $sizeM1 : 0; $up ? $row >= 0 : $row < $size; $up ? $row-- : $row++) {
                for ($c = 0; $c < 2; $c++) {
                    $x = $col - $c;
                    if (!$reserved[$row * $size + $x]) {
                        $zigX[] = $x;
                        $zigY[] = $row;
                        $zigRowBit[] = 1 << ($sizeM1 - $x);
                        $zigColBit[] = 1 << ($sizeM1 - $row);
                    }
                }
            }
        }

        // === Compute mask XOR patterns ===
        $allMaskRows = array_fill(0, 8, array_fill(0, $size, 0));
        $allMaskCols = array_fill(0, 8, array_fill(0, $size, 0));

        for ($y = 0; $y < $size; $y++) {
            $rowOffset = $y * $size;
            $yEven = ($y & 1) === 0;
            $yHalf = $y >> 1;
            $rowBit = 1 << ($sizeM1 - $y);

            for ($x = 0; $x < $size; $x++) {
                if ($reserved[$rowOffset + $x]) {
                    continue;
                }

                $xy = $x * $y;
                $sum = $x + $y;
                $xyMod3 = $xy % 3;
                $xyBit = $xy & 1;
                $sumBit = $sum & 1;
                $colBit = 1 << ($sizeM1 - $x);

                if ($sumBit === 0) {
                    $allMaskRows[0][$y] |= $colBit;
                    $allMaskCols[0][$x] |= $rowBit;
                }
                if ($yEven) {
                    $allMaskRows[1][$y] |= $colBit;
                    $allMaskCols[1][$x] |= $rowBit;
                }
                if ($x % 3 === 0) {
                    $allMaskRows[2][$y] |= $colBit;
                    $allMaskCols[2][$x] |= $rowBit;
                }
                if ($sum % 3 === 0) {
                    $allMaskRows[3][$y] |= $colBit;
                    $allMaskCols[3][$x] |= $rowBit;
                }
                if ((($yHalf + (int)($x / 3)) & 1) === 0) {
                    $allMaskRows[4][$y] |= $colBit;
                    $allMaskCols[4][$x] |= $rowBit;
                }
                if ($xyBit + $xyMod3 === 0) {
                    $allMaskRows[5][$y] |= $colBit;
                    $allMaskCols[5][$x] |= $rowBit;
                }
                if ((($xyBit + $xyMod3) & 1) === 0) {
                    $allMaskRows[6][$y] |= $colBit;
                    $allMaskCols[6][$x] |= $rowBit;
                }
                if ((($sumBit + $xyMod3) & 1) === 0) {
                    $allMaskRows[7][$y] |= $colBit;
                    $allMaskCols[7][$x] |= $rowBit;
                }
            }
        }

        self::$versionCache[$version] = [
            'baseRows' => $baseRows,
            'baseCols' => $baseCols,
            'zigX' => $zigX,
            'zigY' => $zigY,
            'zigRowBit' => $zigRowBit,
            'zigColBit' => $zigColBit,
            'maskRows' => $allMaskRows,
            'maskCols' => $allMaskCols,
        ];
    }

    /**
     * Build and cache full format info as int rows/cols for each mask.
     * Base matrix has NO format info, so these contain the complete format pattern.
     */
    private static function buildFormatCache(int $version, ErrorCorrectionLevel $ecl, int $size): void
    {
        $eclVal = $ecl->value;
        $fmtKey = $version . ':' . $eclVal;
        $sizeM1 = $size - 1;

        $eccBits = match ($ecl) {
            ErrorCorrectionLevel::Low => 0b01,
            ErrorCorrectionLevel::Medium => 0b00,
            ErrorCorrectionLevel::Quartile => 0b11,
            ErrorCorrectionLevel::High => 0b10,
        };

        // Format info positions: [x, y, bit_index]
        $positions = [
            [8, 0, 0], [8, 1, 1], [8, 2, 2], [8, 3, 3],
            [8, 4, 4], [8, 5, 5], [8, 7, 6], [8, 8, 7],
            [7, 8, 8], [5, 8, 9], [4, 8, 10], [3, 8, 11],
            [2, 8, 12], [1, 8, 13], [0, 8, 14],
        ];
        for ($i = 0; $i < 8; $i++) {
            $positions[] = [$size - 1 - $i, 8, $i];
        }
        for ($i = 8; $i < 15; $i++) {
            $positions[] = [8, $size - 15 + $i, $i];
        }

        $allFmtRows = [];
        $allFmtCols = [];

        for ($mask = 0; $mask < 8; $mask++) {
            $fR = array_fill(0, $size, 0);
            $fC = array_fill(0, $size, 0);

            $maskBits = self::computeFormatBitsFromEcc($eccBits, $mask);

            foreach ($positions as [$x, $y, $bit]) {
                if (($maskBits >> $bit) & 1) {
                    $fR[$y] |= (1 << ($sizeM1 - $x));
                    $fC[$x] |= (1 << ($sizeM1 - $y));
                }
            }

            $allFmtRows[$mask] = $fR;
            $allFmtCols[$mask] = $fC;
        }

        self::$formatCache[$fmtKey] = [
            'fmtRows' => $allFmtRows,
            'fmtCols' => $allFmtCols,
        ];
    }

    /**
     * Build and cache RS transposed factor table for a given ECC count.
     */
    private static function buildRsCache(int $eccCount): void
    {
        $exp = self::$exp;
        $log = self::$log;

        // Build generator polynomial
        $poly = [1];
        for ($i = 0; $i < $eccCount; $i++) {
            $polyLen = count($poly);
            $newPoly = array_fill(0, $polyLen + 1, 0);
            $alphaI = $exp[$i];
            for ($j = 0; $j < $polyLen; $j++) {
                $newPoly[$j] ^= $poly[$j];
                $p = $poly[$j];
                if ($p !== 0 && $alphaI !== 0) {
                    $newPoly[$j + 1] ^= $exp[$log[$p] + $log[$alphaI]];
                }
            }
            $poly = $newPoly;
        }

        // Pre-compute log of generator coefficients
        $genLog = [];
        for ($i = 0; $i < $eccCount; $i++) {
            $genLog[$i] = $log[$poly[$i + 1]];
        }

        // Build transposed factor table: factorTable[factor][i]
        $factorTable = [];
        for ($f = 1; $f < 256; $f++) {
            $lf = $log[$f];
            $row = [];
            for ($i = 0; $i < $eccCount; $i++) {
                $row[$i] = $exp[$genLog[$i] + $lf];
            }
            $factorTable[$f] = $row;
        }

        self::$rsCache[$eccCount] = $factorTable;
    }

    // =========================================================================
    // Helper methods (used only during cache building, not in hot path)
    // =========================================================================

    private static function computeFormatBits(int $eclValue, int $maskPattern): int
    {
        $eccBits = match ($eclValue) {
            0 => 0b01, // Low
            1 => 0b00, // Medium
            2 => 0b11, // Quartile
            3 => 0b10, // High
            default => 0b00,
        };
        return self::computeFormatBitsFromEcc($eccBits, $maskPattern);
    }

    private static function computeFormatBitsFromEcc(int $eccBits, int $maskPattern): int
    {
        $data = ($eccBits << 3) | $maskPattern;
        $format = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($format >> $i) & 1) {
                $format ^= 0x537 << ($i - 10);
            }
        }
        return (($data << 10) | $format) ^ 0x5412;
    }

    private static function computeVersionBits(int $version): int
    {
        $data = $version;
        $versionInfo = $data << 12;
        for ($i = 17; $i >= 12; $i--) {
            if (($versionInfo >> $i) & 1) {
                $versionInfo ^= 0x1f25 << ($i - 12);
            }
        }
        return ($data << 12) | $versionInfo;
    }

    private static function placeFormatBitsOnData(array &$data, int $formatBits, int $size): void
    {
        $data[0 * $size + 8] = (bool)(($formatBits >> 0) & 1);
        $data[1 * $size + 8] = (bool)(($formatBits >> 1) & 1);
        $data[2 * $size + 8] = (bool)(($formatBits >> 2) & 1);
        $data[3 * $size + 8] = (bool)(($formatBits >> 3) & 1);
        $data[4 * $size + 8] = (bool)(($formatBits >> 4) & 1);
        $data[5 * $size + 8] = (bool)(($formatBits >> 5) & 1);
        $data[7 * $size + 8] = (bool)(($formatBits >> 6) & 1);
        $data[8 * $size + 8] = (bool)(($formatBits >> 7) & 1);
        $data[8 * $size + 7] = (bool)(($formatBits >> 8) & 1);
        $data[8 * $size + 5] = (bool)(($formatBits >> 9) & 1);
        $data[8 * $size + 4] = (bool)(($formatBits >> 10) & 1);
        $data[8 * $size + 3] = (bool)(($formatBits >> 11) & 1);
        $data[8 * $size + 2] = (bool)(($formatBits >> 12) & 1);
        $data[8 * $size + 1] = (bool)(($formatBits >> 13) & 1);
        $data[8 * $size + 0] = (bool)(($formatBits >> 14) & 1);

        for ($i = 0; $i < 8; $i++) {
            $data[8 * $size + $size - 1 - $i] = (bool)(($formatBits >> $i) & 1);
        }
        for ($i = 8; $i < 15; $i++) {
            $data[($size - 15 + $i) * $size + 8] = (bool)(($formatBits >> $i) & 1);
        }
    }
}
