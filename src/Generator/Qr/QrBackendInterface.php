<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Qr;

use CrazyGoat\ScanMePHP\Generator\BackendInterface;

/**
 * A QR encoding backend, with the limits that differ between ours.
 *
 * All of them are real and none is expressible in the generic
 * BackendInterface: the C++ core reached through the extension and through FFI
 * exposes only `encode(data, len, ecl)`, so it can be told neither which
 * symbol version nor which mask pattern to use, and the bitset encoder can
 * only be pinned to versions up to 27 and inlines its own mask scoring.
 */
interface QrBackendInterface extends BackendInterface
{
    /** Whether this backend can encode into a caller-chosen symbol version. */
    public function supportsForcedVersion(): bool;

    /**
     * Highest version this backend can be *told* to produce. Meaningless, and
     * conventionally 0, when supportsForcedVersion() is false.
     *
     * There is no matching limit on the automatic path: a backend that cannot
     * reach a version it needs falls back internally, so picking the fastest
     * available one is always safe when the caller has not forced a version.
     */
    public function getMaxForcedVersion(): int;

    /**
     * Whether this backend can encode with a caller-chosen mask pattern.
     *
     * Separate from the version because the two limits do not coincide: the
     * bitset encoder can be pinned to a version but scores its masks inside
     * an inlined hot path with nowhere to accept one.
     */
    public function supportsForcedMask(): bool;
}
