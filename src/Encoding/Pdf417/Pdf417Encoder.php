<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\Pdf417;

use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;

/**
 * PDF417 end to end: payload in, module rows out.
 *
 * The one decision worth explaining is the shape. A PDF417 symbol's proportions
 * are not determined by its contents: any grid with enough cells will hold the
 * codewords, and the leftovers become pad codewords. So the column count is a
 * preference, in the same way an Aztec symbol's size is, and it is a caller
 * option with a default rather than something derived and asserted. What *is*
 * determined, once the columns and the error correction level are fixed, is the
 * number of rows — the fewest that hold the data — and everything downstream of
 * that is exact.
 *
 * The error correction level is likewise a request. ISO/IEC 15438 recommends
 * one by data size and {@see Specs::recommendedLevel()} implements the
 * recommendation, but the standard's own table runs out past 863 data
 * codewords, and a caller printing on a label that will be scuffed has better
 * information than a table does.
 */
final class Pdf417Encoder
{
    /** Data columns when the caller does not say; see chooseColumns(). */
    public const DEFAULT_COLUMNS = 6;

    private readonly HighLevelEncoder $highLevel;

    private readonly ReedSolomonGf929 $reedSolomon;

    public function __construct()
    {
        $this->highLevel = new HighLevelEncoder();
        $this->reedSolomon = new ReedSolomonGf929();
    }

    /**
     * @param int|null $level Error correction level 0 to 8, or null for the
     *        level ISO/IEC 15438 recommends for this much data
     * @param int|null $columns Data columns, 1 to 30, or null for the default
     * @param int|null $rows The fewest rows to use; the symbol grows past this
     *        if the data needs it. Null lets the data decide entirely.
     * @return array{
     *     matrix: list<list<bool>>,
     *     rows: int,
     *     columns: int,
     *     level: int,
     *     dataCodewords: int,
     *     padCodewords: int,
     * }
     */
    public function encode(string $data, ?int $level = null, ?int $columns = null, ?int $rows = null): array
    {
        $codewords = $this->highLevel->encode($data);
        $level ??= Specs::recommendedLevel(\count($codewords) + 1);
        $checkWords = Specs::checkCodewords($level);
        $columns ??= $this->chooseColumns(\count($codewords) + 1 + $checkWords);

        $needed = \count($codewords) + 1 + $checkWords;
        $rowsNeeded = max(Specs::MIN_ROWS, $rows ?? Specs::MIN_ROWS, (int) ceil($needed / $columns));

        if ($rowsNeeded > Specs::MAX_ROWS || $needed > Specs::MAX_CODEWORDS) {
            throw DataTooLargeException::forSymbolSize(
                $needed,
                min(Specs::MAX_CODEWORDS, Specs::MAX_ROWS * $columns),
                sprintf('%d columns at error correction level %d', $columns, $level),
            );
        }

        $pads = $rowsNeeded * $columns - $needed;
        $region = [$rowsNeeded * $columns - $checkWords, ...$codewords, ...array_fill(0, $pads, HighLevelEncoder::LATCH_TEXT)];
        $stream = [...$region, ...$this->reedSolomon->encode($region, $checkWords)];

        return [
            'matrix' => (new Layout($rowsNeeded, $columns, $level))->build($stream),
            'rows' => $rowsNeeded,
            'columns' => $columns,
            'level' => $level,
            'dataCodewords' => \count($codewords) + 1,
            'padCodewords' => $pads,
        ];
    }

    /** Whether $data fits at all, which for PDF417 is only ever about length. */
    public function canEncode(string $data): bool
    {
        return \strlen($data) <= self::MAX_PAYLOAD_ESTIMATE;
    }

    /**
     * The widest payload worth attempting, as a cheap pre-check.
     *
     * Byte compaction is the worst case at five codewords per six bytes, and
     * the data region holds at most 928 codewords less the length descriptor
     * and the smallest error correction, so no payload past this can fit under
     * any encoding. A payload under it may still fail, which is why the
     * encoder throws rather than this returning a promise.
     */
    private const MAX_PAYLOAD_ESTIMATE = 1108;

    /**
     * The column count for a symbol nobody has specified a shape for.
     *
     * Six is the shape most PDF417 in the wild takes: wide enough that the
     * fixed overhead — start pattern, two row indicators, stop pattern, which
     * cost the width of four data columns in every single row — is a minority
     * of the symbol, and narrow enough to stay printable on a label. A symbol
     * with one data column spends four fifths of its area on structure.
     *
     * The choice only narrows for data too small to fill six columns, so that a
     * short payload does not come out as one very wide row.
     */
    private function chooseColumns(int $codewords): int
    {
        return max(1, min(self::DEFAULT_COLUMNS, (int) ceil($codewords / Specs::MIN_ROWS)));
    }
}
