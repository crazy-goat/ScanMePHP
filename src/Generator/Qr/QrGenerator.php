<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Qr;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Exception\NoBackendAvailableException;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

final class QrGenerator implements GeneratorInterface
{
    /**
     * Longest byte-mode payload a version 40 symbol holds, per error
     * correction level, indexed by ErrorCorrectionLevel::value.
     * Source: ISO/IEC 18004:2015 Table 7.
     */
    private const MAX_BYTES = [2953, 2331, 1663, 1273];

    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(
            new Backend\NativeBackend(),
            new Backend\FfiBackend(),
            new Backend\BitsetBackend(),
            new Backend\PortableBackend(),
        );
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::QrCode->value,
            title: 'QR Code',
            dimension: Dimension::Matrix,
            moduleShape: ModuleShape::Square,
            aliases: ['qr'],
            dataDescription: 'any byte string, up to 2953 bytes at error correction level L',
            errorCorrectionLevels: ['L', 'M', 'Q', 'H'],
            providesText: false,
            optionsClass: QrOptions::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        if ($data === '') {
            return false;
        }

        $level = $options instanceof QrOptions
            ? $options->errorCorrection
            : ErrorCorrectionLevel::Medium;

        // Capacity at a pinned version is not checked here: working it out
        // costs the version tables, and generate() reports it precisely.
        return \strlen($data) <= self::MAX_BYTES[$level->value];
    }

    public function generate(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        return $this->resolveBackend($options)->encode($data, $options);
    }

    public function getActiveBackend(): ?BackendInterface
    {
        return $this->selector->select();
    }

    /** The backend selection, for benchmarks and for tests that pin one. */
    public function getBackendSelector(): BackendSelector
    {
        return $this->selector;
    }

    /**
     * The fastest backend that can satisfy this particular request.
     *
     * Normally that is simply the fastest available one. A pinned version
     * narrows the field: the C++ core cannot be told which version to use, and
     * the bitset encoder can only be pinned up to version 27, so those
     * requests drop to a pure-PHP backend rather than silently ignoring the
     * version the caller asked for.
     */
    private function resolveBackend(?GeneratorOptionsInterface $options): BackendInterface
    {
        $version = $options instanceof QrOptions ? $options->version : null;
        if ($version === null) {
            return $this->selector->require($this->getCapabilities()->title);
        }

        $backend = $this->selector->bestMatching(
            static fn (BackendInterface $candidate): bool => $candidate instanceof QrBackendInterface
                && $candidate->supportsForcedVersion()
                && $candidate->getMaxForcedVersion() >= $version
        );

        return $backend ?? throw NoBackendAvailableException::forSymbology(
            sprintf('%s pinned to version %d', $this->getCapabilities()->title, $version),
            $this->selector->names()
        );
    }
}
