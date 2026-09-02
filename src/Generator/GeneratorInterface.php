<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator;

use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * Encodes data into a Symbol for one symbology.
 *
 * Implementations are registered by name and are free to live outside this
 * library. A generator owns its backend selection: it decides which of its
 * encoding implementations the current host can run and picks the fastest,
 * rather than the facade or the caller reasoning about extensions and FFI.
 */
interface GeneratorInterface
{
    public function getCapabilities(): GeneratorCapabilities;

    /**
     * Whether this generator can encode the given data.
     *
     * Cheap and total: no exceptions for unencodable input, so a caller can
     * probe several symbologies. generate() may still fail for reasons this
     * cannot see, such as data exceeding capacity at a forced size.
     */
    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool;

    public function generate(string $data, ?GeneratorOptionsInterface $options = null): Symbol;

    /**
     * The backend that would encode right now, for introspection and
     * benchmarking. Null when the host can run none of them.
     */
    public function getActiveBackend(): ?BackendInterface;
}
