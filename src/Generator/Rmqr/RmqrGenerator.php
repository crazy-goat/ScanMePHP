<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Rmqr;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Encoding\Rmqr\RmqrEncoder;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * rMQR: QR for a space that is long rather than square.
 *
 * A QR symbol is square because a QR symbol has three finders in three
 * corners, and that shape is wrong for most of the things barcodes are printed
 * on: the side of a cable, the barrel of a syringe, the edge of a board, the
 * spine of a rack unit. rMQR keeps QR's alphabet, its Reed–Solomon and its
 * zigzag, and rearranges the geometry into thirty-two rectangles from seven by
 * forty-three up to seventeen by a hundred and thirty-nine.
 *
 * The reported error correction levels are M and H, and unlike Micro QR that
 * is not a union of what different sizes offer — every size offers exactly
 * those two.
 */
final class RmqrGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Rmqr->value,
            title: 'Rectangular Micro QR Code',
            dimension: Dimension::Matrix,
            moduleShape: ModuleShape::Square,
            aliases: ['rectangular-micro-qr', 'r-mqr'],
            dataDescription: 'up to 361 digits, 219 alphanumeric characters or 150 bytes, '
                . 'depending on the symbol shape and error correction level',
            errorCorrectionLevels: ['M', 'H'],
            providesText: false,
            optionsClass: RmqrOptions::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        $options = $options instanceof RmqrOptions ? $options : new RmqrOptions();

        // Exact rather than a byte-length bound, as Micro QR's is: thirty-two
        // sizes and two levels is sixty-four comparisons over a shortest path
        // that has to run anyway.
        return RmqrEncoder::fits($data, $options->version?->value, $options->errorCorrection);
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
