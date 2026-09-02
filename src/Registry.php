<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

use CrazyGoat\ScanMePHP\Exception\UnknownGeneratorException;
use CrazyGoat\ScanMePHP\Exception\UnknownRendererException;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\Renderer\RendererInterface;

/**
 * The set of symbologies and output formats this instance knows about.
 *
 * Both sides are open: registering a generator or renderer is the whole
 * extension mechanism, and nothing here privileges the built-in ones. Names
 * are matched case-insensitively so 'QRCode', 'qrcode' and 'qr' behave the
 * way a caller passing a value straight from a form or CLI flag expects.
 */
final class Registry
{
    /** @var array<string, GeneratorInterface> Canonical lowercase name => generator */
    private array $generators = [];

    /** @var array<string, string> Lowercase alias => canonical name */
    private array $generatorAliases = [];

    /** @var array<string, RendererInterface> Lowercase format => renderer */
    private array $renderers = [];

    /**
     * Registers a generator under its canonical name and every alias.
     *
     * A later registration under the same name replaces the earlier one, which
     * is how a caller swaps a built-in for their own implementation.
     */
    public function addGenerator(GeneratorInterface $generator): self
    {
        $capabilities = $generator->getCapabilities();
        $canonical = strtolower($capabilities->name);

        $this->generators[$canonical] = $generator;
        foreach ($capabilities->aliases as $alias) {
            $this->generatorAliases[strtolower($alias)] = $canonical;
        }

        return $this;
    }

    public function addRenderer(RendererInterface $renderer): self
    {
        $this->renderers[strtolower($renderer->getFormat())] = $renderer;

        return $this;
    }

    /** @throws UnknownGeneratorException */
    public function getGenerator(string|Symbology $name): GeneratorInterface
    {
        $requested = Symbology::nameOf($name);
        $key = strtolower($requested);
        $key = $this->generatorAliases[$key] ?? $key;

        return $this->generators[$key]
            ?? throw UnknownGeneratorException::named($requested, $this->generatorNames());
    }

    /** @throws UnknownRendererException */
    public function getRenderer(string|Format $format): RendererInterface
    {
        $requested = Format::nameOf($format);

        return $this->renderers[strtolower($requested)]
            ?? throw UnknownRendererException::named($requested, $this->rendererFormats());
    }

    public function hasGenerator(string|Symbology $name): bool
    {
        $key = strtolower(Symbology::nameOf($name));

        return isset($this->generators[$key]) || isset($this->generatorAliases[$key]);
    }

    public function hasRenderer(string|Format $format): bool
    {
        return isset($this->renderers[strtolower(Format::nameOf($format))]);
    }

    /** Canonical names of every registered generator. @return list<string> */
    public function generatorNames(): array
    {
        return array_map(
            static fn (GeneratorInterface $generator): string => $generator->getCapabilities()->name,
            array_values($this->generators)
        );
    }

    /** @return list<string> */
    public function rendererFormats(): array
    {
        return array_map(
            static fn (RendererInterface $renderer): string => $renderer->getFormat(),
            array_values($this->renderers)
        );
    }

    /**
     * What every registered symbology supports — the answer to "what can this
     * build do?" without instantiating or encoding anything.
     *
     * @return array<string, GeneratorCapabilities> Keyed by canonical name
     */
    public function describeGenerators(): array
    {
        $described = [];
        foreach ($this->generators as $generator) {
            $capabilities = $generator->getCapabilities();
            $described[$capabilities->name] = $capabilities;
        }

        return $described;
    }

    /** @return list<GeneratorInterface> */
    public function generators(): array
    {
        return array_values($this->generators);
    }

    /** @return list<RendererInterface> */
    public function renderers(): array
    {
        return array_values($this->renderers);
    }

    /**
     * Every symbology that can encode this data, for callers that would rather
     * ask than guess which one fits a payload.
     *
     * @return list<string> Canonical names
     */
    public function generatorsFor(string $data): array
    {
        $names = [];
        foreach ($this->generators as $generator) {
            if ($generator->canEncode($data)) {
                $names[] = $generator->getCapabilities()->name;
            }
        }

        return $names;
    }
}
