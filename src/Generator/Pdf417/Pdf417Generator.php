<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Pdf417;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Encoding\Pdf417\Pdf417Encoder;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * PDF417: a stack of independently readable linear rows, which is why it is
 * what driving licences, boarding passes and shipping labels are printed with.
 *
 * It reports nine error correction levels because it genuinely has nine, unlike
 * the matrix symbologies here whose recovery is a percentage. They are named by
 * number, 0 to 8, and {@see Pdf417Options} carries the choice along with the
 * symbol's shape.
 */
final class Pdf417Generator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Pdf417->value,
            title: 'PDF417',
            dimension: Dimension::Matrix,
            moduleShape: ModuleShape::Square,
            aliases: ['pdf-417'],
            dataDescription: 'any byte string, up to roughly 1850 characters of text or 1100 bytes of binary',
            errorCorrectionLevels: ['0', '1', '2', '3', '4', '5', '6', '7', '8'],
            providesText: false,
            optionsClass: Pdf417Options::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        if ($data === '') {
            return false;
        }

        return (new Pdf417Encoder())->canEncode($data);
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
