<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBarExpanded\Encodation;

use CrazyGoat\ScanMePHP\Generator\Ean\Patterns as CheckDigit;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;

/**
 * A GS1 payload turned into DataBar Expanded's twelve-bit data characters.
 *
 * The bit string opens with an encodation method field, and there are two of
 * them here:
 *
 *   000 + 2   general purpose: the whole payload goes through the general field
 *   01  + 2   AI 01 first: its indicator digit in four bits and its item
 *             reference as four ten-bit decimal triples, then everything after
 *             it through the general field
 *
 * The two bits after each are the variable length symbol field — one saying the
 * symbol has an even number of data characters, one saying it has fourteen or
 * more. Neither is redundant: a reader counts characters to know where a symbol
 * ends, and the second bit is what tells it which of the two checksum weighting
 * families to use.
 *
 * Ten bits per three digits is worth pausing on, because it is what makes the
 * AI 01 field 40 bits rather than the 44 a plain binary integer would need, and
 * it is why the characters of a symbol carry decimal-aligned rather than
 * binary-aligned parts of the number.
 *
 * The compaction methods for variable-measure trade items — AI 01 paired with a
 * weight, a price or a date, which the standard gives fourteen more method
 * fields for — are not implemented. Those payloads encode through the AI 01
 * method instead: the symbol is correct and scans to the same data, one or two
 * characters wider than the narrowest a standard-complete encoder would draw.
 *
 * @internal
 */
final class Encodation
{
    /** Bits before the variable length field, general purpose. */
    private const GENERAL_METHOD = '000';

    /** Bits before the variable length field, AI 01 first. */
    private const GTIN_METHOD = '01';

    /** Data characters a general purpose symbol cannot go below. */
    private const MINIMUM_CHARACTERS = 3;

    /** Data characters the AI 01 field alone already fills. */
    private const GTIN_CHARACTERS = 4;

    /** Method, variable length field, indicator digit and item reference. */
    private const GTIN_HEAD_BITS = 48;

    /** The most a symbol can carry. */
    public const MAXIMUM_CHARACTERS = 21;

    /** Digits per ten-bit field of the item reference. */
    private const TRIPLE = 3;

    /**
     * @param int $charactersPerRow how many characters a stacked row holds, or
     *        0 for the linear symbol; see {@see rowFill()}
     * @return list<int> the data characters, each a twelve-bit value
     *
     * @throws \InvalidArgumentException when the payload needs more than a symbol holds
     */
    public static function values(ElementString $elements, int $charactersPerRow = 0): array
    {
        $stream = self::bits($elements, $charactersPerRow);

        $values = [];
        foreach (str_split($stream, 12) as $character) {
            $values[] = (int) bindec($character);
        }

        return $values;
    }

    /**
     * One more data character, when stacking would otherwise leave a row
     * holding a single one.
     *
     * A stacked symbol's rows are filled left to right and the last takes the
     * remainder, and the remainder may not be one character: a row that short
     * carries a character and a finder and nothing to read them against. The
     * cost of avoiding it is one more character of padding, which is also one
     * more character in the count the checksum and the variable length field
     * are computed from — so it has to be decided here, before the bit stream
     * is written, rather than in the layout.
     */
    private static function rowFill(int $characters, int $charactersPerRow): int
    {
        if ($charactersPerRow === 0) {
            return 0;
        }

        return ($characters + 1) % $charactersPerRow === 1 ? 1 : 0;
    }

    private static function bits(ElementString $elements, int $charactersPerRow = 0): string
    {
        $payload = $elements->payload();
        [$ai, $value] = $elements->elements[0];

        if ($ai === '01') {
            self::verifyCheckDigit($value);

            $rest = substr($payload, 2 + \strlen($value));
            $field = GeneralField::encode(ltrim($rest, GeneralField::FNC1));
            $characters = self::characters(
                self::GTIN_HEAD_BITS + $field->shortestLength(),
                self::GTIN_CHARACTERS
            );
            $characters += self::rowFill($characters, $charactersPerRow);

            $head = self::GTIN_METHOD . self::variableLength($characters) . self::field((int) $value[0], 4);
            for ($triple = 0; $triple < 4; $triple++) {
                $head .= self::field((int) substr($value, 1 + self::TRIPLE * $triple, self::TRIPLE), 10);
            }

            return self::pad(
                $head . $field->body(12 * $characters - self::GTIN_HEAD_BITS - \strlen($field->bits)),
                12 * $characters,
                $field->endsNumeric
            );
        }

        $field = GeneralField::encode($payload);
        $head = \strlen(self::GENERAL_METHOD) + 2;
        $characters = self::characters($head + $field->shortestLength(), self::MINIMUM_CHARACTERS);
        $characters += self::rowFill($characters, $charactersPerRow);

        return self::pad(
            self::GENERAL_METHOD . self::variableLength($characters)
                . $field->body(12 * $characters - $head - \strlen($field->bits)),
            12 * $characters,
            $field->endsNumeric
        );
    }

    /**
     * A GTIN's check digit has to be right, because this method does not carry
     * it.
     *
     * The AI 01 field is the indicator digit and twelve digits of item
     * reference; a reader recomputes the fourteenth. So a wrong check digit is
     * not encoded wrongly — it is silently replaced, and the symbol says a
     * number the caller did not write. Every other GS1 symbology here carries
     * the digit as given and reads it back unchanged, which is why this is
     * checked here and not in the payload parser.
     *
     * @throws \InvalidArgumentException
     */
    private static function verifyCheckDigit(string $gtin): void
    {
        $expected = CheckDigit::checkDigit(substr($gtin, 0, 13));

        if ((int) $gtin[13] !== $expected) {
            throw new \InvalidArgumentException(sprintf(
                'The GTIN %s has check digit %s where %d is required; DataBar Expanded does not '
                    . 'carry the digit, so an incorrect one would be silently replaced',
                $gtin,
                $gtin[13],
                $expected
            ));
        }
    }

    private static function characters(int $bits, int $minimum): int
    {
        $characters = max($minimum, intdiv($bits + 11, 12));

        if ($characters > self::MAXIMUM_CHARACTERS) {
            throw new \InvalidArgumentException(sprintf(
                'DataBar Expanded holds %d data characters and this payload needs %d',
                self::MAXIMUM_CHARACTERS,
                $characters
            ));
        }

        return $characters;
    }

    private static function variableLength(int $characters): string
    {
        return ($characters % 2 === 0 ? '1' : '0') . ($characters >= 14 ? '1' : '0');
    }

    /**
     * Fill to the symbol's capacity.
     *
     * A field left in numeric mode latches out of it first, because the filler
     * is a five-bit alphanumeric pattern; '00100' repeated is what a reader
     * recognises as nothing rather than as data.
     */
    private static function pad(string $bits, int $capacity, bool $endsNumeric): string
    {
        if ($endsNumeric && \strlen($bits) < $capacity) {
            $bits .= '0000';
        }

        while (\strlen($bits) < $capacity) {
            $bits .= '00100';
        }

        return substr($bits, 0, $capacity);
    }

    private static function field(int $value, int $bits): string
    {
        return str_pad(decbin($value), $bits, '0', \STR_PAD_LEFT);
    }
}
