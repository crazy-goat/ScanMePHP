<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Codabar;

/**
 * The four characters Codabar can open and close a symbol with.
 *
 * They carry no data — a scanner reports them, but nothing in the payload
 * depends on which pair is used. What they are for is telling one application
 * apart from another on the same scanner: libraries conventionally use A…B or
 * A…A, and blood banks assign meaning to the pair so a bag label cannot be
 * read as a donor record.
 *
 * The same four patterns are written T, N, * and E in some older
 * documentation, which is why fromName() accepts both spellings. They are the
 * same bars, not a variant — a scanner reporting 'A123A' and a manual calling
 * it 'T123T' are describing one symbol.
 */
enum Delimiter: string
{
    case A = 'A';

    case B = 'B';

    case C = 'C';

    case D = 'D';

    /** The alternative spelling of each, in the same order as cases(). */
    public const ALTERNATIVES = ['T', 'N', '*', 'E'];

    /**
     * A delimiter from either spelling, case-insensitively.
     *
     * @throws \InvalidArgumentException on anything that is not one of the four
     */
    public static function fromName(string $name): self
    {
        $upper = strtoupper($name);

        $alternative = array_search($upper, self::ALTERNATIVES, true);
        if ($alternative !== false) {
            return self::cases()[$alternative];
        }

        return self::tryFrom($upper) ?? throw new \InvalidArgumentException(sprintf(
            'A Codabar delimiter is one of A, B, C, D (also written T, N, *, E), got: %s',
            $name
        ));
    }

    /** The alternative spelling of this delimiter. */
    public function alternative(): string
    {
        return self::ALTERNATIVES[array_search($this, self::cases(), true)];
    }
}
