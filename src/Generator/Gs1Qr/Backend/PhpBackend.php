<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Gs1Qr\Backend;

use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\Generator\Qr\QrSymbols;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * GS1 QR in pure PHP.
 *
 * Parses the element strings into the byte payload a scanner reports, and
 * hands it to the readable QR pipeline with FNC1 announced in front. Symbol
 * version, masking and Reed–Solomon are all plain QR's, unchanged.
 */
final class PhpBackend implements BackendInterface
{
    private ?Encoder $encoder = null;

    public function getName(): string
    {
        return 'php';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function encode(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        $elements = ElementString::parse($data);
        $payload = $elements->payload();

        $options = $options instanceof QrOptions ? $options : new QrOptions();
        $this->encoder ??= new Encoder();

        $level = $options->errorCorrection;
        $matrix = $options->mask === null
            ? $this->encoder->encodeGs1($payload, $level, $options->version ?? 0)
            : $this->encoder->encodeGs1AtMask(
                $payload,
                $level,
                $options->version ?? $this->encoder->getMinimumGs1Version($payload, $level),
                $options->mask,
            );

        return QrSymbols::fromMatrix(
            $matrix,
            Symbology::Gs1Qr->value,
            [
                'elements' => \count($elements->elements),
                // What a scanner hands back, FNC1 separators included.
                'payload' => $payload,
            ],
        );
    }
}
