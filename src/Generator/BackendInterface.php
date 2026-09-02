<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator;

use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * One implementation of a symbology's encoding step.
 *
 * A symbology may have several: QR ships a PHP extension, an FFI binding to
 * the same C++ core, a bitset-based pure-PHP encoder and a portable fallback,
 * all producing identical modules at very different speeds. Which one can run
 * depends on the host — loaded extensions, a usable shared library, 64-bit
 * integers — so the choice is made at runtime, per generator, and is invisible
 * to callers except through introspection.
 *
 * Most symbologies will only ever have one backend, and that is fine: they
 * register a single pure-PHP one and the selector always picks it.
 */
interface BackendInterface
{
    /** Short identifier for introspection and benchmarking, e.g. 'ffi'. */
    public function getName(): string;

    /** Whether this backend can run on the current host, right now. */
    public function isAvailable(): bool;

    /**
     * Selection weight; the available backend with the highest value wins.
     * Faster implementations rank higher, so the ordering reads as a speed
     * ranking rather than a hidden registration order.
     */
    public function getPriority(): int;

    public function encode(string $data, ?GeneratorOptionsInterface $options = null): Symbol;
}
