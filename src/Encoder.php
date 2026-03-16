<?php

declare(strict_types=1);

namespace ScanMePHP;

use ScanMePHP\Encoding\DataAnalyzer;
use ScanMePHP\Encoding\DataEncoder;
use ScanMePHP\Encoding\MaskSelector;
use ScanMePHP\Encoding\MatrixBuilder;
use ScanMePHP\Encoding\Mode;
use ScanMePHP\Encoding\ReedSolomon;
use ScanMePHP\Exception\DataTooLargeException;
use ScanMePHP\Exception\InvalidDataException;

class Encoder
{
    private DataAnalyzer $analyzer;
    private DataEncoder $dataEncoder;
    private ReedSolomon $reedSolomon;
    private MatrixBuilder $matrixBuilder;
    private MaskSelector $maskSelector;

    // Character capacity table for each version and error correction level
    // [version-1][errorCorrectionLevel][mode] = capacity
    // Modes: 0=Numeric, 1=Alphanumeric, 2=Byte, 3=Kanji
    private array $capacityTable = [
        // Version 1
        [[41, 25, 17, 10], [34, 20, 14, 8], [27, 16, 11, 7], [17, 10, 7, 4]],
        // Version 2
        [[77, 47, 32, 20], [63, 38, 26, 16], [48, 29, 20, 12], [34, 20, 14, 8]],
        // Version 3
        [[127, 77, 53, 32], [101, 61, 42, 26], [77, 47, 32, 20], [58, 35, 24, 15]],
        // Version 4
        [[187, 114, 78, 48], [149, 90, 62, 38], [111, 67, 46, 28], [82, 50, 34, 21]],
        // Version 5
        [[255, 154, 106, 65], [202, 122, 84, 52], [144, 87, 60, 37], [106, 64, 44, 27]],
        // Version 6
        [[322, 195, 134, 82], [255, 154, 106, 65], [178, 108, 74, 45], [139, 84, 58, 36]],
        // Version 7
        [[370, 224, 154, 95], [293, 178, 122, 75], [207, 125, 86, 53], [154, 93, 64, 39]],
        // Version 8
        [[461, 279, 192, 118], [365, 221, 152, 93], [259, 157, 108, 66], [202, 122, 84, 52]],
        // Version 9
        [[552, 335, 230, 141], [432, 262, 180, 111], [312, 189, 130, 80], [235, 143, 98, 60]],
        // Version 10
        [[652, 395, 271, 167], [513, 311, 213, 131], [364, 221, 151, 93], [288, 174, 119, 74]],
        // ... (versions 11-40 would continue)
    ];

    public function __construct()
    {
        $this->analyzer = new DataAnalyzer();
        $this->dataEncoder = new DataEncoder();
        $this->reedSolomon = new ReedSolomon();
        $this->matrixBuilder = new MatrixBuilder();
        $this->maskSelector = new MaskSelector();
    }

    public function encode(
        string $data,
        ErrorCorrectionLevel $errorCorrectionLevel,
        int $requestedVersion = 0,
        ?Mode $forcedMode = null
    ): Matrix {
        if (empty($data)) {
            throw InvalidDataException::emptyData();
        }

        // Determine encoding mode
        $mode = $forcedMode ?? $this->analyzer->analyze($data);

        if ($forcedMode !== null && !$this->isDataCompatible($data, $forcedMode)) {
            throw InvalidDataException::incompatibleMode($forcedMode->name, $data);
        }

        // Determine version
        $version = $this->determineVersion($data, $mode, $errorCorrectionLevel, $requestedVersion);

        // Encode data
        $encodedData = $this->dataEncoder->encode($data, $mode, $version);

        // Calculate total capacity
        $totalCapacity = $this->getTotalDataCodewords($version, $errorCorrectionLevel);

        // Add terminator and padding
        $encodedData = $this->dataEncoder->addTerminatorAndPadding($encodedData, $totalCapacity);

        // Calculate ECC
        $eccCount = $this->reedSolomon->getEccCount($version, $errorCorrectionLevel->value);
        $ecc = $this->reedSolomon->encode($encodedData, $eccCount);

        $allCodewords = array_merge($encodedData, $ecc);

        // Build base matrix with function patterns (format info uses mask=0 placeholder)
        $matrix = $this->matrixBuilder->buildBase($version, $errorCorrectionLevel, 0);

        // Place data WITHOUT mask directly into base matrix (no clone — base is not reused)
        $this->matrixBuilder->placeDataUnmaskedInPlace($matrix, $allCodewords, $errorCorrectionLevel);

        // Select best mask (evaluates all 8 masks with correct format info per mask)
        $maskPattern = $this->maskSelector->selectBestMask($matrix, $errorCorrectionLevel);

        // Apply chosen mask in-place using cached int-packed XOR rows (no clone needed)
        $maskXorRows = $this->maskSelector->getMaskXorRows($version, $maskPattern);
        $this->matrixBuilder->applyMaskInPlace($matrix, $maskPattern, $errorCorrectionLevel, $maskXorRows);

        return $matrix;
    }

    public function getMinimumVersion(
        string $data,
        ErrorCorrectionLevel $errorCorrectionLevel,
        ?Mode $forcedMode = null
    ): int {
        $mode = $forcedMode ?? $this->analyzer->analyze($data);
        $modeIndex = match ($mode) {
            Mode::Numeric => 0,
            Mode::Alphanumeric => 1,
            Mode::Byte => 2,
            Mode::Kanji => 3,
        };

        $dataLength = $this->analyzer->getDataLength($data, $mode);
        $eccIndex = $errorCorrectionLevel->value;

        for ($version = 1; $version <= 40; $version++) {
            if ($version <= 10) {
                $capacity = $this->capacityTable[$version - 1][$eccIndex][$modeIndex];
            } else {
                $capacity = $this->estimateCapacity($version, $eccIndex, $modeIndex);
            }

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
        Mode $mode,
        ErrorCorrectionLevel $errorCorrectionLevel,
        int $requestedVersion
    ): int {
        $minimumVersion = $this->getMinimumVersion($data, $errorCorrectionLevel, $mode);

        if ($requestedVersion === 0) {
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

    private function isDataCompatible(string $data, Mode $mode): bool
    {
        return match ($mode) {
            Mode::Numeric => $this->analyzer->isNumeric($data),
            Mode::Alphanumeric => $this->analyzer->isAlphanumeric($data),
            Mode::Kanji => $this->analyzer->isKanji($data),
            Mode::Byte => true,
        };
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

    private function estimateCapacity(int $version, int $eccIndex, int $modeIndex): int
    {
        $baseCapacity = $this->capacityTable[9][$eccIndex][$modeIndex];
        $growthFactor = 1 + ($version - 10) * 0.15;
        return (int) ($baseCapacity * $growthFactor);
    }
}
