<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Encoding\MicroQr;

use CrazyGoat\ScanMePHP\Encoding\Mode;
use CrazyGoat\ScanMePHP\Encoding\Segmentation;

/**
 * What a segment header costs at each Micro QR version, and nothing else.
 *
 * The search itself lives in {@see Segmentation}, because it is the same
 * search for every symbology in the QR family. What is peculiar to Micro QR is
 * only the price list: the mode indicator is nought, one, two or three bits
 * depending on the version, the character count is a different width in every
 * version *and* every mode, and three of the four versions cannot be in some
 * modes at all. All of that is one function from a mode to a number of bits,
 * which is what this class is.
 *
 * @internal Part of the Micro QR encoding pipeline.
 */
final class Segments
{
    /**
     * The cheapest split of $data for a symbol of this version, or an empty
     * list where the version cannot carry the payload at all.
     *
     * @return list<array{Mode, string}>
     */
    public static function optimal(string $data, int $version): array
    {
        return Segmentation::optimal($data, self::header($version));
    }

    /**
     * What the cheapest split costs, header bits included, or PHP_INT_MAX
     * where this version cannot carry the payload.
     */
    public static function bits(string $data, int $version): int
    {
        return Segmentation::bits($data, self::header($version));
    }

    public static function covers(Mode $mode, string $character): bool
    {
        return Segmentation::covers($mode, $character);
    }

    /** @return callable(Mode): ?int */
    private static function header(int $version): callable
    {
        return static fn (Mode $mode): ?int => Specs::supportsMode($version, $mode)
            ? Specs::modeBits($version) + Specs::countBits($version, $mode)
            : null;
    }
}
