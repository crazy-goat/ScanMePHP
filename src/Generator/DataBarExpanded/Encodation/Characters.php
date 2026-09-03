<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\DataBarExpanded\Encodation;

use CrazyGoat\ScanMePHP\Generator\DataBar\Patterns;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;

/**
 * A payload's symbol characters, the check character in front of them.
 *
 * The step both DataBar Expanded generators share: values out of the bit
 * stream, widths out of the values, and a check character whose value is
 * 211 x (data characters - 3) plus the checksum residue — which is why the
 * check character can exceed 4000 while a data character cannot.
 *
 * @internal
 */
final class Characters
{
    private function __construct(
        /** @var list<list<int>> every character's widths, the check character first */
        public readonly array $widths,
        /** The checksum residue, modulo 211. */
        public readonly int $checksum,
        /** The check character's own twelve-bit value. */
        public readonly int $checkCharacter,
    ) {
    }

    /**
     * @param int $charactersPerRow 0 for the linear symbol, or a stacked
     *        symbol's row width — it can add a character, and the checksum is
     *        computed over the count it produces
     */
    public static function of(ElementString $elements, int $charactersPerRow = 0): self
    {
        $data = array_map(
            static fn (int $value): array => Patterns::character($value, Patterns::EXPANDED, false),
            Encodation::values($elements, $charactersPerRow)
        );

        $checksum = Patterns::expandedChecksum($data);
        $check = Patterns::EXPANDED_MODULUS * (\count($data) - 3) + $checksum;

        return new self(
            [Patterns::character($check, Patterns::EXPANDED, false), ...$data],
            $checksum,
            $check,
        );
    }
}
