<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\IntelligentMail;

/**
 * What an Intelligent Mail symbol carries: a tracking code and a routing code.
 *
 * The tracking code is always twenty digits — a two digit barcode identifier,
 * a three digit service type, and then a mailer identifier and a serial number
 * that split the remaining fifteen either six and nine or nine and six. This
 * class does not police that split, because nothing in the symbol depends on
 * it: the two halves are encoded as one run of digits, and which mailer is
 * which is a question for the USPS registry rather than for an encoder.
 *
 * The routing code is the delivery point: nothing, a five digit ZIP, a nine
 * digit ZIP+4, or an eleven digit ZIP+4 with the delivery point appended.
 * Those four lengths are the only ones, and they are not interchangeable —
 * five zeroes and no routing code at all are different symbols, which is why
 * {@see Codewords} offsets each length past every shorter one instead of
 * treating the routing code as a plain number.
 *
 * Written `20digits-11digits` or as one run of digits; the hyphen is the form
 * USPS and zint both print, and it is optional here only because the four
 * total lengths — 20, 25, 29 and 31 — tell the two codes apart on their own.
 */
final readonly class Payload
{
    /** Digits of tracking code, in every symbol. */
    public const TRACKING_LENGTH = 20;

    /** Nothing, a ZIP, a ZIP+4, or a ZIP+4 with the delivery point. */
    public const ROUTING_LENGTHS = [0, 5, 9, 11];

    /**
     * The largest second digit of the barcode identifier.
     *
     * The identifier's second digit encodes the Optional Endorsement Line, and
     * only five of them are defined — which is why {@see Codewords} gives that
     * one digit a place value of five rather than ten. A six here would encode
     * as some other payload's symbol, so it is refused rather than carried.
     */
    public const MAX_ENDORSEMENT = 4;

    private function __construct(
        public string $tracking,
        public string $routing,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the input is not a payload
     */
    public static function of(string $data): self
    {
        $digits = str_contains($data, '-')
            ? implode('', explode('-', $data, 2))
            : $data;

        if ($digits === '' || strspn($digits, '0123456789') !== \strlen($digits)) {
            throw new \InvalidArgumentException(sprintf(
                'Intelligent Mail carries digits only, written 20 of tracking then 0, 5, 9 or 11 of routing, got "%s"',
                $data
            ));
        }

        $routing = \strlen($digits) - self::TRACKING_LENGTH;
        if (!\in_array($routing, self::ROUTING_LENGTHS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Intelligent Mail is %d digits of tracking code and 0, 5, 9 or 11 of routing code, got %d in total',
                self::TRACKING_LENGTH,
                \strlen($digits)
            ));
        }

        if ((int) $digits[1] > self::MAX_ENDORSEMENT) {
            throw new \InvalidArgumentException(sprintf(
                'the second digit of the barcode identifier runs 0 to %d, got %s',
                self::MAX_ENDORSEMENT,
                $digits[1]
            ));
        }

        return new self(
            substr($digits, 0, self::TRACKING_LENGTH),
            substr($digits, self::TRACKING_LENGTH),
        );
    }

    /** Whether $data is a payload this symbology can carry. */
    public static function accepts(string $data): bool
    {
        try {
            self::of($data);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }

    /** The two codes as one run of digits, the way they are encoded. */
    public function digits(): string
    {
        return $this->tracking . $this->routing;
    }
}
