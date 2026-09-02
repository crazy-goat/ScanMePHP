<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

use CrazyGoat\ScanMePHP\Exception\FileWriteException;
use CrazyGoat\ScanMePHP\Exception\IncompatibleRendererException;
use CrazyGoat\ScanMePHP\Exception\UnsupportedDataException;
use CrazyGoat\ScanMePHP\Exception\UnsupportedOptionsException;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Options\OptionsInterface;
use CrazyGoat\ScanMePHP\Options\RenderOptionsInterface;

/**
 * The one entry point: pick a symbology, pick an output format, get bytes.
 *
 *     $scanme = Scanme::create();
 *     echo $scanme->render('https://example.com', 'qrcode', 'svg');
 *     echo $scanme->render('5901234123457', 'ean13', 'png', new PngOptions(moduleSize: 4));
 *
 * Everything behind it is swappable through the registry — symbologies,
 * output formats, and each symbology's encoding backend. The facade itself
 * only resolves names, routes option bags to whoever declared them, and
 * refuses combinations that cannot be drawn faithfully.
 */
final class Scanme
{
    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    /** An instance with every built-in symbology and output format registered. */
    public static function create(): self
    {
        return new self(Defaults::registry());
    }

    /**
     * Encode $data as $generator and return it in $format.
     *
     * Option bags are routed by the interface they implement, so generator and
     * renderer options can be passed in any order and either can be omitted.
     * A bag nobody claims is an error rather than a no-op.
     *
     * @throws Exception\UnknownGeneratorException
     * @throws Exception\UnknownRendererException
     * @throws UnsupportedDataException
     * @throws UnsupportedOptionsException
     * @throws IncompatibleRendererException
     */
    public function render(
        string $data,
        string|Symbology $generator,
        string|Format $format,
        OptionsInterface ...$options
    ): string {
        [$generatorOptions, $renderOptions] = $this->routeOptions($options);

        $symbol = $this->generateWith($data, $generator, $generatorOptions);

        return $this->renderSymbol($symbol, $format, $renderOptions);
    }

    /**
     * Encode without rendering, for callers that want the modules themselves —
     * a custom renderer, an image pipeline, an assertion in a test.
     *
     * @throws UnsupportedDataException
     * @throws UnsupportedOptionsException
     */
    public function generate(string $data, string|Symbology $generator, OptionsInterface ...$options): Symbol
    {
        [$generatorOptions, $renderOptions] = $this->routeOptions($options);

        if ($renderOptions instanceof \CrazyGoat\ScanMePHP\Options\RenderOptionsInterface && !$renderOptions instanceof GeneratorOptionsInterface) {
            throw UnsupportedOptionsException::notApplicable($renderOptions::class, 'generating without rendering');
        }

        return $this->generateWith($data, $generator, $generatorOptions);
    }

    /**
     * Render an already-built symbol, whatever produced it.
     *
     * @throws Exception\UnknownRendererException
     * @throws IncompatibleRendererException
     */
    public function renderSymbol(
        Symbol $symbol,
        string|Format $format,
        ?RenderOptionsInterface $options = null
    ): string {
        $renderer = $this->registry->getRenderer($format);
        $capabilities = $renderer->getCapabilities();

        if ($options instanceof \CrazyGoat\ScanMePHP\Options\RenderOptionsInterface
            && $capabilities->optionsClass !== null
            && !$options instanceof $capabilities->optionsClass
        ) {
            throw UnsupportedOptionsException::wrongType(
                'the ' . $renderer->getFormat() . ' renderer',
                $capabilities->optionsClass,
                $options::class
            );
        }

        $reasons = Compatibility::check($symbol, $renderer);
        if ($reasons !== []) {
            $symbology = $symbol->getMetadataValue('symbology');

            throw IncompatibleRendererException::because(
                \is_string($symbology) ? $symbology : null,
                $renderer->getFormat(),
                $reasons
            );
        }

        return $renderer->render($symbol, $options);
    }

    /**
     * The rendered symbol as a data: URI, ready for an <img src> or a CSS
     * background.
     *
     * @throws UnsupportedDataException
     * @throws UnsupportedOptionsException
     * @throws IncompatibleRendererException
     */
    public function dataUri(
        string $data,
        string|Symbology $generator,
        string|Format $format,
        OptionsInterface ...$options
    ): string {
        $content = $this->render($data, $generator, $format, ...$options);

        return 'data:' . $this->getContentType($format) . ';base64,' . base64_encode($content);
    }

    /**
     * Render straight to a file.
     *
     * Writes under LOCK_EX so a concurrent request cannot read a half-written
     * image, and checks the directory first to fail with a clear reason rather
     * than a file_put_contents() warning.
     *
     * @throws FileWriteException
     */
    public function toFile(
        string $path,
        string $data,
        string|Symbology $generator,
        string|Format $format,
        OptionsInterface ...$options
    ): void {
        $directory = \dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw FileWriteException::directoryNotWritable($directory);
        }

        $content = $this->render($data, $generator, $format, ...$options);
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw FileWriteException::cannotWriteToFile($path);
        }
    }

    /** MIME type an output format produces, for HTTP responses and data URIs. */
    public function getContentType(string|Format $format): string
    {
        return $this->registry->getRenderer($format)->getContentType();
    }

    /**
     * Whether this symbology and this output format can be combined at all.
     *
     * Answers the question without encoding: the incompatibilities are
     * properties of the symbology, not of a particular payload.
     */
    public function supports(string|Symbology $generator, string|Format $format): bool
    {
        $capabilities = $this->registry->getGenerator($generator)->getCapabilities();
        $rendererCapabilities = $this->registry->getRenderer($format)->getCapabilities();

        if (!$rendererCapabilities->supportsShape($capabilities->moduleShape)) {
            return false;
        }

        return !$capabilities->providesText || $rendererCapabilities->text;
    }

    public function getRegistry(): Registry
    {
        return $this->registry;
    }

    private function generateWith(
        string $data,
        string|Symbology $name,
        ?GeneratorOptionsInterface $options
    ): Symbol {
        $generator = $this->registry->getGenerator($name);
        $capabilities = $generator->getCapabilities();

        if ($options instanceof \CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface
            && $capabilities->optionsClass !== null
            && !$options instanceof $capabilities->optionsClass
        ) {
            throw UnsupportedOptionsException::wrongType(
                'the ' . $capabilities->title . ' generator',
                $capabilities->optionsClass,
                $options::class
            );
        }

        if (!$generator->canEncode($data, $options)) {
            throw UnsupportedDataException::forSymbology($capabilities->title, $capabilities->dataDescription);
        }

        return $generator->generate($data, $options);
    }

    /**
     * Split option bags into the generator slot and the renderer slot.
     *
     * A bag may implement both interfaces and then fills both slots — useful
     * for a caller who wants one object for a whole symbol-plus-appearance
     * preset.
     *
     * @param list<OptionsInterface> $options
     * @return array{0: GeneratorOptionsInterface|null, 1: RenderOptionsInterface|null}
     */
    private function routeOptions(array $options): array
    {
        $generatorOptions = null;
        $renderOptions = null;

        foreach ($options as $bag) {
            $claimed = false;

            if ($bag instanceof GeneratorOptionsInterface) {
                if ($generatorOptions instanceof \CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface) {
                    throw UnsupportedOptionsException::duplicate(
                        'generator',
                        $generatorOptions::class,
                        $bag::class
                    );
                }
                $generatorOptions = $bag;
                $claimed = true;
            }

            if ($bag instanceof RenderOptionsInterface) {
                if ($renderOptions instanceof \CrazyGoat\ScanMePHP\Options\RenderOptionsInterface) {
                    throw UnsupportedOptionsException::duplicate(
                        'renderer',
                        $renderOptions::class,
                        $bag::class
                    );
                }
                $renderOptions = $bag;
                $claimed = true;
            }

            if (!$claimed) {
                throw UnsupportedOptionsException::unclaimed($bag::class);
            }
        }

        return [$generatorOptions, $renderOptions];
    }
}
