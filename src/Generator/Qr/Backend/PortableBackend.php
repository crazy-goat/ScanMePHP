<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Qr\Backend;

use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Generator\Qr\QrBackendInterface;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\Generator\Qr\QrSymbols;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * Pure PHP with no integer-width assumptions — the backend that always works,
 * and the only one covering versions 28 to 40.
 */
final class PortableBackend implements QrBackendInterface
{
    private ?Encoder $encoder = null;

    public function getName(): string
    {
        return 'portable';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function supportsForcedVersion(): bool
    {
        return true;
    }

    public function getMaxForcedVersion(): int
    {
        return QrOptions::MAX_VERSION;
    }

    public function supportsForcedMask(): bool
    {
        return true;
    }

    public function encode(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        $this->encoder ??= new Encoder();

        $level = ErrorCorrectionLevel::Medium;
        $version = 0;
        $mask = null;
        if ($options instanceof QrOptions) {
            $level = $options->errorCorrection;
            $version = $options->version ?? 0;
            $mask = $options->mask;
        }

        if ($mask === null) {
            return QrSymbols::fromMatrix($this->encoder->encode($data, $level, $version));
        }

        // A pinned mask needs a settled version, and the caller may not have
        // pinned one. The encoder's own answer is the same one encode() would
        // reach, so this changes nothing but the masking step.
        return QrSymbols::fromMatrix($this->encoder->encodeAtMask(
            $data,
            $level,
            $version !== 0 ? $version : $this->encoder->getMinimumVersion($data, $level),
            $mask,
        ));
    }
}
