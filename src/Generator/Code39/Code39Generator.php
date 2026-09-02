<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\Code39;

use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

/**
 * Code 39, the oldest alphanumeric linear symbology still in general use.
 *
 * Registered twice, once per Mode: 'code39' for the 43 characters and
 * 'code39ext' for all of ASCII. Two registry entries rather than one with a
 * flag, because which mode a symbol is in is not something canEncode() could
 * otherwise answer — 'hello' is encodable as Code 39 Extended and not as Code
 * 39, and a registry that could not say which would be no use for choosing a
 * symbology. It also keeps the two honest about width: the same five bytes are
 * seven characters wide in one mode and impossible in the other.
 *
 * The two produce bars a scanner cannot tell apart when the payload happens to
 * be plain: 'HELLO' in extended mode is byte-for-byte the standard symbol, and
 * a decoder reports it as Code 39. That is the symbology, not a defect.
 */
final class Code39Generator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(
        private readonly Mode $mode = Mode::Standard,
        ?BackendSelector $selector = null,
    ) {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend($mode));
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: $this->mode === Mode::Extended
                ? Symbology::Code39Extended->value
                : Symbology::Code39->value,
            title: $this->mode === Mode::Extended ? 'Code 39 Extended' : 'Code 39',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: $this->mode === Mode::Extended
                ? ['code-39-extended', 'code39-full-ascii']
                : ['code-39', 'c39'],
            dataDescription: $this->mode === Mode::Extended
                ? 'any ASCII (byte values 0 to 127)'
                : 'digits, A-Z, space and "-.$/+%"',
            errorCorrectionLevels: [],
            providesText: true,
            optionsClass: Code39Options::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        if ($data === '') {
            return false;
        }

        return $this->mode === Mode::Extended
            ? Charset::isEncodableExtended($data)
            : Charset::isEncodable($data);
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
