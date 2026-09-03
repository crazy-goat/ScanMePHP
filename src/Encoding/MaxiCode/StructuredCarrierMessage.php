<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\MaxiCode;

/**
 * The routing block modes 2 and 3 put in the primary message.
 *
 * Fifty-six bits, packed least significant first, starting at bit 4 of the mode
 * codeword and running through the nine primary data codewords:
 *
 *     postcode  30 bits
 *     length     6 bits   (mode 2 only; mode 3 spends them on a sixth character)
 *     country   10 bits
 *     service   10 bits
 *
 * The two spare bits of the mode codeword are the low two bits of the postcode,
 * which is the detail an implementation is most likely to miss: the mode
 * codeword is only four bits of mode, and treating it as six loses the two
 * least significant digits' worth of postcode without any other symptom.
 *
 * Mode 2's postcode is one number of up to nine digits, with the digit count in
 * the length field — 999999999 is under 2^30, so it always fits. Mode 3's is
 * six characters from code set A occupying the postcode and length fields
 * together, **least significant group first**, so the *last* character of the
 * postcode is the one nearest the mode codeword.
 */
final class StructuredCarrierMessage
{
    public const POSTCODE_BITS = 30;

    public const LENGTH_BITS = 6;

    public const COUNTRY_BITS = 10;

    public const SERVICE_BITS = 10;

    public const ALPHANUMERIC_POSTCODE_LENGTH = 6;

    public const MAX_NUMERIC_POSTCODE_DIGITS = 9;

    /** The country and service class are three-digit ISO codes. */
    public const MAX_CODE = 999;

    /**
     * The ten primary codewords, mode codeword first.
     *
     * @return list<int>
     */
    public static function primary(Mode $mode, string $postcode, int $country, int $service): array
    {
        self::check($mode, $postcode, $country, $service);

        $bits = $mode === Mode::NumericPostcode
            ? self::numericPostcode($postcode)
            : self::alphanumericPostcode($postcode);
        $bits .= self::field($country, self::COUNTRY_BITS);
        $bits .= self::field($service, self::SERVICE_BITS);

        // The stream's first two bits ride in the mode codeword's spare high
        // bits; everything after that is six bits per data codeword.
        $codewords = [$mode->value | self::valueOf(substr($bits, 0, 2)) << 4];
        for ($offset = 2; $offset < \strlen($bits); $offset += Specs::CODEWORD_BITS) {
            $codewords[] = self::valueOf(substr($bits, $offset, Specs::CODEWORD_BITS));
        }

        return $codewords;
    }

    /** The value of a run of bits written least significant first. */
    private static function valueOf(string $bits): int
    {
        $value = 0;
        for ($bit = 0; $bit < \strlen($bits); $bit++) {
            $value |= ($bits[$bit] === '1' ? 1 : 0) << $bit;
        }

        return $value;
    }

    /** Least significant bit first, as a string of '0' and '1'. */
    private static function field(int $value, int $bits): string
    {
        $out = '';
        for ($bit = 0; $bit < $bits; $bit++) {
            $out .= ($value >> $bit) & 1 ? '1' : '0';
        }

        return $out;
    }

    private static function numericPostcode(string $postcode): string
    {
        return self::field((int) $postcode, self::POSTCODE_BITS)
            . self::field(\strlen($postcode), self::LENGTH_BITS);
    }

    /**
     * Six characters, the last one in the lowest bits.
     *
     * A shorter postcode is padded with spaces on the right, which is what the
     * field is: six positions, not a string of six.
     */
    private static function alphanumericPostcode(string $postcode): string
    {
        $padded = str_pad($postcode, self::ALPHANUMERIC_POSTCODE_LENGTH);

        $bits = '';
        for ($i = self::ALPHANUMERIC_POSTCODE_LENGTH - 1; $i >= 0; $i--) {
            $value = CodeSets::value(CodeSets::A, $padded[$i]);
            if ($value === null) {
                throw new \InvalidArgumentException(sprintf(
                    'A mode 3 postcode holds only code set A characters; %s is not one',
                    var_export($padded[$i], true),
                ));
            }
            $bits .= self::field($value, Specs::CODEWORD_BITS);
        }

        return $bits;
    }

    private static function check(Mode $mode, string $postcode, int $country, int $service): void
    {
        if (!$mode->isStructured()) {
            throw new \InvalidArgumentException(sprintf(
                'Mode %d has no structured carrier message',
                $mode->value,
            ));
        }

        if ($mode === Mode::NumericPostcode) {
            if ($postcode === '' || ctype_digit($postcode) === false) {
                throw new \InvalidArgumentException(sprintf(
                    'A mode 2 postcode is digits only, got %s',
                    var_export($postcode, true),
                ));
            }
            if (\strlen($postcode) > self::MAX_NUMERIC_POSTCODE_DIGITS) {
                throw new \InvalidArgumentException(sprintf(
                    'A mode 2 postcode holds at most %d digits, got %d',
                    self::MAX_NUMERIC_POSTCODE_DIGITS,
                    \strlen($postcode),
                ));
            }
        } elseif (\strlen($postcode) > self::ALPHANUMERIC_POSTCODE_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'A mode 3 postcode holds at most %d characters, got %d',
                self::ALPHANUMERIC_POSTCODE_LENGTH,
                \strlen($postcode),
            ));
        }

        foreach (['country' => $country, 'service class' => $service] as $name => $value) {
            if ($value < 0 || $value > self::MAX_CODE) {
                throw new \InvalidArgumentException(sprintf(
                    'The %s code is a three-digit number, got %d',
                    $name,
                    $value,
                ));
            }
        }
    }
}
