<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\MicroQr;

use CrazyGoat\ScanMePHP\Encoding\MicroQr\Specs;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;

/**
 * What Micro QR encoding can be told to do.
 *
 * The one thing that differs from {@see \CrazyGoat\ScanMePHP\Generator\Qr\QrOptions}
 * is the default error correction level, and the difference is not a
 * preference — it is that Micro QR does not offer the same four levels at
 * every size. M1 offers none at all, M2 and M3 offer L and M, and only M4 adds
 * Q; H does not exist anywhere in the symbology. So the default here is null,
 * meaning *the strongest level the symbol that fits can give this payload*,
 * rather than a named level that three of the four versions would have to
 * refuse. Naming a level is still allowed and then it is honoured exactly: the
 * encoder will reach for a larger version rather than quietly hand back a
 * weaker one.
 */
final class MicroQrOptions implements GeneratorOptionsInterface
{
    public const MIN_MASK = 0;

    public const MAX_MASK = Specs::MASKS - 1;

    /**
     * @param ErrorCorrectionLevel|null $errorCorrection null, the default,
     *        takes the strongest level available in the smallest symbol that
     *        fits. High is never available and is refused here rather than at
     *        encoding time.
     * @param Version|null $version Force one of the four sizes instead of using
     *        the smallest that fits. Encoding fails if the data does not fit.
     * @param int|null $mask Force one of the four mask patterns (0–3) instead
     *        of scoring them. Micro QR's four are QR's patterns 1, 4, 6 and 7
     *        renumbered, so a mask number carried over from a QR symbol names
     *        a different pattern here.
     */
    public function __construct(
        public readonly ?ErrorCorrectionLevel $errorCorrection = null,
        public readonly ?Version $version = null,
        public readonly ?int $mask = null,
    ) {
        if ($errorCorrection === ErrorCorrectionLevel::High) {
            throw new \InvalidArgumentException(
                'Micro QR has no error correction level H; the levels are L, M and Q, '
                . 'and only M4 offers Q',
            );
        }

        // A pinned version and a pinned level can contradict each other, and
        // the contradiction is worth catching here rather than at encoding
        // time: M1 takes no level and M2 and M3 have no Q, so the two most
        // natural things to write -- M1 at level L, M3 at level Q -- are both
        // impossible. Pinning only one of the two is always fine; the encoder
        // fills in the other.
        if ($version instanceof \CrazyGoat\ScanMePHP\Generator\MicroQr\Version && $errorCorrection instanceof \CrazyGoat\ScanMePHP\ErrorCorrectionLevel && !$version->supports($errorCorrection)) {
            throw new \InvalidArgumentException($version->levels() === []
                ? sprintf(
                    'Micro QR %s takes no error correction level; it detects errors rather than '
                    . 'correcting them, so %s cannot be asked for',
                    $version->name,
                    $errorCorrection->name,
                )
                : sprintf(
                    'Micro QR %s does not offer error correction level %s; it offers %s',
                    $version->name,
                    $errorCorrection->name,
                    implode(', ', array_map(
                        static fn (ErrorCorrectionLevel $level): string => $level->name,
                        $version->levels(),
                    )),
                ));
        }

        if ($mask !== null && ($mask < self::MIN_MASK || $mask > self::MAX_MASK)) {
            throw new \InvalidArgumentException(sprintf(
                'Micro QR mask pattern must be between %d and %d, got %d',
                self::MIN_MASK,
                self::MAX_MASK,
                $mask,
            ));
        }
    }
}
