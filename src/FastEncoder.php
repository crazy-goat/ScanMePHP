<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

use CrazyGoat\ScanMePHP\Exception\InvalidDataException;

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
    public const MAX_VERSION = 27;

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

    private const TOTAL_CODEWORDS = [
        0,
        26, 44, 70, 100, 134, 172, 196, 242, 292, 346,
        404, 466, 532, 581, 655, 733, 815, 901, 991, 1085,
        1156, 1258, 1364, 1474, 1588, 1706, 1828,
    ];

    // [version-1][ecl] = [g1_blocks, g1_data, ecc_per_block, g2_blocks, g2_data]
    private const EC_BLOCKS = [
        [[1,19,7,0,0],[1,16,10,0,0],[1,13,13,0,0],[1,9,17,0,0]],         // v1
        [[1,34,10,0,0],[1,28,16,0,0],[1,22,22,0,0],[1,16,28,0,0]],       // v2
        [[1,55,15,0,0],[1,44,26,0,0],[2,17,18,0,0],[2,13,22,0,0]],       // v3
        [[1,80,20,0,0],[2,32,18,0,0],[2,24,26,0,0],[4,9,16,0,0]],        // v4
        [[1,108,26,0,0],[2,43,24,0,0],[2,15,18,2,16],[2,11,22,2,12]],    // v5
        [[2,68,18,0,0],[4,27,16,0,0],[4,19,24,0,0],[4,15,28,0,0]],       // v6
        [[2,78,20,0,0],[4,31,18,0,0],[2,14,18,4,15],[4,13,26,1,14]],     // v7
        [[2,97,24,0,0],[2,38,22,2,39],[4,18,22,2,19],[4,14,26,2,15]],    // v8
        [[2,116,30,0,0],[3,36,22,2,37],[4,16,20,4,17],[4,12,24,4,13]],   // v9
        [[2,68,18,2,69],[4,43,26,1,44],[6,19,24,2,20],[6,15,28,2,16]],   // v10
        [[4,81,20,0,0],[1,50,30,4,51],[4,22,28,4,23],[3,12,24,8,13]],    // v11
        [[2,92,24,2,93],[6,36,22,2,37],[4,20,26,6,21],[7,14,28,4,15]],   // v12
        [[4,107,26,0,0],[8,37,22,1,38],[8,20,24,4,21],[12,11,22,4,12]],  // v13
        [[3,115,30,1,116],[4,40,24,5,41],[11,16,20,5,17],[11,12,24,5,13]], // v14
        [[5,87,22,1,88],[5,41,24,5,42],[5,24,30,7,25],[11,12,24,7,13]],  // v15
        [[5,98,24,1,99],[7,45,28,3,46],[15,19,24,2,20],[3,15,30,13,16]], // v16
        [[1,107,28,5,108],[10,46,28,1,47],[1,22,28,15,23],[2,14,28,17,15]], // v17
        [[5,120,30,1,121],[9,43,26,4,44],[17,22,28,1,23],[2,14,28,19,15]], // v18
        [[3,113,28,4,114],[3,44,26,11,45],[17,21,26,4,22],[9,13,26,16,14]], // v19
        [[3,107,28,5,108],[3,41,26,13,42],[15,24,30,5,25],[15,15,28,10,16]], // v20
        [[4,116,28,4,117],[17,42,26,0,0],[17,22,28,6,23],[19,16,30,6,17]], // v21
        [[2,111,28,7,112],[17,46,28,0,0],[7,24,30,16,25],[34,13,24,0,0]], // v22
        [[4,121,30,5,122],[4,47,28,14,48],[11,24,30,14,25],[16,15,30,14,16]], // v23
        [[6,117,30,4,118],[6,45,28,14,46],[11,24,30,16,25],[30,16,30,2,17]], // v24
        [[8,106,26,4,107],[8,47,28,13,48],[7,24,30,22,25],[22,15,30,13,16]], // v25
        [[10,114,28,2,115],[19,46,28,4,47],[28,22,28,6,23],[33,16,30,4,17]], // v26
        [[8,122,30,4,123],[22,45,28,3,46],[8,23,30,26,24],[12,15,30,28,16]], // v27
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

    /** @var string[] byte value -> 8 module bytes ("\0"/"\1", MSB first) */
    private static array $moduleBytes = [];

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

        return $this->encodeVersion($url, $errorCorrectionLevel, $version);
    }

    /**
     * Encodes $url as a Byte-mode symbol of exactly $version (1..27). The data
     * must fit; Encoder validates that before delegating here.
     *
     * @internal Used by Encoder for its fast path; prefer encode().
     */
    public function encodeVersion(
        string $url,
        ErrorCorrectionLevel $errorCorrectionLevel,
        int $version,
    ): Matrix {
        $dataLen = strlen($url);
        $eclVal = $errorCorrectionLevel->value;
        if ($dataLen === 0) {
            throw InvalidDataException::emptyData();
        }
        if ($version < 1 || $version > self::MAX_VERSION || $dataLen > self::BYTE_CAPACITY[$version - 1][$eclVal]) {
            throw new \InvalidArgumentException(
                "FastEncoder::encodeVersion(): {$dataLen} bytes do not fit in version {$version}"
                . " at ECL {$errorCorrectionLevel->name} (or version is outside 1.." . self::MAX_VERSION . ')'
            );
        }

        // === Initialize static tables on first use ===
        if (self::$exp === []) {
            $this->initTables();
        }

        $size = 17 + ($version << 2);
        $eccCount = self::ECC_COUNT[$version - 1][$eclVal];
        $totalCodewords = self::TOTAL_CODEWORDS[$version];
        $dataCodewords = $totalCodewords - $eccCount;

        // === Ensure version cache ===
        if (!isset(self::$versionCache[$version])) {
            $this->buildVersionCache($version, $size);
        }
        $vc = self::$versionCache[$version];

        // === Ensure format info cache ===
        $fmtKey = $version . ':' . $eclVal;
        if (!isset(self::$formatCache[$fmtKey])) {
            $this->buildFormatCache($version, $errorCorrectionLevel, $size);
        }
        $fc = self::$formatCache[$fmtKey];

        // === Ensure RS factor table cache (per-block ECC count) ===
        $ecBlock = self::EC_BLOCKS[$version - 1][$eclVal];
        $eccPerBlock = $ecBlock[2];
        if (!isset(self::$rsCache[$eccPerBlock])) {
            $this->buildRsCache($eccPerBlock);
        }
        $factorTable = self::$rsCache[$eccPerBlock];

        // =====================================================================
        // HOT PATH — everything below is inlined, zero method calls
        // =====================================================================

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

        // Pad to dataCodewords
        $padByte = 0xEC;
        for ($i = $usedCodewords; $i < $dataCodewords; $i++) {
            $codewords[$i] = $padByte;
            $padByte = $padByte === 0xEC ? 0x11 : 0xEC;
        }

        // === 2. Reed-Solomon ECC with multi-block interleaving ===
        // The ECC register of each block is 4 packed 64-bit words (byte j of
        // the register = coefficient j, little-endian): consuming a data byte
        // shifts the register down one byte and XORs the factor row, which is
        // 4 word XORs instead of a per-coefficient loop.
        $g1Blocks = $ecBlock[0];
        $g1Data   = $ecBlock[1];
        $g2Blocks = $ecBlock[3];
        $g2Data   = $ecBlock[4];
        $numBlocks = $g1Blocks + $g2Blocks;

        $blockOffset = [];
        $blockLen = [];
        $blockEcc = [];
        $k = 0;
        for ($b = 0; $b < $numBlocks; $b++) {
            $dlen = ($b < $g1Blocks) ? $g1Data : $g2Data;
            $blockOffset[$b] = $k;
            $blockLen[$b] = $dlen;
            $w0 = 0;
            $w1 = 0;
            $w2 = 0;
            $w3 = 0;
            for ($i = $k, $end = $k + $dlen; $i < $end; $i++) {
                $factor = $codewords[$i] ^ ($w0 & 0xFF);
                $w0 = (($w0 >> 8) & 0x00FFFFFFFFFFFFFF) | ($w1 << 56);
                $w1 = (($w1 >> 8) & 0x00FFFFFFFFFFFFFF) | ($w2 << 56);
                $w2 = (($w2 >> 8) & 0x00FFFFFFFFFFFFFF) | ($w3 << 56);
                $w3 = ($w3 >> 8) & 0x00FFFFFFFFFFFFFF;
                if ($factor !== 0) {
                    $ft = $factorTable[$factor];
                    $w0 ^= $ft[0];
                    $w1 ^= $ft[1];
                    $w2 ^= $ft[2];
                    $w3 ^= $ft[3];
                }
            }
            $blockEcc[$b] = [$w0, $w1, $w2, $w3];
            $k += $dlen;
        }

        $maxDataLen = ($g2Blocks > 0) ? $g2Data : $g1Data;
        $interleaved = [];
        for ($col = 0; $col < $maxDataLen; $col++) {
            for ($b = 0; $b < $numBlocks; $b++) {
                if ($col < $blockLen[$b]) {
                    $interleaved[] = $codewords[$blockOffset[$b] + $col];
                }
            }
        }
        for ($col = 0; $col < $eccPerBlock; $col++) {
            $word = $col >> 3;
            $shift = ($col & 7) << 3;
            for ($b = 0; $b < $numBlocks; $b++) {
                $interleaved[] = ($blockEcc[$b][$word] >> $shift) & 0xFF;
            }
        }
        $codewords = $interleaved;

        // === 3. Place data into int-pair rows/cols ===
        // Zigzag position p carries bit p of the codeword stream (MSB first),
        // so codeword byte i owns positions 8i..8i+7. Zero bytes are skipped;
        // remainder bits after the last codeword are light.
        $rowsHi = $vc['baseRowsHi'];
        $rowsLo = $vc['baseRowsLo'];
        $colsHi = $vc['baseColsHi'];
        $colsLo = $vc['baseColsLo'];

        $allCount = count($codewords);
        $zigX = $vc['zigX'];
        $zigY = $vc['zigY'];
        $zigRowBitLo = $vc['zigRowBitLo'];
        $zigColBitLo = $vc['zigColBitLo'];

        if ($size <= 64) {
            // hi words stay zero for v1-v11
            for ($bi = 0; $bi < $allCount; $bi++) {
                $cw = $codewords[$bi];
                if ($cw === 0) {
                    continue;
                }
                $p = $bi << 3;
                for ($bit = 7; $bit >= 0; $bit--, $p++) {
                    if (($cw >> $bit) & 1) {
                        $rowsLo[$zigY[$p]] |= $zigRowBitLo[$p];
                        $colsLo[$zigX[$p]] |= $zigColBitLo[$p];
                    }
                }
            }
        } else {
            $zigRowBitHi = $vc['zigRowBitHi'];
            $zigColBitHi = $vc['zigColBitHi'];
            for ($bi = 0; $bi < $allCount; $bi++) {
                $cw = $codewords[$bi];
                if ($cw === 0) {
                    continue;
                }
                $p = $bi << 3;
                for ($bit = 7; $bit >= 0; $bit--, $p++) {
                    if (($cw >> $bit) & 1) {
                        $y = $zigY[$p];
                        $x = $zigX[$p];
                        $rowsHi[$y] |= $zigRowBitHi[$p];
                        $rowsLo[$y] |= $zigRowBitLo[$p];
                        $colsHi[$x] |= $zigColBitHi[$p];
                        $colsLo[$x] |= $zigColBitLo[$p];
                    }
                }
            }
        }

        // === 4. Select best mask (bitwise penalty on whole rows/columns) ===
        $maskRowsHi = $vc['maskRowsHi'];
        $maskRowsLo = $vc['maskRowsLo'];
        $fmtRowsHi = $fc['fmtRowsHi'];
        $fmtRowsLo = $fc['fmtRowsLo'];

        $bestMask = $size <= 64
            ? $this->selectMask64($rowsLo, $colsLo, $maskRowsLo, $vc['maskColsLo'], $fmtRowsLo, $fc['fmtColsLo'], $size)
            : $this->selectMask128($rowsLo, $rowsHi, $colsLo, $colsHi, $maskRowsLo, $maskRowsHi, $vc['maskColsLo'], $vc['maskColsHi'], $fmtRowsLo, $fmtRowsHi, $fc['fmtColsLo'], $fc['fmtColsHi'], $size);

        // === 5. Apply best mask to get final rows ===
        $fxrHi = $maskRowsHi[$bestMask];
        $fxrLo = $maskRowsLo[$bestMask];
        $ffrHi = $fmtRowsHi[$bestMask];
        $ffrLo = $fmtRowsLo[$bestMask];
        for ($i = 0; $i < $size; $i++) {
            $rowsHi[$i] ^= $fxrHi[$i] ^ $ffrHi[$i];
            $rowsLo[$i] ^= $fxrLo[$i] ^ $ffrLo[$i];
        }

        // === 6. Convert int-pair rows → module bytes → Matrix ===
        // Each row is expanded 8 modules at a time through a 256-entry LUT of
        // "\0"/"\1" strings, then unpack() turns the whole symbol into a
        // list<int> in C. Matrix normalizes it to bool[] lazily on demand.
        $bytes = self::$moduleBytes;
        $s = '';
        if ($size <= 64) {
            $shift = 64 - $size;
            for ($y = 0; $y < $size; $y++) {
                $v = $rowsLo[$y] << $shift; // x = 0 lands on bit 63
                $s .= substr(
                    "{$bytes[($v >> 56) & 0xFF]}{$bytes[($v >> 48) & 0xFF]}{$bytes[($v >> 40) & 0xFF]}{$bytes[($v >> 32) & 0xFF]}"
                    . "{$bytes[($v >> 24) & 0xFF]}{$bytes[($v >> 16) & 0xFF]}{$bytes[($v >> 8) & 0xFF]}{$bytes[$v & 0xFF]}",
                    0,
                    $size,
                );
            }
        } else {
            $shift = 128 - $size;           // 3..63
            $loShift = $size - 64;          // 1..61
            $loMask = \PHP_INT_MAX >> ($size - 65);  // low (128 - size) bits
            for ($y = 0; $y < $size; $y++) {
                $lo = $rowsLo[$y];
                $v = ($rowsHi[$y] << $shift) | (($lo >> $loShift) & $loMask); // x = 0..63
                $w = $lo << $shift;                                           // x = 64..
                $s .= substr(
                    "{$bytes[($v >> 56) & 0xFF]}{$bytes[($v >> 48) & 0xFF]}{$bytes[($v >> 40) & 0xFF]}{$bytes[($v >> 32) & 0xFF]}"
                    . "{$bytes[($v >> 24) & 0xFF]}{$bytes[($v >> 16) & 0xFF]}{$bytes[($v >> 8) & 0xFF]}{$bytes[$v & 0xFF]}"
                    . "{$bytes[($w >> 56) & 0xFF]}{$bytes[($w >> 48) & 0xFF]}{$bytes[($w >> 40) & 0xFF]}{$bytes[($w >> 32) & 0xFF]}"
                    . "{$bytes[($w >> 24) & 0xFF]}{$bytes[($w >> 16) & 0xFF]}{$bytes[($w >> 8) & 0xFF]}{$bytes[$w & 0xFF]}",
                    0,
                    $size,
                );
            }
        }

        /** @var list<int> $modules */
        $modules = array_values((array) unpack('C*', $s));

        return new Matrix($version, $modules, normalized: false);
    }

    // =========================================================================
    // Mask selection: bitwise penalty rules on whole lines
    // =========================================================================
    //
    // Every row and every column is one bitset (bit i = module i counted from
    // the far end; the rules are symmetric so orientation does not matter).
    // Instead of walking modules one by one, the penalty rules are evaluated
    // with shifts/ands on the whole line — the same formulation as the C++
    // kernel in clib/src/mask_kernel.hpp:
    //
    //   rule 1: C5 = C & C>>1 & ... & C>>4 marks every start of a run >= 5 of
    //           colour C; a run of length L sets L-4 bits plus one group start,
    //           and (L-4) + 2 == L-2 is exactly 3 + (L-5).
    //   rule 3: the n = 1 finder-like pattern (L D L DDD L D L with >= 4 light
    //           on either side) is an 11-bit template; bits beyond the symbol
    //           are zero, so shifts pull in "light" — nayuki's virtual border.
    //           Scaled (n >= 2) patterns need a dark run >= 6 preceded by two
    //           light modules; those anchors are rare and checked locally.
    //   rule 2: 2x2 blocks between adjacent rows.
    //   rule 4: popcount of all rows.
    //
    // Popcounts use SWAR reduced to 16-bit fields and accumulated across lines
    // (max 48 per field per line, <= 122 lines), folded once per mask.

    private const SWAR_M1 = 0x5555555555555555;
    private const SWAR_M2 = 0x3333333333333333;
    private const SWAR_M4 = 0x0F0F0F0F0F0F0F0F;
    private const SWAR_M8 = 0x00FF00FF00FF00FF;

    /**
     * Sizes <= 61 (v1-v11): one non-negative 64-bit int per line.
     *
     * @param int[] $rowsLo
     * @param int[] $colsLo
     * @param array<int, int[]> $maskRowsLo
     * @param array<int, int[]> $maskColsLo
     * @param array<int, int[]> $fmtRowsLo
     * @param array<int, int[]> $fmtColsLo
     */
    private function selectMask64(
        array $rowsLo,
        array $colsLo,
        array $maskRowsLo,
        array $maskColsLo,
        array $fmtRowsLo,
        array $fmtColsLo,
        int $size,
    ): int {
        $valid = (1 << $size) - 1;
        $valid2 = (1 << ($size - 1)) - 1;
        $total = $size * $size;
        $lineCount = $size << 1;

        $bestMask = 0;
        $bestScore = \PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $xr = $maskRowsLo[$mask];
            $fr = $fmtRowsLo[$mask];
            $xc = $maskColsLo[$mask];
            $fcl = $fmtColsLo[$mask];

            $lines = [];
            for ($i = 0; $i < $size; $i++) {
                $lines[] = $rowsLo[$i] ^ $xr[$i] ^ $fr[$i];
            }
            for ($i = 0; $i < $size; $i++) {
                $lines[] = $colsLo[$i] ^ $xc[$i] ^ $fcl[$i];
            }

            $acc1 = 0;
            $r3 = 0;

            for ($i = 0; $i < $lineCount; $i++) {
                $r = $lines[$i];
                $s1 = $r >> 1;
                $s2 = $r >> 2;
                $s5 = $r >> 5;
                $d2 = $r & $s1;
                $d3 = $d2 & $s2;
                $d4 = $d2 & ($d2 >> 2);
                $d5 = $d4 & ($r >> 4);
                $sl1 = $r << 1;

                // Rule 1
                $l = ~$r & $valid;
                $l2 = $l & ($l >> 1);
                $l4 = $l2 & ($l2 >> 2);
                $runs = $d5 | ($l4 & ($l >> 4));
                $starts = $runs & ~($runs >> 1);
                $a = $runs - (($runs >> 1) & self::SWAR_M1);
                $a = ($a & self::SWAR_M2) + (($a >> 2) & self::SWAR_M2);
                $b = $starts - (($starts >> 1) & self::SWAR_M1);
                $b = ($b & self::SWAR_M2) + (($b >> 2) & self::SWAR_M2);
                $t = $a + $b + $b;
                $t = ($t + ($t >> 4)) & self::SWAR_M4;
                $acc1 += ($t + ($t >> 8)) & self::SWAR_M8;

                // Rule 3, n = 1
                $core = $r & ~$s1 & ($d3 >> 2) & ~$s5 & ($r >> 6) & ~($r >> 7) & ~$sl1;
                if ($core !== 0) {
                    $pa = $core & ~(($r | $sl1 | ($r << 2)) << 2);
                    $pb = $core & ~(($r | $s1 | $s2) >> 8);
                    $hits = $pa | $pb;
                    if ($hits !== 0) {
                        $r3 += $this->popcount($pa) + $this->popcount($pb);
                    }
                }

                // Rule 3, n >= 2: dark run >= 6 preceded by two light modules
                $anch = $d5 & $s5 & ~(($r | $sl1) << 1);
                if ($anch !== 0) {
                    for ($x = 0; $anch !== 0; $x++) {
                        if (($anch >> $x) & 1) {
                            $anch ^= 1 << $x;
                            $r3 += $this->scaledPatternsAt($r, 0, $size, $x);
                        }
                    }
                }
            }

            // Rules 2 and 4 over rows
            $acc2 = 0;
            $prev = $lines[0];
            $prevS = $prev >> 1;
            $t = $prev - (($prev >> 1) & self::SWAR_M1);
            $t = ($t & self::SWAR_M2) + (($t >> 2) & self::SWAR_M2);
            $t = ($t + ($t >> 4)) & self::SWAR_M4;
            $accDark = ($t + ($t >> 8)) & self::SWAR_M8;
            for ($y = 1; $y < $size; $y++) {
                $r = $lines[$y];
                $s = $r >> 1;
                $blocks = ($prev & $prevS & $r & $s & $valid2) | (~($prev | $prevS | $r | $s) & $valid2);
                $t = $blocks - (($blocks >> 1) & self::SWAR_M1);
                $t = ($t & self::SWAR_M2) + (($t >> 2) & self::SWAR_M2);
                $t = ($t + ($t >> 4)) & self::SWAR_M4;
                $acc2 += ($t + ($t >> 8)) & self::SWAR_M8;
                $t = $r - (($r >> 1) & self::SWAR_M1);
                $t = ($t & self::SWAR_M2) + (($t >> 2) & self::SWAR_M2);
                $t = ($t + ($t >> 4)) & self::SWAR_M4;
                $accDark += ($t + ($t >> 8)) & self::SWAR_M8;
                $prev = $r;
                $prevS = $s;
            }

            $acc1 += $acc1 >> 32;
            $acc1 += $acc1 >> 16;
            $acc2 += $acc2 >> 32;
            $acc2 += $acc2 >> 16;
            $accDark += $accDark >> 32;
            $accDark += $accDark >> 16;
            $dark = $accDark & 0xFFFF;
            $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
            $penalty = ($acc1 & 0xFFFF) + 3 * ($acc2 & 0xFFFF) + 40 * $r3 + 10 * $k;

            if ($penalty < $bestScore) {
                $bestScore = $penalty;
                $bestMask = $mask;
            }
        }

        return $bestMask;
    }

    /**
     * Sizes 65..125 (v12-v27): int pair per line, lo = bits 0..63 (may be
     * negative), hi = bits 64..size-1 (always non-negative).
     *
     * @param int[] $rowsLo
     * @param int[] $rowsHi
     * @param int[] $colsLo
     * @param int[] $colsHi
     * @param array<int, int[]> $maskRowsLo
     * @param array<int, int[]> $maskRowsHi
     * @param array<int, int[]> $maskColsLo
     * @param array<int, int[]> $maskColsHi
     * @param array<int, int[]> $fmtRowsLo
     * @param array<int, int[]> $fmtRowsHi
     * @param array<int, int[]> $fmtColsLo
     * @param array<int, int[]> $fmtColsHi
     */
    private function selectMask128(
        array $rowsLo,
        array $rowsHi,
        array $colsLo,
        array $colsHi,
        array $maskRowsLo,
        array $maskRowsHi,
        array $maskColsLo,
        array $maskColsHi,
        array $fmtRowsLo,
        array $fmtRowsHi,
        array $fmtColsLo,
        array $fmtColsHi,
        int $size,
    ): int {
        $validHi = (1 << ($size - 64)) - 1;
        $valid2Hi = (1 << ($size - 65)) - 1;
        $total = $size * $size;
        $lineCount = $size << 1;

        $bestMask = 0;
        $bestScore = \PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $xrLo = $maskRowsLo[$mask];
            $xrHi = $maskRowsHi[$mask];
            $frLo = $fmtRowsLo[$mask];
            $frHi = $fmtRowsHi[$mask];
            $xcLo = $maskColsLo[$mask];
            $xcHi = $maskColsHi[$mask];
            $fcLo = $fmtColsLo[$mask];
            $fcHi = $fmtColsHi[$mask];

            $linesLo = [];
            $linesHi = [];
            for ($i = 0; $i < $size; $i++) {
                $linesLo[] = $rowsLo[$i] ^ $xrLo[$i] ^ $frLo[$i];
                $linesHi[] = $rowsHi[$i] ^ $xrHi[$i] ^ $frHi[$i];
            }
            for ($i = 0; $i < $size; $i++) {
                $linesLo[] = $colsLo[$i] ^ $xcLo[$i] ^ $fcLo[$i];
                $linesHi[] = $colsHi[$i] ^ $xcHi[$i] ^ $fcHi[$i];
            }

            $acc1 = 0;
            $r3 = 0;

            for ($i = 0; $i < $lineCount; $i++) {
                $lo = $linesLo[$i];
                $hi = $linesHi[$i];

                // Shifted copies of the line (128-bit shifts across the pair)
                $s1Lo = (($lo >> 1) & \PHP_INT_MAX) | ($hi << 63);
                $s1Hi = $hi >> 1;
                $s2Lo = (($lo >> 2) & (\PHP_INT_MAX >> 1)) | ($hi << 62);
                $s2Hi = $hi >> 2;
                $s4Lo = (($lo >> 4) & (\PHP_INT_MAX >> 3)) | ($hi << 60);
                $s4Hi = $hi >> 4;
                $s5Lo = (($lo >> 5) & (\PHP_INT_MAX >> 4)) | ($hi << 59);
                $s5Hi = $hi >> 5;
                $sl1Lo = $lo << 1;
                $sl1Hi = ($hi << 1) | (($lo >> 63) & 1);

                $d2Lo = $lo & $s1Lo;
                $d2Hi = $hi & $s1Hi;
                $d3Lo = $d2Lo & $s2Lo;
                $d3Hi = $d2Hi & $s2Hi;
                $d4Lo = $d2Lo & ((($d2Lo >> 2) & (\PHP_INT_MAX >> 1)) | ($d2Hi << 62));
                $d4Hi = $d2Hi & ($d2Hi >> 2);
                $d5Lo = $d4Lo & $s4Lo;
                $d5Hi = $d4Hi & $s4Hi;

                // Rule 1
                $lLo = ~$lo;
                $lHi = ~$hi & $validHi;
                $l2Lo = $lLo & ((($lLo >> 1) & \PHP_INT_MAX) | ($lHi << 63));
                $l2Hi = $lHi & ($lHi >> 1);
                $l4Lo = $l2Lo & ((($l2Lo >> 2) & (\PHP_INT_MAX >> 1)) | ($l2Hi << 62));
                $l4Hi = $l2Hi & ($l2Hi >> 2);
                $runsLo = $d5Lo | ($l4Lo & ((($lLo >> 4) & (\PHP_INT_MAX >> 3)) | ($lHi << 60)));
                $runsHi = $d5Hi | ($l4Hi & ($lHi >> 4));
                $startsLo = $runsLo & ~((($runsLo >> 1) & \PHP_INT_MAX) | ($runsHi << 63));
                $startsHi = $runsHi & ~($runsHi >> 1);

                // bit 63 handled separately: the SWAR steps need non-negative ints
                $acc1 += (($runsLo >> 63) & 1) + ((($startsLo >> 63) & 1) << 1);
                $a = $runsLo & \PHP_INT_MAX;
                $a -= ($a >> 1) & self::SWAR_M1;
                $a = ($a & self::SWAR_M2) + (($a >> 2) & self::SWAR_M2);
                $b = $startsLo & \PHP_INT_MAX;
                $b -= ($b >> 1) & self::SWAR_M1;
                $b = ($b & self::SWAR_M2) + (($b >> 2) & self::SWAR_M2);
                $t = $a + $b + $b;
                $t = ($t + ($t >> 4)) & self::SWAR_M4;
                $acc1 += ($t + ($t >> 8)) & self::SWAR_M8;
                $a = $runsHi - (($runsHi >> 1) & self::SWAR_M1);
                $a = ($a & self::SWAR_M2) + (($a >> 2) & self::SWAR_M2);
                $b = $startsHi - (($startsHi >> 1) & self::SWAR_M1);
                $b = ($b & self::SWAR_M2) + (($b >> 2) & self::SWAR_M2);
                $t = $a + $b + $b;
                $t = ($t + ($t >> 4)) & self::SWAR_M4;
                $acc1 += ($t + ($t >> 8)) & self::SWAR_M8;

                // Rule 3, n = 1
                $s6Lo = (($lo >> 6) & (\PHP_INT_MAX >> 5)) | ($hi << 58);
                $s6Hi = $hi >> 6;
                $s7Lo = (($lo >> 7) & (\PHP_INT_MAX >> 6)) | ($hi << 57);
                $s7Hi = $hi >> 7;
                $coreLo = $lo & ~$s1Lo & ((($d3Lo >> 2) & (\PHP_INT_MAX >> 1)) | ($d3Hi << 62))
                    & ~$s5Lo & $s6Lo & ~$s7Lo & ~$sl1Lo;
                $coreHi = $hi & ~$s1Hi & ($d3Hi >> 2) & ~$s5Hi & $s6Hi & ~$s7Hi & ~$sl1Hi;
                if (($coreLo | $coreHi) !== 0) {
                    $o3lLo = $lo | $sl1Lo | ($lo << 2);
                    $o3lHi = $hi | $sl1Hi | ($hi << 2) | (($lo >> 62) & 3);
                    $paLo = $coreLo & ~($o3lLo << 2);
                    $paHi = $coreHi & ~(($o3lHi << 2) | (($o3lLo >> 62) & 3));
                    $o3rLo = $lo | $s1Lo | $s2Lo;
                    $o3rHi = $hi | $s1Hi | $s2Hi;
                    $pbLo = $coreLo & ~((($o3rLo >> 8) & (\PHP_INT_MAX >> 7)) | ($o3rHi << 56));
                    $pbHi = $coreHi & ~($o3rHi >> 8);
                    if (($paLo | $paHi | $pbLo | $pbHi) !== 0) {
                        $r3 += $this->popcount($paLo) + $this->popcount($paHi)
                            + $this->popcount($pbLo) + $this->popcount($pbHi);
                    }
                }

                // Rule 3, n >= 2
                $o1Lo = $lo | $sl1Lo;
                $o1Hi = $hi | $sl1Hi;
                $anchLo = $d5Lo & $s5Lo & ~($o1Lo << 1);
                $anchHi = $d5Hi & $s5Hi & ~(($o1Hi << 1) | (($o1Lo >> 63) & 1));
                if (($anchLo | $anchHi) !== 0) {
                    for ($x = 0; $anchLo !== 0; $x++) {
                        if (($anchLo >> $x) & 1) {
                            $anchLo ^= 1 << $x;
                            $r3 += $this->scaledPatternsAt($lo, $hi, $size, $x);
                        }
                    }
                    for ($x = 0; $anchHi !== 0; $x++) {
                        if (($anchHi >> $x) & 1) {
                            $anchHi ^= 1 << $x;
                            $r3 += $this->scaledPatternsAt($lo, $hi, $size, $x + 64);
                        }
                    }
                }
            }

            // Rules 2 and 4 over rows
            $acc2 = 0;
            $prevLo = $linesLo[0];
            $prevHi = $linesHi[0];
            $prevSLo = (($prevLo >> 1) & \PHP_INT_MAX) | ($prevHi << 63);
            $prevSHi = $prevHi >> 1;
            $t = $prevLo & \PHP_INT_MAX;
            $t -= ($t >> 1) & self::SWAR_M1;
            $t = ($t & self::SWAR_M2) + (($t >> 2) & self::SWAR_M2);
            $t = ($t + ($t >> 4)) & self::SWAR_M4;
            $accDark = (($t + ($t >> 8)) & self::SWAR_M8) + (($prevLo >> 63) & 1);
            $t = $prevHi - (($prevHi >> 1) & self::SWAR_M1);
            $t = ($t & self::SWAR_M2) + (($t >> 2) & self::SWAR_M2);
            $t = ($t + ($t >> 4)) & self::SWAR_M4;
            $accDark += ($t + ($t >> 8)) & self::SWAR_M8;
            for ($y = 1; $y < $size; $y++) {
                $lo = $linesLo[$y];
                $hi = $linesHi[$y];
                $sLo = (($lo >> 1) & \PHP_INT_MAX) | ($hi << 63);
                $sHi = $hi >> 1;
                $blocks = ($prevLo & $prevSLo & $lo & $sLo) | ~($prevLo | $prevSLo | $lo | $sLo);
                $acc2 += ($blocks >> 63) & 1;
                $t = $blocks & \PHP_INT_MAX;
                $t -= ($t >> 1) & self::SWAR_M1;
                $t = ($t & self::SWAR_M2) + (($t >> 2) & self::SWAR_M2);
                $t = ($t + ($t >> 4)) & self::SWAR_M4;
                $acc2 += ($t + ($t >> 8)) & self::SWAR_M8;
                $blocks = (($prevHi & $prevSHi & $hi & $sHi) | ~($prevHi | $prevSHi | $hi | $sHi)) & $valid2Hi;
                $t = $blocks - (($blocks >> 1) & self::SWAR_M1);
                $t = ($t & self::SWAR_M2) + (($t >> 2) & self::SWAR_M2);
                $t = ($t + ($t >> 4)) & self::SWAR_M4;
                $acc2 += ($t + ($t >> 8)) & self::SWAR_M8;
                $accDark += ($lo >> 63) & 1;
                $t = $lo & \PHP_INT_MAX;
                $t -= ($t >> 1) & self::SWAR_M1;
                $t = ($t & self::SWAR_M2) + (($t >> 2) & self::SWAR_M2);
                $t = ($t + ($t >> 4)) & self::SWAR_M4;
                $accDark += ($t + ($t >> 8)) & self::SWAR_M8;
                $t = $hi - (($hi >> 1) & self::SWAR_M1);
                $t = ($t & self::SWAR_M2) + (($t >> 2) & self::SWAR_M2);
                $t = ($t + ($t >> 4)) & self::SWAR_M4;
                $accDark += ($t + ($t >> 8)) & self::SWAR_M8;
                $prevLo = $lo;
                $prevHi = $hi;
                $prevSLo = $sLo;
                $prevSHi = $sHi;
            }

            $acc1 += $acc1 >> 32;
            $acc1 += $acc1 >> 16;
            $acc2 += $acc2 >> 32;
            $acc2 += $acc2 >> 16;
            $accDark += $accDark >> 32;
            $accDark += $accDark >> 16;
            $dark = $accDark & 0xFFFF;
            $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;
            $penalty = ($acc1 & 0xFFFF) + 3 * ($acc2 & 0xFFFF) + 40 * $r3 + 10 * $k;

            if ($penalty < $bestScore) {
                $bestScore = $penalty;
                $bestMask = $mask;
            }
        }

        return $bestMask;
    }

    private function popcount(int $x): int
    {
        $top = ($x >> 63) & 1;
        $x &= \PHP_INT_MAX;
        $x -= ($x >> 1) & self::SWAR_M1;
        $x = ($x & self::SWAR_M2) + (($x >> 2) & self::SWAR_M2);
        $x = ($x + ($x >> 4)) & self::SWAR_M4;
        $x += $x >> 8;
        $x += $x >> 16;
        $x += $x >> 32;
        return ($x & 0x7F) + $top;
    }

    /**
     * Counts scaled (n >= 2) finder-like patterns whose central 3n dark run
     * starts at bit $x of the line (lo, hi). Mirrors nayuki's run-history
     * test exactly, including the border acting as an arbitrarily long light
     * run — see scaled_patterns_at() in clib/src/mask_common.hpp.
     */
    private function scaledPatternsAt(int $lo, int $hi, int $size, int $x): int
    {
        // light run immediately before: exactly n >= 2, not touching the border
        $i = $x - 1;
        $n = 0;
        while ($i >= 0 && !(($i < 64 ? $lo >> $i : $hi >> ($i - 64)) & 1)) {
            $n++;
            $i--;
        }
        if ($i < 0 || $n < 2) {
            return 0;
        }
        // dark run of exactly 3n starting at x
        $d = 0;
        $i = $x;
        while ($i < $size && (($i < 64 ? $lo >> $i : $hi >> ($i - 64)) & 1) && $d <= 3 * $n) {
            $d++;
            $i++;
        }
        if ($d !== 3 * $n) {
            return 0;
        }
        // light run of exactly n after
        $nr = 0;
        while ($i < $size && !(($i < 64 ? $lo >> $i : $hi >> ($i - 64)) & 1) && $nr <= $n) {
            $nr++;
            $i++;
        }
        if ($i >= $size || $nr !== $n) {
            return 0;
        }
        // dark run of exactly n after
        $dr = 0;
        while ($i < $size && (($i < 64 ? $lo >> $i : $hi >> ($i - 64)) & 1) && $dr <= $n) {
            $dr++;
            $i++;
        }
        if ($dr !== $n) {
            return 0;
        }
        // outer light run after, capped at 4n; border counts as >= 4n
        $outerR = 0;
        while ($i < $size && !(($i < 64 ? $lo >> $i : $hi >> ($i - 64)) & 1) && $outerR < 4 * $n) {
            $outerR++;
            $i++;
        }
        if ($i >= $size) {
            $outerR = 4 * $n;
        }
        // dark run of exactly n before (may touch the border)
        $j = $x - $n - 1;
        $dl = 0;
        while ($j >= 0 && (($j < 64 ? $lo >> $j : $hi >> ($j - 64)) & 1) && $dl <= $n) {
            $dl++;
            $j--;
        }
        if ($dl !== $n) {
            return 0;
        }
        // outer light run before, capped at 4n; border counts as >= 4n
        $outerL = 0;
        while ($j >= 0 && !(($j < 64 ? $lo >> $j : $hi >> ($j - 64)) & 1) && $outerL < 4 * $n) {
            $outerL++;
            $j--;
        }
        if ($j < 0) {
            $outerL = 4 * $n;
        }

        return ($outerL >= 4 * $n && $outerR >= $n ? 1 : 0)
            + ($outerR >= 4 * $n && $outerL >= $n ? 1 : 0);
    }

    // =========================================================================
    // Static table initialization (runs once, cached forever)
    // =========================================================================

    private function initTables(): void
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
            $chunk = '';
            for ($bit = 7; $bit >= 0; $bit--) {
                $chunk .= (($i >> $bit) & 1) ? "\1" : "\0";
            }
            self::$moduleBytes[$i] = $chunk;
        }
    }

    /**
     * Build and cache all version-specific data using int-pair representation.
     */
    private function buildVersionCache(int $version, int $size): void
    {
        $sizeM1 = $size - 1;
        $totalModules = $size * $size;

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
                    if ($cx <= 8 && $cy <= 8) {
                        continue;
                    }
                    if ($cx >= $sizeM8 && $cy <= 8) {
                        continue;
                    }
                    if ($cx <= 8 && $cy >= $sizeM8) {
                        continue;
                    }
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
                    if ($cx <= 8 && $cy <= 8) {
                        continue;
                    }
                    if ($cx >= $sizeM8 && $cy <= 8) {
                        continue;
                    }
                    if ($cx <= 8 && $cy >= $sizeM8) {
                        continue;
                    }
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
            $versionBits = $this->computeVersionBits($version);
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
    private function buildFormatCache(int $version, ErrorCorrectionLevel $ecl, int $size): void
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

        $allFmtRowsHi = [];
        $allFmtRowsLo = [];
        $allFmtColsHi = [];
        $allFmtColsLo = [];

        for ($mask = 0; $mask < 8; $mask++) {
            $fRHi = array_fill(0, $size, 0);
            $fRLo = array_fill(0, $size, 0);
            $fCHi = array_fill(0, $size, 0);
            $fCLo = array_fill(0, $size, 0);

            $maskBits = $this->computeFormatBitsFromEcc($eccBits, $mask);

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

            $allFmtRowsHi[$mask] = $fRHi;
            $allFmtRowsLo[$mask] = $fRLo;
            $allFmtColsHi[$mask] = $fCHi;
            $allFmtColsLo[$mask] = $fCLo;
        }

        self::$formatCache[$fmtKey] = [
            'fmtRowsHi' => $allFmtRowsHi, 'fmtRowsLo' => $allFmtRowsLo,
            'fmtColsHi' => $allFmtColsHi, 'fmtColsLo' => $allFmtColsLo,
        ];
    }

    /**
     * Build and cache RS transposed factor table for a given ECC count.
     */
    private function buildRsCache(int $eccCount): void
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
            $row = [0, 0, 0, 0];
            for ($i = 0; $i < $eccCount; $i++) {
                $coeff = $genLog[$i] !== -1 ? $exp[$genLog[$i] + $lf] : 0;
                $row[$i >> 3] |= $coeff << (($i & 7) << 3);
            }
            $factorTable[$f] = $row;
        }

        self::$rsCache[$eccCount] = $factorTable;
    }

    // =========================================================================
    // Helper methods (cache building only, not in hot path)
    // =========================================================================

    private function computeFormatBitsFromEcc(int $eccBits, int $maskPattern): int
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

    private function computeVersionBits(int $version): int
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
