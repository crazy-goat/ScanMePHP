<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\FourState;

/**
 * What one bar of a four-state postal code can be.
 *
 * Every bar crosses the tracker band. What distinguishes them is whether it
 * also reaches up into the ascender band, down into the descender band, both,
 * or neither — so a bar carries two bits, not four states, and the symbologies
 * in this family are built by choosing those two bits separately: RM4SCC picks
 * a four-bar ascender pattern and a four-bar descender pattern out of the same
 * six, and the character is which pair it picked.
 *
 * The letters are zint's, from its DAFT symbology, which takes a string of
 * them verbatim. Keeping them means `tools/four_state.py` can read a symbol
 * back out of a drawing and compare it with what we write, in the alphabet
 * both sides already speak.
 */
enum State: string
{
    case Descender = 'D';

    case Ascender = 'A';

    case Full = 'F';

    case Tracker = 'T';

    public static function of(bool $ascender, bool $descender): self
    {
        return match (true) {
            $ascender && $descender => self::Full,
            $ascender => self::Ascender,
            $descender => self::Descender,
            default => self::Tracker,
        };
    }

    public function hasAscender(): bool
    {
        return $this === self::Full || $this === self::Ascender;
    }

    public function hasDescender(): bool
    {
        return $this === self::Full || $this === self::Descender;
    }
}
