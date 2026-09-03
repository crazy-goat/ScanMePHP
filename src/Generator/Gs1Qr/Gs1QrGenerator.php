<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Gs1Qr;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\Exception\DataTooLargeException;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * GS1 QR: a QR symbol carrying GS1 application identifiers.
 *
 * The third spelling of FNC1 in this library, and the odd one out. Code 128
 * spells it as a symbol character and Data Matrix as codeword 232 — both of
 * them values in the same alphabet as the data. QR spells it as a *mode
 * indicator*, four bits ahead of the first segment, which is why nothing here
 * touches the payload itself: the separators inside it stay plain 0x1d bytes,
 * identical to the ones GS1-128 and GS1 Data Matrix carry.
 *
 * Only the pure-PHP backend. The C++ core reached through the extension and
 * through FFI exposes `encode(data, len, ecl)` and has nowhere to put the
 * indicator, and native acceleration is deliberately not growing new
 * symbologies — see ROADMAP.md. A GS1 QR therefore encodes in PHP even on a
 * machine with the extension loaded, which costs microseconds on a symbol a
 * scanner reads once.
 *
 * Registered separately from `qrcode` for the reason GS1-128 is: canEncode()
 * answers a different question. QR takes any byte string, so it would encode
 * '(01)09501101020917' as literal parentheses — a symbol that scans, carrying
 * data no GS1 system expects.
 */
final class Gs1QrGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    private ?Encoder $encoder = null;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Gs1Qr->value,
            title: 'GS1 QR Code',
            dimension: Dimension::Matrix,
            moduleShape: ModuleShape::Square,
            aliases: ['gs1-qrcode', 'gs1qr'],
            dataDescription: 'GS1 element strings, as (AI)data — e.g. (01)09501101020917(10)LOT0001',
            errorCorrectionLevels: ['L', 'M', 'Q', 'H'],
            providesText: false,
            optionsClass: QrOptions::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        if (!ElementString::isParsable($data)) {
            return false;
        }

        $options = $options instanceof QrOptions ? $options : new QrOptions();
        $this->encoder ??= new Encoder();

        try {
            $minimum = $this->encoder->getMinimumGs1Version(
                ElementString::parse($data)->payload(),
                $options->errorCorrection
            );
        } catch (DataTooLargeException) {
            return false;
        }

        // A pinned version is answerable here, unlike plain QR's, because the
        // minimum comes out of a bit count rather than a capacity table.
        return $options->version === null || $options->version >= $minimum;
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
