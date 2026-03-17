<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

use CrazyGoat\ScanMePHP\Encoding\DataEncoder;
use CrazyGoat\ScanMePHP\Encoding\MaskSelector;
use CrazyGoat\ScanMePHP\Encoding\MatrixBuilder;
use CrazyGoat\ScanMePHP\Encoding\Mode;
use CrazyGoat\ScanMePHP\Encoding\ReedSolomon;
use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;
use CrazyGoat\ScanMePHP\Exception\InvalidDataException;

class Encoder implements EncoderInterface
{
    private DataEncoder $dataEncoder;
    private ReedSolomon $reedSolomon;
    private MatrixBuilder $matrixBuilder;
    private MaskSelector $maskSelector;

    // Character capacity table for each version and error correction level
    // [version-1][errorCorrectionLevel][mode] = capacity
    // Modes: 0=Numeric, 1=Alphanumeric, 2=Byte, 3=Kanji
    // Source: ISO/IEC 18004:2015 Table 7
    private array $capacityTable = [
        [[41, 25, 17, 10], [34, 20, 14, 8], [27, 16, 11, 7], [17, 10, 7, 4]],           // v1
        [[77, 47, 32, 20], [63, 38, 26, 16], [48, 29, 20, 12], [34, 20, 14, 8]],         // v2
        [[127, 77, 53, 32], [101, 61, 42, 26], [77, 47, 32, 20], [58, 35, 24, 15]],      // v3
        [[187, 114, 78, 48], [149, 90, 62, 38], [111, 67, 46, 28], [82, 50, 34, 21]],    // v4
        [[255, 154, 106, 65], [202, 122, 84, 52], [144, 87, 60, 37], [106, 64, 44, 27]], // v5
        [[322, 195, 134, 82], [255, 154, 106, 65], [178, 108, 74, 45], [139, 84, 58, 36]], // v6
        [[370, 224, 154, 95], [293, 178, 122, 75], [207, 125, 86, 53], [154, 93, 64, 39]], // v7
        [[461, 279, 192, 118], [365, 221, 152, 93], [259, 157, 108, 66], [202, 122, 84, 52]], // v8
        [[552, 335, 230, 141], [432, 262, 180, 111], [312, 189, 130, 80], [235, 143, 98, 60]], // v9
        [[652, 395, 271, 167], [513, 311, 213, 131], [364, 221, 151, 93], [288, 174, 119, 74]], // v10
        [[772, 468, 321, 198], [604, 366, 251, 155], [427, 259, 177, 109], [331, 200, 137, 85]], // v11
        [[883, 535, 367, 226], [691, 419, 287, 177], [489, 296, 203, 125], [374, 227, 155, 96]], // v12
        [[1022, 619, 425, 262], [796, 483, 331, 204], [580, 352, 241, 149], [427, 259, 177, 109]], // v13
        [[1101, 667, 458, 282], [871, 528, 362, 223], [621, 376, 258, 159], [468, 283, 194, 120]], // v14
        [[1250, 758, 520, 320], [991, 600, 412, 254], [703, 426, 292, 180], [530, 321, 220, 136]], // v15
        [[1408, 854, 586, 361], [1082, 656, 450, 277], [775, 470, 322, 198], [602, 365, 250, 154]], // v16
        [[1548, 938, 644, 397], [1212, 734, 504, 310], [876, 531, 364, 224], [674, 408, 280, 173]], // v17
        [[1725, 1046, 718, 442], [1346, 816, 560, 345], [948, 574, 394, 243], [746, 452, 310, 191]], // v18
        [[1903, 1153, 792, 488], [1500, 909, 624, 384], [1063, 644, 442, 272], [813, 493, 338, 208]], // v19
        [[2061, 1249, 858, 528], [1600, 970, 666, 410], [1159, 702, 482, 297], [919, 557, 382, 235]], // v20
        [[2232, 1352, 929, 572], [1708, 1035, 711, 438], [1224, 742, 509, 314], [969, 587, 403, 248]], // v21
        [[2409, 1460, 1003, 618], [1872, 1134, 779, 480], [1358, 823, 565, 348], [1056, 640, 439, 270]], // v22
        [[2620, 1588, 1091, 672], [2059, 1248, 857, 528], [1468, 890, 611, 376], [1108, 672, 461, 284]], // v23
        [[2812, 1704, 1171, 721], [2188, 1326, 911, 561], [1588, 963, 661, 407], [1228, 744, 511, 315]], // v24
        [[3057, 1853, 1273, 784], [2395, 1451, 997, 614], [1718, 1041, 715, 440], [1286, 779, 535, 330]], // v25
        [[3283, 1990, 1367, 842], [2544, 1542, 1059, 652], [1804, 1094, 751, 462], [1425, 864, 593, 365]], // v26
        [[3517, 2132, 1465, 902], [2701, 1637, 1125, 692], [1933, 1172, 805, 496], [1501, 910, 625, 385]], // v27
        [[3669, 2223, 1528, 940], [2857, 1732, 1190, 732], [2085, 1263, 868, 534], [1581, 958, 658, 405]], // v28
        [[3909, 2369, 1628, 1002], [3035, 1839, 1264, 778], [2181, 1322, 908, 559], [1677, 1016, 698, 430]], // v29
        [[4158, 2520, 1732, 1066], [3289, 1994, 1370, 843], [2358, 1429, 982, 604], [1782, 1080, 742, 457]], // v30
        [[4417, 2677, 1840, 1132], [3486, 2113, 1452, 894], [2473, 1499, 1030, 634], [1897, 1150, 790, 486]], // v31
        [[4686, 2840, 1952, 1201], [3693, 2238, 1538, 947], [2670, 1618, 1112, 684], [2022, 1226, 842, 518]], // v32
        [[4965, 3009, 2068, 1273], [3909, 2369, 1628, 1002], [2805, 1700, 1168, 719], [2157, 1307, 898, 553]], // v33
        [[5253, 3183, 2188, 1347], [4134, 2506, 1722, 1060], [2949, 1787, 1228, 756], [2301, 1394, 958, 590]], // v34
        [[5529, 3351, 2303, 1417], [4343, 2632, 1809, 1113], [3081, 1867, 1283, 790], [2361, 1431, 983, 605]], // v35
        [[5836, 3537, 2431, 1496], [4588, 2780, 1911, 1176], [3244, 1966, 1351, 832], [2524, 1530, 1051, 647]], // v36
        [[6153, 3729, 2563, 1577], [4775, 2894, 1989, 1224], [3417, 2071, 1423, 876], [2625, 1591, 1093, 673]], // v37
        [[6479, 3927, 2699, 1661], [5039, 3054, 2099, 1292], [3599, 2181, 1499, 923], [2735, 1658, 1139, 701]], // v38
        [[6743, 4087, 2809, 1729], [5313, 3220, 2213, 1362], [3791, 2298, 1579, 972], [2927, 1774, 1219, 750]], // v39
        [[7089, 4296, 2953, 1817], [5596, 3391, 2331, 1435], [3993, 2420, 1663, 1024], [3057, 1852, 1273, 784]], // v40
    ];

    public function __construct()
    {
        $this->dataEncoder = new DataEncoder();
        $this->reedSolomon = new ReedSolomon();
        $this->matrixBuilder = new MatrixBuilder();
        $this->maskSelector = new MaskSelector();
    }

    public function encode(
        string $url,
        ErrorCorrectionLevel $errorCorrectionLevel,
        int $requestedVersion = 0,
        ?Mode $forcedMode = null
    ): Matrix {
        $data = $url;
        if (empty($data)) {
            throw InvalidDataException::emptyData();
        }

        $mode = Mode::Byte;

        // Determine version
        $version = $this->determineVersion($data, $errorCorrectionLevel, $requestedVersion);

        // Encode data
        $encodedData = $this->dataEncoder->encode($data, $mode, $version);
        
        // Calculate total capacity
        $totalCapacity = $this->getTotalDataCodewords($version, $errorCorrectionLevel);
        
        // Add terminator and padding
        $encodedData = $this->dataEncoder->addTerminatorAndPadding($encodedData, $totalCapacity);

        // Generate ECC per block and interleave all codewords
        $allCodewords = $this->reedSolomon->encodeWithInterleaving(
            $encodedData,
            $version,
            $errorCorrectionLevel->value
        );

        $matrix = $this->matrixBuilder->buildUnmasked($version, $allCodewords, []);

        $maskPattern = $this->maskSelector->selectBestMask($matrix, $errorCorrectionLevel);

        $this->matrixBuilder->applyMaskAndFormatInfo($matrix, $errorCorrectionLevel, $maskPattern);

        return $matrix;
    }

    public function getMinimumVersion(
        string $data,
        ErrorCorrectionLevel $errorCorrectionLevel,
        ?Mode $forcedMode = null
    ): int {
        $modeIndex = 2;
        $dataLength = strlen($data);
        $eccIndex = $errorCorrectionLevel->value;

        for ($version = 1; $version <= 40; $version++) {
            $capacity = $this->capacityTable[$version - 1][$eccIndex][$modeIndex];
            if ($dataLength <= $capacity) {
                return $version;
            }
        }

        throw DataTooLargeException::dataExceedsMaximumCapacity(
            strlen($data),
            $errorCorrectionLevel
        );
    }

    public function validateDataFits(
        string $data,
        int $version,
        ErrorCorrectionLevel $errorCorrectionLevel,
        ?Mode $forcedMode = null
    ): bool {
        try {
            $minimumVersion = $this->getMinimumVersion($data, $errorCorrectionLevel, $forcedMode);
            return $version >= $minimumVersion;
        } catch (DataTooLargeException) {
            return false;
        }
    }

    private function determineVersion(
        string $data,
        ErrorCorrectionLevel $errorCorrectionLevel,
        int $requestedVersion
    ): int {
        $minimumVersion = $this->getMinimumVersion($data, $errorCorrectionLevel);

        if ($requestedVersion === 0) {
            // Auto-detect version
            return $minimumVersion;
        }

        if ($requestedVersion < $minimumVersion) {
            throw DataTooLargeException::dataDoesNotFitInVersion(
                strlen($data),
                $requestedVersion,
                $errorCorrectionLevel,
                $minimumVersion
            );
        }

        return $requestedVersion;
    }

    private const TOTAL_CODEWORDS = [
        0,
        26, 44, 70, 100, 134, 172, 196, 242, 292, 346,
        404, 466, 532, 581, 655, 733, 815, 901, 991, 1085,
        1156, 1258, 1364, 1474, 1588, 1706, 1828, 1921, 2051, 2185,
        2323, 2465, 2611, 2761, 2876, 3034, 3196, 3362, 3532, 3706,
    ];

    private function getTotalDataCodewords(int $version, ErrorCorrectionLevel $errorCorrectionLevel): int
    {
        $totalCodewords = self::TOTAL_CODEWORDS[$version];
        $eccCount = $this->reedSolomon->getEccCount($version, $errorCorrectionLevel->value);
        return $totalCodewords - $eccCount;
    }


}
