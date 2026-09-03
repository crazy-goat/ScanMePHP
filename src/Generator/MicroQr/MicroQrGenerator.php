<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\MicroQr;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Encoding\MicroQr\MicroQrEncoder;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Micro QR Code: QR for the cases where a QR symbol will not fit.
 *
 * The smallest QR symbol is twenty-one modules across before its four-module
 * quiet zone, so twenty-nine all told, and it holds seventeen bytes. The
 * smallest Micro QR is eleven with a two-module quiet zone, so fifteen — a
 * quarter of the area — and it holds five digits. That trade is the whole
 * point of the symbology, and it is why it turns up on things too small to
 * carry a QR symbol at all: electronic components, medical vials, PCB silk
 * screens.
 *
 * The error correction levels reported here are the union of what the four
 * versions offer, which is not what any one of them offers: M1 has none, M2
 * and M3 have L and M, and only M4 has Q. There is no H anywhere in the
 * symbology. {@see MicroQrOptions} is where that gets enforced per version.
 */
final class MicroQrGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::MicroQr->value,
            title: 'Micro QR Code',
            dimension: Dimension::Matrix,
            moduleShape: ModuleShape::Square,
            aliases: ['microqr', 'micro-qrcode'],
            dataDescription: 'up to 35 digits, 21 alphanumeric characters or 15 bytes, '
                . 'depending on the version and error correction level',
            errorCorrectionLevels: ['L', 'M', 'Q'],
            providesText: false,
            optionsClass: MicroQrOptions::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        $options = $options instanceof MicroQrOptions ? $options : new MicroQrOptions();

        // Unlike QR this is exact rather than a byte-length bound, and it can
        // afford to be: there are four versions and three levels, so the
        // search that generate() runs is twelve comparisons and costs nothing.
        return MicroQrEncoder::fits($data, $options->version?->value, $options->errorCorrection);
    }

    public function generate(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        return $this->selector->require($this->getCapabilities()->title)->encode($data, $options);
    }

    public function getActiveBackend(): ?BackendInterface
    {
        return $this->selector->select();
    }

    public function getBackendSelector(): BackendSelector
    {
        return $this->selector;
    }
}
