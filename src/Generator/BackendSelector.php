<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator;

use CrazyGoat\ScanMePHP\Exception\NoBackendAvailableException;

/**
 * Picks the fastest backend a symbology can actually run here.
 *
 * Generators compose this rather than inheriting it, so a generator written
 * outside this library owes nothing to a base class. Availability is probed
 * once and cached: isAvailable() may dlopen a shared library or stat a file,
 * which must not happen per encode.
 */
final class BackendSelector
{
    /** @var list<BackendInterface> */
    private readonly array $backends;

    private ?BackendInterface $selected = null;

    private bool $resolved = false;

    public function __construct(BackendInterface ...$backends)
    {
        if ($backends === []) {
            throw new \InvalidArgumentException('A backend selector needs at least one backend');
        }

        $this->backends = $backends;
    }

    /** The highest-priority available backend, or null if none can run. */
    public function select(): ?BackendInterface
    {
        if (!$this->resolved) {
            $best = null;
            foreach ($this->backends as $backend) {
                if (!$backend->isAvailable()) {
                    continue;
                }
                if (!$best instanceof \CrazyGoat\ScanMePHP\Generator\BackendInterface || $backend->getPriority() > $best->getPriority()) {
                    $best = $backend;
                }
            }
            $this->selected = $best;
            $this->resolved = true;
        }

        return $this->selected;
    }

    /** @throws NoBackendAvailableException when the host can run none of them */
    public function require(string $symbology): BackendInterface
    {
        $backend = $this->select();
        if (!$backend instanceof \CrazyGoat\ScanMePHP\Generator\BackendInterface) {
            throw NoBackendAvailableException::forSymbology($symbology, $this->names());
        }

        return $backend;
    }

    /**
     * Pin a specific backend by name, bypassing the ranking.
     *
     * This exists for the benchmark suite and for tests that must exercise one
     * implementation rather than whatever the host happens to offer; the
     * per-backend numbers in BENCHMARK.md are meaningless without it.
     */
    public function force(string $name): void
    {
        foreach ($this->backends as $backend) {
            if ($backend->getName() !== $name) {
                continue;
            }
            if (!$backend->isAvailable()) {
                throw new \RuntimeException(sprintf('Backend "%s" is not available on this host', $name));
            }
            $this->selected = $backend;
            $this->resolved = true;

            return;
        }

        throw new \RuntimeException(sprintf(
            'Unknown backend "%s"; this symbology has: %s',
            $name,
            implode(', ', $this->names())
        ));
    }

    /** Drop any forced choice and re-probe on next select(). */
    public function reset(): void
    {
        $this->selected = null;
        $this->resolved = false;
    }

    /** @return list<BackendInterface> */
    public function all(): array
    {
        return $this->backends;
    }

    /**
     * The highest-priority available backend satisfying an extra requirement.
     *
     * Symbologies use this when one call cannot go to the otherwise-fastest
     * implementation: QR's native and FFI backends cannot honour a forced
     * symbol version, so that one request falls back to a pure-PHP backend
     * while everything else keeps the fast path.
     *
     * @param callable(BackendInterface): bool $predicate
     */
    public function bestMatching(callable $predicate): ?BackendInterface
    {
        $best = null;
        foreach ($this->backends as $backend) {
            if (!$predicate($backend) || !$backend->isAvailable()) {
                continue;
            }
            if (!$best instanceof \CrazyGoat\ScanMePHP\Generator\BackendInterface || $backend->getPriority() > $best->getPriority()) {
                $best = $backend;
            }
        }

        return $best;
    }

    /** @return list<BackendInterface> */
    public function available(): array
    {
        return array_values(array_filter(
            $this->backends,
            static fn (BackendInterface $backend): bool => $backend->isAvailable()
        ));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(
            static fn (BackendInterface $backend): string => $backend->getName(),
            $this->backends
        );
    }
}
