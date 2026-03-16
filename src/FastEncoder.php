<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

use ScanMePHP\Exception\DataTooLargeException;
use ScanMePHP\Exception\InvalidDataException;

/**
 * High-performance monolithic QR encoder for URLs (Byte mode, v1-v27).
 *
 * Requires 64-bit PHP (PHP_INT_SIZE === 8). Trades readability for raw speed:
 * all encoding, Reed-Solomon, matrix building, mask selection, and data placement
 * are inlined with zero internal method calls in the hot path.
 *
 * Uses int-pair representation: each row/column is stored as two 64-bit ints
 * [$hi, $lo] giving 128 usable bits. This covers QR sizes up to 125 modules
 * (v1-v27). Bit layout: hi holds bits [size-1 .. 64], lo holds bits [63 .. 0].
 * For v1-v11 (size ≤ 61), hi is always 0.
 *
 * Falls back to the standard Encoder for URLs exceeding v27 capacity.
 */
class FastEncoder implements EncoderInterface
{
    private const MAX_VERSION = 27;

    // Byte-mode capacity: [version-1][ecl] = max URL length
    private const BYTE_CAPACITY = [
        [  17,   14,   11,    7], // v1
        [  32,   26,   20,   14], // v2
        [  53,   42,   32,   24], // v3
        [  78,   62,   46,   34], // v4
        [ 106,   84,   60,   44], // v5
        [ 134,  106,   74,   58], // v6
        [ 154,  122,   86,   64], // v7
        [ 192,  152,  108,   84], // v8
        [ 230,  180,  130,   98], // v9
        [ 271,  213,  151,  119], // v10
        [ 321,  251,  177,  137], // v11
        [ 367,  287,  203,  155], // v12
        [ 425,  331,  241,  177], // v13
        [ 458,  362,  258,  194], // v14
        [ 520,  412,  292,  220], // v15
        [ 586,  450,  322,  250], // v16
        [ 644,  504,  364,  280], // v17
        [ 718,  560,  394,  310], // v18
        [ 792,  624,  442,  338], // v19
        [ 858,  666,  482,  382], // v20
        [ 929,  711,  509,  403], // v21
        [1003,  779,  565,  439], // v22
        [1091,  857,  611,  461], // v23
        [1171,  911,  661,  511], // v24
        [1273,  997,  715,  535], // v25
        [1367, 1059,  751,  593], // v26
        [1465, 1125,  805,  625], // v27
    ];

    private const ECC_COUNT = [
        [   7,   10,   13,   17], // v1
        [  10,   16,   22,   28], // v2
        [  15,   26,   36,   44], // v3
        [  20,   36,   52,   64], // v4
        [  26,   48,   72,   88], // v5
        [  36,   64,   96,  112], // v6
        [  40,   72,  108,  130], // v7
        [  48,   88,  132,  156], // v8
        [  60,  110,  160,  192], // v9
        [  72,  130,  192,  224], // v10
        [  80,  150,  224,  264], // v11
        [  96,  176,  260,  308], // v12
        [ 104,  198,  288,  352], // v13
        [ 120,  216,  320,  384], // v14
        [ 132,  240,  360,  432], // v15
        [ 144,  280,  408,  480], // v16
        [ 168,  308,  448,  532], // v17
        [ 180,  338,  504,  588], // v18
        [ 196,  364,  546,  650], // v19
        [ 224,  416,  600,  700], // v20
        [ 224,  442,  644,  750], // v21
        [ 252,  476,  690,  816], // v22
        [ 270,  504,  750,  900], // v23
        [ 300,  560,  810,  960], // v24
        [ 312,  588,  870, 1050], // v25
        [ 336,  644,  952, 1110], // v26
        [ 360,  700, 1020, 1200], // v27
    ];

    private const NUM_BLOCKS = [
        [ 1,  1,  1,  1], // v1
        [ 1,  1,  1,  1], // v2
        [ 1,  1,  2,  2], // v3
        [ 1,  2,  2,  4], // v4
        [ 1,  2,  4,  4], // v5
        [ 2,  4,  4,  4], // v6
        [ 2,  4,  6,  5], // v7
        [ 2,  4,  6,  6], // v8
        [ 2,  5,  8,  8], // v9
        [ 4,  5,  8,  8], // v10
        [ 4,  5,  8, 11], // v11
        [ 4,  8, 10, 11], // v12
        [ 4,  9, 12, 16], // v13
        [ 4,  9, 16, 16], // v14
        [ 6, 10, 12, 18], // v15
        [ 6, 10, 17, 16], // v16
        [ 6, 11, 16, 19], // v17
        [ 6, 13, 18, 21], // v18
        [ 7, 14, 21, 25], // v19
        [ 8, 16, 20, 25], // v20
        [ 8, 17, 23, 25], // v21
        [ 9, 17, 23, 34], // v22
        [ 9, 18, 25, 30], // v23
        [10, 20, 27, 32], // v24
        [12, 21, 29, 35], // v25
        [12, 23, 34, 37], // v26
        [12, 25, 34, 40], // v27
    ];

    private const ECC_PER_BLOCK = [
        [ 7, 10, 13, 17], // v1
        [10, 16, 22, 28], // v2
        [15, 26, 18, 22], // v3
        [20, 18, 26, 16], // v4
        [26, 24, 18, 22], // v5
        [18, 16, 24, 28], // v6
        [20, 18, 18, 26], // v7
        [24, 22, 22, 26], // v8
        [30, 22, 20, 24], // v9
        [18, 26, 24, 28], // v10
        [20, 30, 28, 24], // v11
        [24, 22, 26, 28], // v12
        [26, 22, 24, 22], // v13
        [30, 24, 20, 24], // v14
        [22, 24, 30, 24], // v15
        [24, 28, 24, 30], // v16
        [28, 28, 28, 28], // v17
        [30, 26, 28, 28], // v18
        [28, 26, 26, 26], // v19
        [28, 26, 30, 28], // v20
        [28, 26, 28, 30], // v21
        [28, 28, 30, 24], // v22
        [30, 28, 30, 30], // v23
        [30, 28, 30, 30], // v24
        [26, 28, 30, 30], // v25
        [28, 28, 28, 30], // v26
        [30, 28, 30, 30], // v27
    ];

    private const TOTAL_CODEWORDS = [
        0,
        26, 44, 70, 100, 134, 172, 196, 242, 292, 346,
        404, 466, 532, 581, 655, 733, 815, 901, 991, 1085,
        1156, 1258, 1364, 1474, 1588, 1706, 1828,
    ];

    private const ALIGNMENT_POSITIONS = [
        [], [],
        [6, 18], [6, 22], [6, 26], [6, 30], [6, 34],
        [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50], [6, 30, 54],
        [6, 32, 58], [6, 34, 62],
        [6, 26, 46, 66], [6, 26, 48, 70], [6, 26, 50, 74],
        [6, 30, 54, 78], [6, 30, 56, 82], [6, 30, 58, 86], [6, 34, 62, 90],
        [6, 28, 50, 72, 94], [6, 26, 50, 74, 98], [6, 30, 54, 78, 102],
        [6, 28, 54, 80, 106], [6, 32, 58, 84, 110], [6, 30, 58, 86, 114],
        [6, 34, 62, 90, 118],
    ];

    /** @var int[] Galois field exp table (512 entries) */
    private static array $exp = [];

    /** @var int[] Galois field log table (256 entries) */
    private static array $log = [];

    /** @var int[] Byte popcount LUT (256 entries) */
    private static array $pop = [];

    /**
     * Per-version cached data. All row/col arrays use int-pair layout:
     * baseHi/baseLo are parallel int[] arrays (one pair per row/col).
     * @var array<int, array>
     */
    private static array $versionCache = [];

    /** @var array<string, array> */
    private static array $formatCache = [];

    /** @var array<int, array<int, int[]>> */
    private static array $rsCache = [];

    private ?Encoder $fallback = null;

    public function __construct()
    {
        if (\PHP_INT_SIZE < 8) {
            throw new \RuntimeException(
                'FastEncoder requires 64-bit PHP (PHP_INT_SIZE >= 8). '
                . 'Current PHP_INT_SIZE is ' . \PHP_INT_SIZE . '.'
            );
        }
    }

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
        for ($v = 1; $v <= self::MAX_VERSION; $v++) {
            if ($dataLen <= self::BYTE_CAPACITY[$v - 1][$eclVal]) {
                $version = $v;
                break;
            }
        }

        // Fall back to standard encoder for URLs too long for v27
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

        $numBlocks = self::NUM_BLOCKS[$version - 1][$eclVal];
        $blockEccLen = self::ECC_PER_BLOCK[$version - 1][$eclVal];

        // === Ensure RS factor table cache ===
        if (!isset(self::$rsCache[$blockEccLen])) {
            self::buildRsCache($blockEccLen);
        }
        $factorTable = self::$rsCache[$blockEccLen];

        // =====================================================================
        // HOT PATH — everything below is inlined, zero method calls
        // =====================================================================

        $pop = self::$pop;

        // === 1. Byte-mode encode: URL bytes → codeword array ===
        $charCountBits = $version <= 9 ? 8 : 16;
        $codewords = [];

        if ($charCountBits === 8) {
            $codewords[0] = 0x40 | (($dataLen >> 4) & 0x0F);
            $prev4 = ($dataLen & 0x0F) << 4;
            for ($i = 0; $i < $dataLen; $i++) {
                $b = ord($url[$i]);
                $codewords[$i + 1] = $prev4 | (($b >> 4) & 0x0F);
                $prev4 = ($b & 0x0F) << 4;
            }
            $codewords[$dataLen + 1] = $prev4;
            $usedCodewords = $dataLen + 2;
        } else {
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

        $padByte = 0xEC;
        for ($i = $usedCodewords; $i < $dataCodewords; $i++) {
            $codewords[$i] = $padByte;
            $padByte = $padByte === 0xEC ? 0x11 : 0xEC;
        }

        // === 2. Reed-Solomon ECC with multi-block interleaving ===
        $numShortBlocks = $numBlocks - $totalCodewords % $numBlocks;
        $shortBlockLen = intdiv($totalCodewords, $numBlocks);
        $shortDataLen = $shortBlockLen - $blockEccLen;

        $blockData = [];
        $blockEcc = [];
        $k = 0;
        for ($b = 0; $b < $numBlocks; $b++) {
            $dLen = $b < $numShortBlocks ? $shortDataLen : $shortDataLen + 1;
            $blockData[$b] = array_slice($codewords, $k, $dLen);
            $k += $dLen;

            $ecc = array_fill(0, $blockEccLen, 0);
            for ($i = 0; $i < $dLen; $i++) {
                $factor = $blockData[$b][$i] ^ array_shift($ecc);
                $ecc[] = 0;
                if ($factor !== 0) {
                    $ft = $factorTable[$factor];
                    for ($j = 0; $j < $blockEccLen; $j++) {
                        $ecc[$j] ^= $ft[$j];
                    }
                }
            }
            $blockEcc[$b] = $ecc;
        }

        $longDataLen = $shortDataLen + 1;
        $allCount = $totalCodewords;
        $codewords = [];
        $idx = 0;
        for ($col = 0; $col < $longDataLen; $col++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $dLen = $b < $numShortBlocks ? $shortDataLen : $longDataLen;
                if ($col < $dLen) {
                    $codewords[$idx++] = $blockData[$b][$col];
                }
            }
        }
        for ($col = 0; $col < $blockEccLen; $col++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $codewords[$idx++] = $blockEcc[$b][$col];
            }
        }

        // === 3. Place data into int-pair rows/cols ===
        $rowsHi = $vc['baseRowsHi'];
        $rowsLo = $vc['baseRowsLo'];
        $colsHi = $vc['baseColsHi'];
        $colsLo = $vc['baseColsLo'];

        // Place data bits using pre-computed zigzag positions
        $zigX = $vc['zigX'];
        $zigY = $vc['zigY'];
        $zigRowBitHi = $vc['zigRowBitHi'];
        $zigRowBitLo = $vc['zigRowBitLo'];
        $zigColBitHi = $vc['zigColBitHi'];
        $zigColBitLo = $vc['zigColBitLo'];
        $zigCount = count($zigX);

        $bitIndex = 0;
        for ($p = 0; $p < $zigCount; $p++) {
            $byteIndex = $bitIndex >> 3;
            if ($byteIndex < $allCount && (($codewords[$byteIndex] >> (7 - ($bitIndex & 7))) & 1)) {
                $y = $zigY[$p];
                $x = $zigX[$p];
                $rowsHi[$y] |= $zigRowBitHi[$p];
                $rowsLo[$y] |= $zigRowBitLo[$p];
                $colsHi[$x] |= $zigColBitHi[$p];
                $colsLo[$x] |= $zigColBitLo[$p];
            }
            $bitIndex++;
        }

        // === 4. Select best mask (all 8 masks, bitwise penalty rules on int pairs) ===
        $maskRowsHi = $vc['maskRowsHi'];
        $maskRowsLo = $vc['maskRowsLo'];
        $maskColsHi = $vc['maskColsHi'];
        $maskColsLo = $vc['maskColsLo'];
        $fmtRowsHi = $fc['fmtRowsHi'];
        $fmtRowsLo = $fc['fmtRowsLo'];
        $fmtColsHi = $fc['fmtColsHi'];
        $fmtColsLo = $fc['fmtColsLo'];

        $totalModules = $size * $size;

        // Pre-compute masks for the hi part (bits above 63)
        // For size <= 64, hiBits = 0 and all hi masks are 0
        $hiBits = $size > 64 ? $size - 64 : 0;

        $bestMask = 0;
        $bestScore = \PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $xrHi = $maskRowsHi[$mask]; $xrLo = $maskRowsLo[$mask];
            $xcHi = $maskColsHi[$mask]; $xcLo = $maskColsLo[$mask];
            $frHi = $fmtRowsHi[$mask]; $frLo = $fmtRowsLo[$mask];
            $fcHi = $fmtColsHi[$mask]; $fcLo = $fmtColsLo[$mask];

            // Apply mask XOR + format info
            $mrHi = []; $mrLo = []; $mcHi = []; $mcLo = [];
            for ($i = 0; $i < $size; $i++) {
                $mrHi[$i] = $rowsHi[$i] ^ $xrHi[$i] ^ $frHi[$i];
                $mrLo[$i] = $rowsLo[$i] ^ $xrLo[$i] ^ $frLo[$i];
                $mcHi[$i] = $colsHi[$i] ^ $xcHi[$i] ^ $fcHi[$i];
                $mcLo[$i] = $colsLo[$i] ^ $xcLo[$i] ^ $fcLo[$i];
            }

            $penalty = 0;
            $darkCount = 0;

            $getModule = static function (int $x, int $y) use (&$mrHi, &$mrLo): bool {
                return $x < 64
                    ? (bool)(($mrLo[$y] >> $x) & 1)
                    : (bool)(($mrHi[$y] >> ($x - 64)) & 1);
            };

            $addHistory = static function (int $runLen, array &$hist) use ($size): void {
                if ($hist[0] === 0) {
                    $runLen += $size;
                }
                for ($i = 6; $i >= 1; $i--) {
                    $hist[$i] = $hist[$i - 1];
                }
                $hist[0] = $runLen;
            };

            $countPatterns = static function (array &$hist): int {
                $n = $hist[1];
                if ($n <= 0) {
                    return 0;
                }
                $core = $hist[2] === $n && $hist[3] === $n * 3 && $hist[4] === $n && $hist[5] === $n;
                return ($core && $hist[0] >= $n * 4 && $hist[6] >= $n ? 1 : 0)
                     + ($core && $hist[6] >= $n * 4 && $hist[0] >= $n ? 1 : 0);
            };

            $terminateAndCount = static function (bool $curColor, int $curLen, array &$hist) use ($size, $addHistory, $countPatterns): int {
                if ($curColor) {
                    $addHistory($curLen, $hist);
                    $curLen = 0;
                }
                $curLen += $size;
                $addHistory($curLen, $hist);
                return $countPatterns($hist);
            };

            for ($y = 0; $y < $size; $y++) {
                $lo = $mrLo[$y];
                $hi = $mrHi[$y];
                $darkCount += $pop[$lo & 0xff] + $pop[($lo >> 8) & 0xff]
                    + $pop[($lo >> 16) & 0xff] + $pop[($lo >> 24) & 0xff]
                    + $pop[($lo >> 32) & 0xff] + $pop[($lo >> 40) & 0xff]
                    + $pop[($lo >> 48) & 0xff] + $pop[($lo >> 56) & 0xff]
                    + $pop[$hi & 0xff] + $pop[($hi >> 8) & 0xff]
                    + $pop[($hi >> 16) & 0xff] + $pop[($hi >> 24) & 0xff]
                    + $pop[($hi >> 32) & 0xff] + $pop[($hi >> 40) & 0xff]
                    + $pop[($hi >> 48) & 0xff] + $pop[($hi >> 56) & 0xff];

                $runColor = false;
                $runX = 0;
                $runHistory = [0, 0, 0, 0, 0, 0, 0];
                for ($x = 0; $x < $size; $x++) {
                    if ($getModule($x, $y) === $runColor) {
                        $runX++;
                        if ($runX === 5) {
                            $penalty += 3;
                        } elseif ($runX > 5) {
                            $penalty++;
                        }
                    } else {
                        $addHistory($runX, $runHistory);
                        if (!$runColor) {
                            $penalty += $countPatterns($runHistory) * 40;
                        }
                        $runColor = $getModule($x, $y);
                        $runX = 1;
                    }
                }
                $penalty += $terminateAndCount($runColor, $runX, $runHistory) * 40;
            }

            for ($x = 0; $x < $size; $x++) {
                $runColor = false;
                $runY = 0;
                $runHistory = [0, 0, 0, 0, 0, 0, 0];
                for ($y = 0; $y < $size; $y++) {
                    if ($getModule($x, $y) === $runColor) {
                        $runY++;
                        if ($runY === 5) {
                            $penalty += 3;
                        } elseif ($runY > 5) {
                            $penalty++;
                        }
                    } else {
                        $addHistory($runY, $runHistory);
                        if (!$runColor) {
                            $penalty += $countPatterns($runHistory) * 40;
                        }
                        $runColor = $getModule($x, $y);
                        $runY = 1;
                    }
                }
                $penalty += $terminateAndCount($runColor, $runY, $runHistory) * 40;
            }

            for ($y = 0; $y < $sizeM1; $y++) {
                for ($x = 0; $x < $sizeM1; $x++) {
                    $color = $getModule($x, $y);
                    if ($color === $getModule($x + 1, $y)
                        && $color === $getModule($x, $y + 1)
                        && $color === $getModule($x + 1, $y + 1)) {
                        $penalty += 3;
                    }
                }
            }

            $total = $size * $size;
            $k = (int)((\abs($darkCount * 20 - $total * 10) + $total - 1) / $total) - 1;
            $penalty += $k * 10;

            if ($penalty < $bestScore) {
                $bestScore = $penalty;
                $bestMask = $mask;
            }
        }

        if (getenv('SCANME_DEBUG_PENALTIES')) {
            fwrite(STDERR, "PHP best mask: $bestMask (penalty=$bestScore)\n");
        }

        // === 5. Apply best mask to get final rows ===
        $fxrHi = $maskRowsHi[$bestMask]; $fxrLo = $maskRowsLo[$bestMask];
        $ffrHi = $fmtRowsHi[$bestMask]; $ffrLo = $fmtRowsLo[$bestMask];
        for ($i = 0; $i < $size; $i++) {
            $rowsHi[$i] ^= $fxrHi[$i] ^ $ffrHi[$i];
            $rowsLo[$i] ^= $fxrLo[$i] ^ $ffrLo[$i];
        }

        // === 6. Convert int-pair rows → flat bool[] → Matrix ===
        $flat = array_fill(0, $totalModules, false);
        for ($y = 0; $y < $size; $y++) {
            $hi = $rowsHi[$y];
            $lo = $rowsLo[$y];
            if ($hi === 0 && $lo === 0) {
                continue;
            }
            $rowOffset = $y * $size;
            // Extract bits from hi part (bits [size-1 .. 64])
            if ($hi !== 0) {
                for ($x = 0; $x < $hiBits; $x++) {
                    if (($hi >> ($hiBits - 1 - $x)) & 1) {
                        $flat[$rowOffset + $x] = true;
                    }
                }
            }
            // Extract bits from lo part (bits [sizeM1-hiBits .. 0])
            if ($lo !== 0) {
                for ($x = 0; $x < 64 && ($hiBits + $x) < $size; $x++) {
                    if (($lo >> ($sizeM1 - $hiBits - $x)) & 1) {
                        $flat[$rowOffset + $hiBits + $x] = true;
                    }
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
     * Build and cache all version-specific data using int-pair representation.
     */
    private static function buildVersionCache(int $version, int $size): void
    {
        $sizeM1 = $size - 1;
        $totalModules = $size * $size;
        $hiBits = $size > 64 ? $size - 64 : 0;

        // === Build reserved bitmap ===
        $reserved = array_fill(0, $totalModules, false);

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

        for ($i = 8; $i < $size - 8; $i++) {
            $reserved[6 * $size + $i] = true;
            $reserved[$i * $size + 6] = true;
        }

        $reserved[(4 * $version + 9) * $size + 8] = true;

        for ($i = 0; $i < 9; $i++) {
            $reserved[8 * $size + $i] = true;
            $reserved[$i * $size + 8] = true;
        }
        for ($i = $size - 8; $i < $size; $i++) {
            $reserved[8 * $size + $i] = true;
            $reserved[$i * $size + 8] = true;
        }

        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = $size - 11; $j < $size - 8; $j++) {
                    $reserved[$j * $size + $i] = true;
                    $reserved[$i * $size + $j] = true;
                }
            }
        }

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

        for ($i = 8; $i < $size - 8; $i++) {
            $val = ($i & 1) === 0;
            $data[6 * $size + $i] = $val;
            $data[$i * $size + 6] = $val;
        }

        $data[(4 * $version + 9) * $size + 8] = true;

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

        // === Pack base matrix into int-pair rows and cols ===
        $baseRowsHi = array_fill(0, $size, 0);
        $baseRowsLo = array_fill(0, $size, 0);
        $baseColsHi = array_fill(0, $size, 0);
        $baseColsLo = array_fill(0, $size, 0);

        for ($y = 0; $y < $size; $y++) {
            $rowOffset = $y * $size;
            for ($x = 0; $x < $size; $x++) {
                if ($data[$rowOffset + $x]) {
                    $bitPos = $sizeM1 - $x;
                    if ($bitPos >= 64) {
                        $baseRowsHi[$y] |= (1 << ($bitPos - 64));
                    } else {
                        $baseRowsLo[$y] |= (1 << $bitPos);
                    }
                }
            }
        }
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                if ($data[$y * $size + $x]) {
                    $bitPos = $sizeM1 - $y;
                    if ($bitPos >= 64) {
                        $baseColsHi[$x] |= (1 << ($bitPos - 64));
                    } else {
                        $baseColsLo[$x] |= (1 << $bitPos);
                    }
                }
            }
        }

        // === Compute zigzag traversal positions ===
        $zigX = [];
        $zigY = [];
        $zigRowBitHi = [];
        $zigRowBitLo = [];
        $zigColBitHi = [];
        $zigColBitLo = [];

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
                        $bitPosR = $sizeM1 - $x;
                        if ($bitPosR >= 64) {
                            $zigRowBitHi[] = 1 << ($bitPosR - 64);
                            $zigRowBitLo[] = 0;
                        } else {
                            $zigRowBitHi[] = 0;
                            $zigRowBitLo[] = 1 << $bitPosR;
                        }
                        $bitPosC = $sizeM1 - $row;
                        if ($bitPosC >= 64) {
                            $zigColBitHi[] = 1 << ($bitPosC - 64);
                            $zigColBitLo[] = 0;
                        } else {
                            $zigColBitHi[] = 0;
                            $zigColBitLo[] = 1 << $bitPosC;
                        }
                    }
                }
            }
        }

        // === Compute mask XOR patterns ===
        $allMaskRowsHi = array_fill(0, 8, array_fill(0, $size, 0));
        $allMaskRowsLo = array_fill(0, 8, array_fill(0, $size, 0));
        $allMaskColsHi = array_fill(0, 8, array_fill(0, $size, 0));
        $allMaskColsLo = array_fill(0, 8, array_fill(0, $size, 0));

        for ($y = 0; $y < $size; $y++) {
            $rowOffset = $y * $size;
            $yEven = ($y & 1) === 0;
            $yHalf = $y >> 1;
            $bitPosRow = $sizeM1 - $y;
            $rowBitIsHi = $bitPosRow >= 64;

            for ($x = 0; $x < $size; $x++) {
                if ($reserved[$rowOffset + $x]) {
                    continue;
                }

                $xy = $x * $y;
                $sum = $x + $y;
                $xyMod3 = $xy % 3;
                $xyBit = $xy & 1;
                $sumBit = $sum & 1;
                $bitPosCol = $sizeM1 - $x;
                $colBitIsHi = $bitPosCol >= 64;

                $conditions = [
                    $sumBit === 0,
                    $yEven,
                    $x % 3 === 0,
                    $sum % 3 === 0,
                    (($yHalf + (int)($x / 3)) & 1) === 0,
                    $xyBit + $xyMod3 === 0,
                    (($xyBit + $xyMod3) & 1) === 0,
                    (($sumBit + $xyMod3) & 1) === 0,
                ];

                for ($m = 0; $m < 8; $m++) {
                    if ($conditions[$m]) {
                        if ($colBitIsHi) {
                            $allMaskRowsHi[$m][$y] |= (1 << ($bitPosCol - 64));
                        } else {
                            $allMaskRowsLo[$m][$y] |= (1 << $bitPosCol);
                        }
                        if ($rowBitIsHi) {
                            $allMaskColsHi[$m][$x] |= (1 << ($bitPosRow - 64));
                        } else {
                            $allMaskColsLo[$m][$x] |= (1 << $bitPosRow);
                        }
                    }
                }
            }
        }

        self::$versionCache[$version] = [
            'baseRowsHi' => $baseRowsHi, 'baseRowsLo' => $baseRowsLo,
            'baseColsHi' => $baseColsHi, 'baseColsLo' => $baseColsLo,
            'zigX' => $zigX, 'zigY' => $zigY,
            'zigRowBitHi' => $zigRowBitHi, 'zigRowBitLo' => $zigRowBitLo,
            'zigColBitHi' => $zigColBitHi, 'zigColBitLo' => $zigColBitLo,
            'maskRowsHi' => $allMaskRowsHi, 'maskRowsLo' => $allMaskRowsLo,
            'maskColsHi' => $allMaskColsHi, 'maskColsLo' => $allMaskColsLo,
        ];
    }

    /**
     * Build and cache format info as int-pair rows/cols for each mask.
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

        $allFmtRowsHi = []; $allFmtRowsLo = [];
        $allFmtColsHi = []; $allFmtColsLo = [];

        for ($mask = 0; $mask < 8; $mask++) {
            $fRHi = array_fill(0, $size, 0); $fRLo = array_fill(0, $size, 0);
            $fCHi = array_fill(0, $size, 0); $fCLo = array_fill(0, $size, 0);

            $maskBits = self::computeFormatBitsFromEcc($eccBits, $mask);

            foreach ($positions as [$x, $y, $bit]) {
                if (($maskBits >> $bit) & 1) {
                    $bitPosR = $sizeM1 - $x;
                    if ($bitPosR >= 64) {
                        $fRHi[$y] |= (1 << ($bitPosR - 64));
                    } else {
                        $fRLo[$y] |= (1 << $bitPosR);
                    }
                    $bitPosC = $sizeM1 - $y;
                    if ($bitPosC >= 64) {
                        $fCHi[$x] |= (1 << ($bitPosC - 64));
                    } else {
                        $fCLo[$x] |= (1 << $bitPosC);
                    }
                }
            }

            $allFmtRowsHi[$mask] = $fRHi; $allFmtRowsLo[$mask] = $fRLo;
            $allFmtColsHi[$mask] = $fCHi; $allFmtColsLo[$mask] = $fCLo;
        }

        self::$formatCache[$fmtKey] = [
            'fmtRowsHi' => $allFmtRowsHi, 'fmtRowsLo' => $allFmtRowsLo,
            'fmtColsHi' => $allFmtColsHi, 'fmtColsLo' => $allFmtColsLo,
        ];
    }

    /**
     * Build and cache RS transposed factor table for a given ECC count.
     */
    private static function buildRsCache(int $eccCount): void
    {
        $exp = self::$exp;
        $log = self::$log;

        $poly = [1];
        for ($i = 0; $i < $eccCount; $i++) {
            $polyLen = count($poly);
            $newPoly = array_fill(0, $polyLen + 1, 0);
            $alphaI = $exp[$i % 255];
            for ($j = 0; $j < $polyLen; $j++) {
                $newPoly[$j] ^= $poly[$j];
                $p = $poly[$j];
                if ($p !== 0 && $alphaI !== 0) {
                    $newPoly[$j + 1] ^= $exp[$log[$p] + $log[$alphaI]];
                }
            }
            $poly = $newPoly;
        }

        $genLog = [];
        for ($i = 0; $i < $eccCount; $i++) {
            $coeff = $poly[$i + 1];
            $genLog[$i] = $coeff !== 0 ? $log[$coeff] : -1;
        }

        $factorTable = [];
        for ($f = 1; $f < 256; $f++) {
            $lf = $log[$f];
            $row = [];
            for ($i = 0; $i < $eccCount; $i++) {
                $row[$i] = $genLog[$i] !== -1 ? $exp[$genLog[$i] + $lf] : 0;
            }
            $factorTable[$f] = $row;
        }

        self::$rsCache[$eccCount] = $factorTable;
    }

    // =========================================================================
    // Helper methods (cache building only, not in hot path)
    // =========================================================================

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
}
