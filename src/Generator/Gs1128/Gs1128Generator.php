<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Gs1128;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\Generator\Gs1\ElementString;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * GS1-128: Code 128 bars carrying GS1 application identifiers.
 *
 * The same table and the same encoder as Code 128 — the bars are Code 128 bars,
 * and a scanner that cannot tell the difference is not misreading anything.
 * What separates the two is one symbol character: an FNC1 directly after the
 * start code, which is what makes a reader announce ']C1' and hand the data to
 * a GS1 parser instead of passing it through.
 *
 * It is a generator of its own rather than an option on Code 128 because
 * canEncode() has a different question to answer. Code 128 asks whether the
 * bytes are printable; this asks whether the payload is a sequence of valid
 * application identifiers with data of a length each one accepts. Those are
 * different enough that one generator answering both would have to be told
 * which it was being asked, and a caller who forgot would get a symbol that
 * scans as the wrong kind of thing.
 *
 * Takes the parenthesised form GS1 prints under the bars —
 * '(01)09501101020917(10)LOT0001' — and puts the FNC1 separators where the
 * table says they go.
 */
final class Gs1128Generator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::Gs1128->value,
            title: 'GS1-128',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['gs1128', 'ean128', 'ean-128', 'ucc128'],
            dataDescription: 'GS1 element strings, as (AI)data — e.g. (01)09501101020917(10)LOT0001',
            errorCorrectionLevels: [],
            providesText: true,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        return ElementString::isParsable($data);
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
