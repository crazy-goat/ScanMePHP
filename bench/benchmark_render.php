<?php

declare(strict_types=1);

/*
 * Renderer benchmark: how long it takes to draw an already-encoded symbol.
 *
 * Encoding is deliberately outside the measured region — a Symbol is built
 * once and handed to every renderer — because the two costs move
 * independently, and for large symbols rendering is the larger of the two.
 *
 * usage: php bench/benchmark_render.php [format|all] [iterations] [payload bytes] [symbology]
 *
 *   php bench/benchmark_render.php all 200
 *   php bench/benchmark_render.php svg 500 1400
 *   php bench/benchmark_render.php png 200 300 ean13
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Renderer\Options\AbstractRenderOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\AsciiOptions;
use CrazyGoat\ScanMePHP\Renderer\RendererInterface;
use CrazyGoat\ScanMePHP\Scanme;

$format = $argv[1] ?? 'all';
$iterations = max(1, (int) ($argv[2] ?? 200));
$payloadBytes = max(1, (int) ($argv[3] ?? 300));
$symbology = $argv[4] ?? 'qrcode';

$scanme = new Scanme($registry = Defaults::registry());

if ($format !== 'all' && !$registry->hasRenderer($format)) {
    fwrite(STDERR, sprintf(
        "unknown format \"%s\"; available: all, %s\n",
        $format,
        implode(', ', $registry->rendererFormats())
    ));
    exit(1);
}

/**
 * A payload of roughly the requested size that the chosen symbology accepts.
 *
 * The retail symbologies take a fixed number of digits, so a size argument is
 * meaningless for them; saying so beats silently benchmarking something else.
 */
$payload = static function (string $symbology, int $bytes) use ($registry): string {
    $fixed = [
        'ean13' => '5901234123457',
        'ean8' => '96385074',
        'upc-a' => '036000291452',
        'upc-e' => '04252614',
        'itf' => '1234567890',
        'itf14' => '1234567890123',
        'code39' => 'PART-4471',
        'code39ext' => 'Part 4471/a',
        'code93' => 'Part 4471/a',
        'codabar' => '4917234',
        'ean2' => '52',
        'ean5' => '51299',
    ];

    if (isset($fixed[$symbology])) {
        return $fixed[$symbology];
    }

    $data = substr(str_repeat('https://example.com/', (int) ceil($bytes / 20) + 1), 0, $bytes);

    if (!$registry->getGenerator($symbology)->canEncode($data)) {
        fwrite(STDERR, sprintf("%s cannot encode a %d-byte payload of that shape\n", $symbology, $bytes));
        exit(1);
    }

    return $data;
};

$data = $payload($symbology, $payloadBytes);
$symbol = $scanme->generate($data, $symbology);

/** The options bag each renderer reads, at a size worth drawing. */
$optionsFor = static function (RendererInterface $renderer): ?AbstractRenderOptions {
    $class = $renderer->getCapabilities()->optionsClass;

    if ($class === null) {
        return null;
    }

    // The ASCII renderers fix module size at 1 — a character cell is a module —
    // so their bag takes no size to set.
    return $class === AsciiOptions::class ? new AsciiOptions() : new $class(moduleSize: 4);
};

/** Steady-state per-call cost in microseconds, warmed up and with GC settled. */
$bench = static function (callable $subject, int $n): float {
    for ($i = 0; $i < min(5, $n); $i++) {
        $subject();
    }

    gc_collect_cycles();
    $start = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $subject();
    }

    return (hrtime(true) - $start) / 1e3 / $n;
};

printf("symbology:  %s\n", $symbology);
printf("payload:    %d bytes\n", \strlen($data));
printf("symbol:     %d x %d modules\n", $symbol->getWidth(), $symbol->getHeight());
printf("iterations: %d\n\n", $iterations);

printf("%-18s %12s %12s %10s\n", 'format', 'per call', 'total', 'output');
printf("%-18s %12s %12s %10s\n", str_repeat('-', 18), str_repeat('-', 12), str_repeat('-', 12), str_repeat('-', 10));

$renderers = $format === 'all' ? $registry->renderers() : [$registry->getRenderer($format)];
$results = [];

foreach ($renderers as $renderer) {
    $options = $optionsFor($renderer);
    $bytes = \strlen($renderer->render($symbol, $options));
    $microseconds = $bench(static fn (): string => $renderer->render($symbol, $options), $iterations);

    printf(
        "%-18s %9.1f us %9.1f ms %8d B\n",
        $renderer->getFormat(),
        $microseconds,
        $microseconds * $iterations / 1e3,
        $bytes
    );

    $results[] = [
        'format' => $renderer->getFormat(),
        'microseconds' => round($microseconds, 2),
        'bytes' => $bytes,
    ];
}

// Written for comparison across commits; bench/benchmark_*.json is gitignored.
$file = sprintf('%s/benchmark_render_%s_%d.json', __DIR__, $format, time());
file_put_contents($file, json_encode([
    'symbology' => $symbology,
    'payload_bytes' => \strlen($data),
    'symbol' => ['width' => $symbol->getWidth(), 'height' => $symbol->getHeight()],
    'iterations' => $iterations,
    'results' => $results,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

printf("\nwrote %s\n", basename($file));
