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
     * Normally that is simply the fastest available one. Pinning narrows the
     * field, and the two things a caller can pin narrow it differently: the
     * C++ core can be told neither a version nor a mask, the bitset encoder
     * can be told a version up to 27 but not a mask, and only the portable
     * encoder takes both. So a pinned request drops to whichever backend can
     * honour it rather than silently ignoring what the caller asked for.
     */
    private function resolveBackend(?GeneratorOptionsInterface $options): BackendInterface
    {
        $version = $options instanceof QrOptions ? $options->version : null;
        $mask = $options instanceof QrOptions ? $options->mask : null;

        if ($version === null && $mask === null) {
            return $this->selector->require($this->getCapabilities()->title);
        }

        $backend = $this->selector->bestMatching(
            static fn (BackendInterface $candidate): bool => $candidate instanceof QrBackendInterface
                && ($version === null || ($candidate->supportsForcedVersion()
                    && $candidate->getMaxForcedVersion() >= $version))
                && ($mask === null || $candidate->supportsForcedMask())
        );

        return $backend ?? throw NoBackendAvailableException::forSymbology(
            $this->describePinned($version, $mask),
            $this->selector->names()
        );
    }

    private function describePinned(?int $version, ?int $mask): string
    {
        $pinned = [];
        if ($version !== null) {
            $pinned[] = sprintf('version %d', $version);
        }

        if ($mask !== null) {
            $pinned[] = sprintf('mask %d', $mask);
        }

        return sprintf('%s pinned to %s', $this->getCapabilities()->title, implode(' and ', $pinned));
    }
}
