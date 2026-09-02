<?php

declare(strict_types=1);

/**
 * Adding your own symbology, renderer or encoding backend.
 *
 * Nothing in the default registry is privileged. Building on top of it is the
 * normal path: take the registry, add your own, hand it to Scanme. A
 * registration under an existing name replaces it, which is how you would swap
 * the SVG renderer for one that suits your house style without forking.
 *
 * Run: php examples/07_extending.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Dimension;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\BackendSelector;
use CrazyGoat\ScanMePHP\Generator\GeneratorCapabilities;
use CrazyGoat\ScanMePHP\Generator\GeneratorInterface;
use CrazyGoat\ScanMePHP\Generator\Qr\QrGenerator;
use CrazyGoat\ScanMePHP\ModuleShape;
use CrazyGoat\ScanMePHP\Options\GeneratorOptionsInterface;
use CrazyGoat\ScanMePHP\Options\RenderOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Renderer\RendererCapabilities;
use CrazyGoat\ScanMePHP\Renderer\RendererInterface;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbol;

echo "=== 1. A custom renderer ===\n\n";

// Renderers turn a Symbol into bytes. The Symbol is a plain two-level bitmap
// with a width, a height and optional per-row heights, so a renderer needs no
// knowledge of the symbology that produced it.
final class JsonRenderer implements RendererInterface
{
    public function getFormat(): string
    {
        return 'json';
    }

    public function getContentType(): string
    {
        return 'application/json';
    }

    public function getCapabilities(): RendererCapabilities
    {
        // No drawing at all, so nothing is beyond it: any shape, any text.
        return new RendererCapabilities(
            moduleShapes: [ModuleShape::Square, ModuleShape::Hexagon],
            text: true,
            color: false,
        );
    }

    public function render(Symbol $symbol, ?RenderOptionsInterface $options = null): string
    {
        return json_encode([
            'width' => $symbol->getWidth(),
            'height' => $symbol->getHeight(),
            'text' => $symbol->getText(),
            'metadata' => $symbol->getMetadata(),
            'rows' => array_map(
                static fn (string $row): string => $row,
                $symbol->rows()
            ),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }
}

$scanme = new Scanme(Defaults::registry()->addRenderer(new JsonRenderer()));

$json = $scanme->render('96385074', 'ean8', 'json');
echo implode("\n", array_slice(explode("\n", $json), 0, 8)) . "\n      ...\n\n";
printf("content type: %s\n\n", $scanme->getContentType('json'));

echo "=== 2. A custom symbology ===\n\n";

// A generator owns its encoding, its capabilities and its backend selection.
// There is no base class to inherit: the interface is four methods, and the
// selector is composed in rather than mixed in.
final class PharmacodeBackend implements BackendInterface
{
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
        return 0;
    }

    public function encode(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        // Pharmacode (Laetus) encodes a number 3 to 131070 as a sequence of
        // narrow and wide bars read right to left, with no check digit: the
        // valid range is the integrity check.
        $value = (int) $data;
        $bars = [];

        while ($value > 0) {
            if ($value % 2 === 0) {
                $bars[] = '111';   // wide bar
                $value = intdiv($value - 2, 2);
            } else {
                $bars[] = '1';     // narrow bar
                $value = intdiv($value - 1, 2);
            }
        }

        $modules = implode('0', array_reverse($bars));

        return new Symbol(
            width: \strlen($modules),
            height: 1,
            modules: $modules,
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            quietZone: new QuietZone(left: 10, right: 10),
            text: $data,
            metadata: ['symbology' => 'pharmacode', 'value' => (int) $data],
        );
    }
}

final class PharmacodeGenerator implements GeneratorInterface
{
    private readonly BackendSelector $selector;

    public function __construct()
    {
        $this->selector = new BackendSelector(new PharmacodeBackend());
    }

    public function getCapabilities(): GeneratorCapabilities
    {
        return new GeneratorCapabilities(
            name: 'pharmacode',
            title: 'Pharmacode',
            dimension: Dimension::Linear,
            moduleShape: ModuleShape::Square,
            aliases: ['laetus'],
            dataDescription: 'a whole number from 3 to 131070',
            providesText: true,
        );
    }

    public function canEncode(string $data, ?GeneratorOptionsInterface $options = null): bool
    {
        // The real check, not a length guess: the facade calls this before
        // encoding and turns a false into UnsupportedDataException with the
        // description above.
        return preg_match('/^\d{1,6}$/', $data) === 1
            && (int) $data >= 3
            && (int) $data <= 131070;
    }

    public function generate(string $data, ?GeneratorOptionsInterface $options = null): Symbol
    {
        return $this->selector->require($this->getCapabilities()->title)->encode($data, $options);
    }

    public function getActiveBackend(): ?BackendInterface
    {
        return $this->selector->select();
    }
}

$scanme = new Scanme(
    Defaults::registry()
        ->addRenderer(new JsonRenderer())
        ->addGenerator(new PharmacodeGenerator())
);

foreach (['3', '117', '12345'] as $value) {
    $symbol = $scanme->generate($value, 'pharmacode');
    printf("%-6s %2d modules  %s\n", $value, $symbol->getWidth(), $symbol->rows()[0]);
}

echo "\n  It is a first-class citizen: aliases resolve, capabilities publish,\n";
echo "  every renderer works, and unsupported data is refused by name.\n\n";

printf("  via alias 'laetus': %d modules\n", $scanme->generate('117', 'laetus')->getWidth());
printf("  registered: %s\n", implode(', ', $scanme->getRegistry()->generatorNames()));

try {
    $scanme->generate('2', 'pharmacode');
} catch (\CrazyGoat\ScanMePHP\Exception\UnsupportedDataException $e) {
    echo '  ' . $e->getMessage() . "\n";
}

echo "\n=== 3. Backends: the same modules, different speed ===\n\n";

// A symbology may have several encoders producing identical output. QR ships
// four: a PHP extension, an FFI binding to the same C++ core, a bitset-based
// pure-PHP encoder and a portable fallback. Which one runs depends on the
// host, so the choice is made at runtime and is invisible except here.
// Taken from the class rather than the registry, because backend selection is
// each generator's own business and deliberately absent from GeneratorInterface.
$qr = new QrGenerator();
$selector = $qr->getBackendSelector();

foreach ($selector->all() as $backend) {
    printf(
        "  %-8s priority %3d  %s\n",
        $backend->getName(),
        $backend->getPriority(),
        $backend->isAvailable() ? 'available' : 'not available on this host'
    );
}

printf("\n  active: %s\n", $qr->getActiveBackend()?->getName() ?? 'none');

// Forcing one is how the benchmarks compare them, and how a test pins the
// pure-PHP path regardless of what happens to be installed.
$selector->force('portable');
printf("  forced: %s\n", $qr->getActiveBackend()?->getName() ?? 'none');
$selector->reset();
printf("  reset:  %s\n", $qr->getActiveBackend()?->getName() ?? 'none');

echo "\nDone.\n";
