<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Pdf417;

use CrazyGoat\ScanMePHP\Encoding\Pdf417\Specs;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * What PDF417 encoding can be told to do.
 *
 * Three of these four are preferences rather than facts about the data, which
 * is why they are options at all. A PDF417 symbol's proportions are not implied
 * by its contents — any grid with enough cells works and the spare cells become
 * pad codewords — so the column count, and the floor under the row count, are
 * the caller's to choose. So is the error correction level: the standard
 * recommends one by data size, but a label that will be scuffed or partly
 * covered wants more than the recommendation, and the recommendation runs out
 * past 863 data codewords anyway.
 *
 * The fourth, the row height, is presentation. PDF417 rows carry no vertical
 * information — every row is independently readable, which is the point of the
 * format — so their height is only about giving a scanner's sweep something to
 * hit. Three modules is the convention and what readers expect.
 */
final class Pdf417Options implements GeneratorOptionsInterface
{
    /** Rows are conventionally three modules tall; ISO/IEC 15438 §5.8.2. */
    public const DEFAULT_ROW_HEIGHT = 3;

    /**
     * @param int|null $errorCorrectionLevel 0 to 8, each level twice the
     *        previous one's check codewords — two at level 0, 512 at level 8.
     *        Null takes the level ISO/IEC 15438 recommends for the amount of
     *        data, which is what a reader expects to find.
     * @param int|null $columns Data columns, 1 to 30, not counting the start
     *        pattern, the two row indicators and the stop pattern that every
     *        row also carries. Null picks a shape that keeps that fixed
     *        overhead in proportion. Encoding fails if the data cannot fit in
     *        ninety rows of this width.
     * @param int|null $rows The fewest rows to use, 3 to 90. The symbol still
     *        grows past this when the data needs more room — it is a floor for
     *        a caller who wants a minimum printed height, not a fixed size.
     * @param int $rowHeight How many modules tall to make each row.
     */
    public function __construct(
        public readonly ?int $errorCorrectionLevel = null,
        public readonly ?int $columns = null,
        public readonly ?int $rows = null,
        public readonly int $rowHeight = self::DEFAULT_ROW_HEIGHT,
    ) {
        if (
            $errorCorrectionLevel !== null
            && ($errorCorrectionLevel < Specs::MIN_ERROR_CORRECTION_LEVEL
                || $errorCorrectionLevel > Specs::MAX_ERROR_CORRECTION_LEVEL)
        ) {
            throw new \InvalidArgumentException(sprintf(
                'PDF417 error correction levels run from %d to %d, got %d',
                Specs::MIN_ERROR_CORRECTION_LEVEL,
                Specs::MAX_ERROR_CORRECTION_LEVEL,
                $errorCorrectionLevel,
            ));
        }

        if ($columns !== null && ($columns < Specs::MIN_COLUMNS || $columns > Specs::MAX_COLUMNS)) {
            throw new \InvalidArgumentException(sprintf(
                'A PDF417 symbol has %d to %d data columns, got %d',
                Specs::MIN_COLUMNS,
                Specs::MAX_COLUMNS,
                $columns,
            ));
        }

        if ($rows !== null && ($rows < Specs::MIN_ROWS || $rows > Specs::MAX_ROWS)) {
            throw new \InvalidArgumentException(sprintf(
                'A PDF417 symbol has %d to %d rows, got %d',
                Specs::MIN_ROWS,
                Specs::MAX_ROWS,
                $rows,
            ));
        }

        if ($rowHeight < 1) {
            throw new \InvalidArgumentException(sprintf('A row cannot be %d modules tall', $rowHeight));
        }
    }
}
