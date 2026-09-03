<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\AustraliaPost;

/**
 * A sorting code, and whatever the sender put after it.
 *
 * Every Australia Post symbol begins with the eight-digit Delivery Point
 * Identifier — the sorting code — and the Standard Customer Barcode may then
 * carry a customer information field, which the standard sizes in *bars* and
 * not in characters: sixteen of them, or thirty-one.
 *
 * That is why the lengths this class accepts look arbitrary and are not. A
 * sixteen-bar field is eight N-table digits exactly, or five C-table
 * characters and a filler bar; a thirty-one-bar field is fifteen digits and a
 * filler, or ten characters and a filler. Nothing else fills a field, and the
 * length alone says which table is in use — the symbol does not record it, so
 * a field of five digits is C-table text and a field of eight is N-table
 * numbers, whatever the digits happen to be.
 *
 * Shorter fields are refused rather than padded. Padding is not ours to
 * invent: the customer information field has no reader outside the mailer who
 * wrote it, and filler bars in the middle of a C-table field are indexed by
 * the table as lower-case letters, so a field padded out to width would be a
 * symbol saying something we made up.
 */
final readonly class Payload
{
    /** The Delivery Point Identifier, in digits. */
    public const SORTING_CODE_LENGTH = 8;

    /** A customer field carries nothing at all, or one of these widths. */
    public const CUSTOMER_FIELD_BARS = [0 => 1, 5 => 16, 8 => 16, 10 => 31, 15 => 31];

    /** The customer field lengths the N table fills; the rest are C table. */
    public const NUMERIC_LENGTHS = [8, 15];

    private function __construct(
        public string $sortingCode,
        public string $customerInformation,
        public Format $format,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the data is not encodable
     */
    public static function of(string $data, Format $format = Format::Standard): self
    {
        $sortingCode = substr($data, 0, self::SORTING_CODE_LENGTH);
        $customer = substr($data, self::SORTING_CODE_LENGTH);

        if (\strlen($sortingCode) !== self::SORTING_CODE_LENGTH || strspn($sortingCode, Bars::DIGITS) !== self::SORTING_CODE_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Australia Post starts with an eight-digit sorting code, got "%s"',
                $data
            ));
        }

        if ($customer !== '' && !$format->carriesCustomerInformation()) {
            throw new \InvalidArgumentException(sprintf(
                'the %s barcode is a sorting code and nothing else, got %d characters after it',
                $format->value,
                \strlen($customer)
            ));
        }

        if (!isset(self::CUSTOMER_FIELD_BARS[\strlen($customer)])) {
            throw new \InvalidArgumentException(sprintf(
                'customer information fills a field exactly: %s characters, got %d',
                implode(', ', array_slice(array_keys(self::CUSTOMER_FIELD_BARS), 1)),
                \strlen($customer)
            ));
        }

        if (self::isNumeric(\strlen($customer))) {
            if (strspn($customer, Bars::DIGITS) !== \strlen($customer)) {
                throw new \InvalidArgumentException(sprintf(
                    'a %d-character customer field is filled from the N table and carries digits only, got "%s"',
                    \strlen($customer),
                    $customer
                ));
            }
        } elseif (!Bars::covers($customer)) {
            throw new \InvalidArgumentException(sprintf(
                'the Australia Post C table carries digits, letters, the space and the hash only, got "%s"',
                $customer
            ));
        }

        return new self($sortingCode, $customer, $format);
    }

    /** Whether a customer field of this length is filled from the N table. */
    public static function isNumeric(int $length): bool
    {
        return \in_array($length, self::NUMERIC_LENGTHS, true);
    }

    /** How many bars the customer information field is wide. */
    public function customerFieldBars(): int
    {
        return self::CUSTOMER_FIELD_BARS[\strlen($this->customerInformation)];
    }

    /**
     * The Format Control Code this payload is drawn under.
     *
     * The caller's format answers for everything but the two wider Standard
     * codes, which are not a choice: a customer field of sixteen bars is FCC
     * 59 and one of thirty-one is 62, because the field's width is the only
     * thing that tells a reader where the parity begins.
     */
    public function formatControlCode(): string
    {
        if (!$this->format->carriesCustomerInformation()) {
            return $this->format->code();
        }

        return match ($this->customerFieldBars()) {
            16 => '59',
            31 => '62',
            default => $this->format->code(),
        };
    }

    /**
     * The customer information field as bars, padded to its width.
     *
     * Padding is the one filler bar the standard puts at the end of a field
     * whose table does not divide it: five C-table characters are fifteen bars
     * in a sixteen-bar field, and the Standard Customer Barcode's field is a
     * single filler and nothing else. It is never more than one bar per field
     * here, because {@see of()} refuses the lengths that would need more.
     *
     * @return list<int>
     */
    public function customerBars(): array
    {
        $numeric = self::isNumeric(\strlen($this->customerInformation));
        $bars = [];

        foreach (str_split($this->customerInformation) as $character) {
            $bars = [...$bars, ...($numeric ? Bars::numeric($character) : Bars::character($character))];
        }

        return array_pad($bars, $this->customerFieldBars(), Bars::FILLER);
    }

    /** Whether $data is a payload this symbology can carry. */
    public static function accepts(string $data, Format $format = Format::Standard): bool
    {
        try {
            self::of($data, $format);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }
}
