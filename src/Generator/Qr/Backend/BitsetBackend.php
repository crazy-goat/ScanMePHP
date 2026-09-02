<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Qr\Backend;

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\FastEncoder;
use CrazyGoat\ScanMePHP\Generator\Qr\QrBackendInterface;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\Generator\Qr\QrSymbols;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;

/**
 * Pure PHP with int-pair bit packing and bitwise mask selection.
 *
 * The packing puts two 32-bit halves of a module row in one integer, so it
 * needs 64-bit integers. Its tables stop at version 27 and it delegates to the
 * portable encoder above that on its own, so the limit only shows up when a
 * caller pins a version.
 */
final class BitsetBackend implements QrBackendInterface
{
    private ?FastEncoder $encoder = null;

    public function getName(): string
    {
        return 'bitset';
    }

    public function isAvailable(): bool
    {
        return \PHP_INT_SIZE >= 8;
    }

    public function getPriority(): int
    {
        return 200;
    }

    public function supportsForcedVersion(): bool
    {
        return true;
    }

    public function getMaxForcedVersion(): int
    {
        return FastEncoder::MAX_VERSION;
    }

    public function encode(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        $this->encoder ??= new FastEncoder();

        $level = ErrorCorrectionLevel::Medium;
        $version = null;
        if ($options instanceof QrOptions) {
            $level = $options->errorCorrection;
            $version = $options->version;
        }

        $matrix = $version === null
            ? $this->encoder->encode($data, $level)
            : $this->encoder->encodeVersion($data, $level, $version);

        return QrSymbols::fromMatrix($matrix);
    }
}
