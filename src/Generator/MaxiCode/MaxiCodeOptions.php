<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\MaxiCode;

use CrazyGoat\ScanMePHP\Encoding\MaxiCode\Mode;
use CrazyGoat\ScanMePHP\Encoding\MaxiCode\StructuredCarrierMessage;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * What MaxiCode encoding can be told to do.
 *
 * There is no size to choose and no error correction level to trade off — a
 * MaxiCode symbol is always 33 rows of hexagons and always carries the same
 * amount of recovery data. What there is instead is the mode, and the mode is a
 * real decision rather than a preference: modes 2 and 3 spend the nine
 * codewords nearest the bullseye on a routing block so a scanner can read a
 * parcel's destination from the middle of a symbol it has not finished, which
 * is the reason the symbology exists. Those two modes therefore hold 84
 * codewords of payload where the plain mode holds 93.
 *
 * The routing block belongs here and not in the payload, because it is not
 * payload: it is three separate fields with their own widths and rules, and a
 * caller who had to pack them into the data string would be reimplementing
 * {@see StructuredCarrierMessage} by hand.
 */
final class MaxiCodeOptions implements GeneratorOptionsInterface
{
    /**
     * @param Mode $mode Which primary message the symbol carries. The default
     *        is the plain symbol; reach for {@see Mode::NumericPostcode} or
     *        {@see Mode::AlphanumericPostcode} when the symbol is a shipping
     *        label and the postcode has to be readable on its own.
     * @param string $postcode The destination postcode, for the two structured
     *        modes. Up to nine digits in mode 2; up to six characters, padded
     *        with spaces, in mode 3.
     * @param int $country The ISO 3166-1 numeric country code, 0 to 999.
     * @param int $service The carrier's service class, 0 to 999. What the
     *        numbers mean is the carrier's business, not the standard's.
     */
    public function __construct(
        public readonly Mode $mode = Mode::Standard,
        public readonly string $postcode = '',
        public readonly int $country = 0,
        public readonly int $service = 0,
    ) {
        if (!$mode->isStructured()) {
            if ($postcode !== '' || $country !== 0 || $service !== 0) {
                throw new \InvalidArgumentException(sprintf(
                    'MaxiCode mode %d has no room for a postcode, country or service class; '
                    . 'those need mode %d or %d',
                    $mode->value,
                    Mode::NumericPostcode->value,
                    Mode::AlphanumericPostcode->value,
                ));
            }

            return;
        }

        if ($postcode === '') {
            throw new \InvalidArgumentException(sprintf(
                'MaxiCode mode %d is a structured carrier message and needs a postcode',
                $mode->value,
            ));
        }

        // Validated here as well as at encoding time so a bad option bag is
        // rejected where it was written rather than one call later.
        StructuredCarrierMessage::primary($mode, $postcode, $country, $service);
    }

    /**
     * The ten primary codewords this bag describes, or null in a plain mode.
     *
     * @return list<int>|null
     */
    public function primaryMessage(): ?array
    {
        return $this->mode->isStructured()
            ? StructuredCarrierMessage::primary($this->mode, $this->postcode, $this->country, $this->service)
            : null;
    }
}
