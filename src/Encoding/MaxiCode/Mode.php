<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\MaxiCode;

/**
 * What a MaxiCode symbol is for, which decides what its primary message holds.
 *
 * The mode is not an encoding preference — it changes the meaning of the ten
 * codewords nearest the bullseye. In modes 2 and 3 those carry a structured
 * carrier message: a postcode, a three-digit country and a three-digit service
 * class, packed as bit fields so a scanner can route a parcel from the middle
 * of a symbol it has not finished reading. In modes 4 and 6 the same codewords
 * are the first nine codewords of the payload and nothing more.
 *
 * That is also why the two structured modes hold less: their nine primary
 * codewords are spent on the routing block, leaving the payload the 84
 * codewords of the secondary message rather than all 93.
 */
enum Mode: int
{
    /** Structured carrier message with a numeric postcode of up to nine digits. */
    case NumericPostcode = 2;

    /** Structured carrier message with a postcode of six characters. */
    case AlphanumericPostcode = 3;

    /** A plain symbol: the whole payload, standard error correction. */
    case Standard = 4;

    /**
     * Reader programming: the payload configures the scanner rather than
     * describing anything. A symbol a device is meant to obey, so it is here
     * for completeness and is not something to reach for by accident.
     */
    case ReaderProgramming = 6;

    /** Whether this mode's primary message is a structured carrier message. */
    public function isStructured(): bool
    {
        return $this === self::NumericPostcode || $this === self::AlphanumericPostcode;
    }

    /** How many data codewords the payload may use in this mode. */
    public function capacity(): int
    {
        return $this->isStructured()
            ? Specs::SECONDARY_DATA_CODEWORDS
            : Specs::DATA_CODEWORDS;
    }
}
