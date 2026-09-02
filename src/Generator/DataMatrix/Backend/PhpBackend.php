<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataMatrix\Backend;

use CrazyGoat\ScanMePHP\Encoding\ReedSolomon256;
use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\AsciiEncodation;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\DataMatrixOptions;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\Placement;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\Specs;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\SymbolSpec;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Data Matrix ECC200 in pure PHP.
 *
 * Reuses the shared GF(2^8) Reed–Solomon encoder, configured with ECC200's own
 * primitive polynomial and generator base — the arithmetic is the same as QR's,
 * the parameters are not.
 */
final class PhpBackend implements BackendInterface
{
    /** Minimum blank margin around the symbol, in modules (ISO/IEC 16022 §5.3). */
    private const QUIET_ZONE = 1;

    private ?ReedSolomon256 $reedSolomon = null;

    public function getName(): string
    {
        return 'php';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function encode(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        $options = $options instanceof DataMatrixOptions ? $options : new DataMatrixOptions();

        $codewords = AsciiEncodation::encode($data);
        $spec = $this->chooseSpec($codewords, $options);

        $codewords = AsciiEncodation::pad($codewords, $spec->dataWords);
        $codewords = $this->appendErrorCorrection($codewords, $spec);

        $mapping = Placement::place($codewords, $spec->mappingRows(), $spec->mappingCols());

        return new Symbol(
            width: $spec->cols,
            height: $spec->rows,
            modules: implode('', $this->assemble($mapping, $spec)),
            quietZone: QuietZone::uniform(self::QUIET_ZONE),
            metadata: [
                'symbology' => Symbology::DataMatrix->value,
                'size' => $spec->name(),
                'dataCodewords' => $spec->dataWords,
                'errorCodewords' => $spec->eccWords,
            ],
        );
    }

    /** @param list<int> $codewords */
    private function chooseSpec(array $codewords, DataMatrixOptions $options): SymbolSpec
    {
        $needed = \count($codewords);

        if ($options->size !== null) {
            $spec = Specs::byName($options->size)
                ?? throw new \InvalidArgumentException(sprintf(
                    'Unknown Data Matrix size "%s"',
                    $options->size
                ));

            if ($spec->dataWords < $needed) {
                throw DataTooLargeException::forSymbolSize($needed, $spec->dataWords, $spec->name());
            }

            return $spec;
        }

        return Specs::smallestFor($needed, $options->rectangular)
            ?? throw DataTooLargeException::forSymbolSize(
                $needed,
                $options->rectangular ? 49 : Specs::maxDataWords(),
                $options->rectangular ? 'the largest rectangular symbol (16x48)' : 'the largest symbol (144x144)'
            );
    }

    /**
     * Append the interleaved error correction codewords.
     *
     * Larger symbols split the data into blocks so that a single burst of
     * damage cannot exhaust one block's correction capacity. The data
     * codewords keep their order and each block is gathered by striding
     * through them; only the error codewords are interleaved.
     *
     * @param list<int> $codewords
     * @return list<int>
     */
    private function appendErrorCorrection(array $codewords, SymbolSpec $spec): array
    {
        $this->reedSolomon ??= ReedSolomon256::forDataMatrix();
        $blocks = $spec->blocks;
        $eccPerBlock = $spec->eccPerBlock();

        if ($blocks === 1) {
            return array_merge($codewords, $this->reedSolomon->encode($codewords, $eccPerBlock));
        }

        $output = array_pad($codewords, $spec->totalWords(), 0);

        for ($block = 0; $block < $blocks; $block++) {
            $blockData = [];
            for ($position = $block; $position < $spec->dataWords; $position += $blocks) {
                $blockData[] = $codewords[$position];
            }

            $ecc = $this->reedSolomon->encode($blockData, $eccPerBlock);
            foreach ($ecc as $index => $codeword) {
                $output[$spec->dataWords + $block + $index * $blocks] = $codeword;
            }
        }

        return $output;
    }

    /**
     * Wrap the mapping matrix in the finder patterns.
     *
     * Every data region gets a solid dark L down its left side and along its
     * bottom, which is what a scanner locates and uses for orientation, plus an
     * alternating clock track along the top and right that tells it the module
     * pitch. Symbols with several regions repeat the whole frame per region.
     *
     * @param list<string> $mapping
     * @return list<string>
     */
    private function assemble(array $mapping, SymbolSpec $spec): array
    {
        $blockRows = $spec->regionRows + 2;
        $blockCols = $spec->regionCols + 2;

        $rows = [];
        for ($row = 0; $row < $spec->rows; $row++) {
            $inBlockRow = $row % $blockRows;
            $mappingRow = intdiv($row, $blockRows) * $spec->regionRows + $inBlockRow - 1;

            $line = '';
            for ($col = 0; $col < $spec->cols; $col++) {
                $inBlockCol = $col % $blockCols;

                $line .= match (true) {
                    $inBlockRow === $blockRows - 1 => '1',                      // solid bottom
                    $inBlockCol === 0 => '1',                                   // solid left
                    $inBlockRow === 0 => $inBlockCol % 2 === 0 ? '1' : '0',      // top clock track
                    $inBlockCol === $blockCols - 1 => $inBlockRow % 2 === 1 ? '1' : '0', // right clock track
                    default => $mapping[$mappingRow][
                        intdiv($col, $blockCols) * $spec->regionCols + $inBlockCol - 1
                    ],
                };
            }

            $rows[] = $line;
        }

        return $rows;
    }
}
