<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Generator\AustraliaPost;

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
 * The Australia Post Customer Barcode, on the front of Australian mail.
 *
 * The last of the four-state postal codes here, and the only one that can
 * repair what it reads. RM4SCC and KIX carry a check character or nothing at
 * all, Intelligent Mail carries a CRC — all three can say a symbol is wrong
 * and none can say what it should have been. This one spends four of its
 * codewords on Reed–Solomon over GF(64) and corrects two of them outright.
 *
 * The payload is an eight-digit sorting code, optionally followed by customer
 * information that fills a field the standard sizes in bars rather than in
 * characters — so the accepted lengths are the ones that fill a field exactly,
 * and the length is also what picks the table the field is written in. See
 * {@see Payload}.
 *
 * The one option is the Format Control Code, because the same sorting code
 * drawn as Reply Paid and drawn as ordinary mail are two different articles
 * and nothing in the data says which. See {@see AustraliaPostOptions}.
 */
final class AustraliaPostGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct(?BackendSelector $selector = null)
    {
        $this->selector = $selector ?? new BackendSelector(new Backend\PhpBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: Symbology::AustraliaPost->value,
            title: 'Australia Post',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['auspost', 'australia-post-4state', 'customer-barcode'],
            dataDescription: 'an 8-digit sorting code, optionally followed by 5, 8, 10 or 15 characters '
                . 'of customer information — 8 and 15 digits only',
            errorCorrectionLevels: [],
            providesText: false,
            optionsClass: AustraliaPostOptions::class,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        return Backend\PhpBackend::accepts($data, $options);
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
