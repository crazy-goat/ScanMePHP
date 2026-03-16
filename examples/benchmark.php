<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\QRCode;
use CrazyGoat\ScanMePHP\QRCodeConfig;
use CrazyGoat\ScanMePHP\Renderer\PngRenderer;
use CrazyGoat\ScanMePHP\Renderer\SvgRenderer;
use CrazyGoat\ScanMePHP\Renderer\HtmlDivRenderer;
use CrazyGoat\ScanMePHP\Renderer\HtmlTableRenderer;
use CrazyGoat\ScanMePHP\Renderer\FullBlocksRenderer;
use CrazyGoat\ScanMePHP\Renderer\HalfBlocksRenderer;
use CrazyGoat\ScanMePHP\Renderer\SimpleRenderer;

function showUsage(): void
{
    echo "Usage: php benchmark.php <renderer> <iterations> [data_size]\n";
    echo "\n";
    echo "Available renderers:\n";
    echo "  png          - PngRenderer\n";
    echo "  svg          - SvgRenderer\n";
    echo "  html-div     - HtmlDivRenderer\n";
    echo "  html-table   - HtmlTableRenderer\n";
    echo "  full-blocks  - FullBlocksRenderer\n";
    echo "  half-blocks  - HalfBlocksRenderer\n";
    echo "  simple       - SimpleRenderer\n";
    echo "  all          - Run all renderers\n";
    echo "\n";
    echo "Examples:\n";
    echo "  php benchmark.php svg 100\n";
    echo "  php benchmark.php png 50 500\n";
    echo "  php benchmark.php all 20\n";
    exit(1);
}

if ($argc < 3) {
    showUsage();
}

$rendererName = $argv[1];
$iterations = (int) $argv[2];
$dataSize = isset($argv[3]) ? (int) $argv[3] : 300;

if ($iterations < 1) {
    echo "Error: iterations must be at least 1\n";
    exit(1);
}

// Test data
$testData = str_repeat('https://example.com/', (int) ceil($dataSize / 20));
echo "Test data length: " . strlen($testData) . " bytes\n";
echo "Iterations: $iterations\n";
echo "\n";

$renderers = [
    'png' => ['class' => PngRenderer::class, 'args' => [4]],
    'svg' => ['class' => SvgRenderer::class, 'args' => [4]],
    'html-div' => ['class' => HtmlDivRenderer::class, 'args' => [4]],
    'html-table' => ['class' => HtmlTableRenderer::class, 'args' => [4]],
    'full-blocks' => ['class' => FullBlocksRenderer::class, 'args' => []],
    'half-blocks' => ['class' => HalfBlocksRenderer::class, 'args' => []],
    'simple' => ['class' => SimpleRenderer::class, 'args' => []],
];

function benchmark(string $name, callable $fn, int $iterations): array
{
    // Warmup
    for ($i = 0; $i < min(3, $iterations); $i++) {
        $fn();
    }

    gc_collect_cycles();
    
    $startMemory = memory_get_usage(true);
    $startTime = hrtime(true);

    for ($i = 0; $i < $iterations; $i++) {
        $fn();
    }

    $endTime = hrtime(true);
    $endMemory = memory_get_usage(true);
    
    $totalTimeMs = ($endTime - $startTime) / 1e6;
    $avgTimeMs = $totalTimeMs / $iterations;
    $memoryDiffKb = ($endMemory - $startMemory) / 1024;

    return [
        'name' => $name,
        'avg_time_ms' => round($avgTimeMs, 3),
        'total_time_ms' => round($totalTimeMs, 2),
        'memory_kb' => round($memoryDiffKb, 2),
        'iterations' => $iterations,
    ];
}

function runBenchmark(string $name, array $rendererConfig, string $testData, int $iterations): array
{
    $class = $rendererConfig['class'];
    $args = $rendererConfig['args'];
    
    return benchmark($name, function () use ($class, $args, $testData) {
        $renderer = new $class(...$args);
        $config = new QRCodeConfig(engine: $renderer);
        $qr = new QRCode($testData, $config);
        $qr->render();
    }, $iterations);
}

function printResult(array $r): void
{
    echo sprintf(
        "%-20s %10.3f ms %10.2f ms %10.2f KB\n",
        $r['name'],
        $r['avg_time_ms'],
        $r['total_time_ms'],
        $r['memory_kb']
    );
}

// Header
echo sprintf("%-20s %12s %12s %12s\n", "Renderer", "Avg Time", "Total Time", "Memory");
echo str_repeat("-", 60) . "\n";

$results = [];

if ($rendererName === 'all') {
    foreach ($renderers as $key => $config) {
        $results[] = runBenchmark($key, $config, $testData, $iterations);
        printResult(end($results));
    }
} else {
    if (!isset($renderers[$rendererName])) {
        echo "Error: Unknown renderer '$rendererName'\n";
        showUsage();
    }
    $results[] = runBenchmark($rendererName, $renderers[$rendererName], $testData, $iterations);
    printResult($results[0]);
}

echo str_repeat("-", 60) . "\n";

// Save results
$resultsFile = __DIR__ . '/benchmark_' . $rendererName . '_' . time() . '.json';
file_put_contents($resultsFile, json_encode($results, JSON_PRETTY_PRINT));
echo "\nResults saved to: $resultsFile\n";
