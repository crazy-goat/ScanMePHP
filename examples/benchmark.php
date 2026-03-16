<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use ScanMePHP\Encoder;
use ScanMePHP\FastEncoder;
use ScanMePHP\ErrorCorrectionLevel;

// ============================================================================
// ScanMePHP Encoder Benchmark
//
// Compares Encoder (scalar, portable, v1-v40) vs FastEncoder (int-packed,
// 64-bit only, v1-v27) across multiple QR versions and URL lengths.
//
// Usage:
//   php examples/benchmark.php              # Run with defaults (500 iterations)
//   php examples/benchmark.php 1000         # Custom iteration count
//   php examples/benchmark.php 500 json     # Output as JSON
// ============================================================================

$iterations = (int) ($argv[1] ?? 500);
$outputFormat = $argv[2] ?? 'table';
$warmup = min(50, (int) ($iterations * 0.1));

$urls = [
    'v1 (12B)'    => 'https://a.co',
    'v2 (19B)'    => 'https://example.com',
    'v4 (48B)'    => 'https://example.com/some/path?query=value&foo=bar',
    'v6 (130B)'   => 'https://example.com/' . str_repeat('x', 110),
    'v8 (168B)'   => 'https://example.com/' . str_repeat('y', 148),
    'v11 (250B)'  => 'https://example.com/' . str_repeat('z', 230),
    'v14 (360B)'  => 'https://example.com/' . str_repeat('a', 340),
    'v17 (500B)'  => 'https://example.com/' . str_repeat('b', 480),
    'v20 (660B)'  => 'https://example.com/' . str_repeat('c', 640),
    'v24 (910B)'  => 'https://example.com/' . str_repeat('d', 890),
    'v27 (1120B)' => 'https://example.com/' . str_repeat('e', 1100),
];

$ecl = ErrorCorrectionLevel::Medium;

// ---------------------------------------------------------------------------
// Benchmark runner
// ---------------------------------------------------------------------------

function benchEncode(object $encoder, string $url, ErrorCorrectionLevel $ecl, int $warmup, int $iterations): array
{
    // Warmup — populate caches, JIT warmup
    for ($i = 0; $i < $warmup; $i++) {
        $encoder->encode($url, $ecl);
    }

    // Measure
    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = hrtime(true);
        $encoder->encode($url, $ecl);
        $elapsed = (hrtime(true) - $start) / 1_000_000; // ms
        $times[] = $elapsed;
    }

    sort($times);
    $count = count($times);

    return [
        'mean' => round(array_sum($times) / $count, 4),
        'p50'  => round($times[(int) ($count * 0.50)], 4),
        'p95'  => round($times[(int) ($count * 0.95)], 4),
        'min'  => round($times[0], 4),
    ];
}

// ---------------------------------------------------------------------------
// Run benchmarks
// ---------------------------------------------------------------------------

fprintf(STDERR, "ScanMePHP Encoder Benchmark\n");
fprintf(STDERR, "PHP %s (%d-bit) | %d iterations | %d warmup\n\n", PHP_VERSION, PHP_INT_SIZE * 8, $iterations, $warmup);

$encoder = new Encoder();
$fastEncoder = new FastEncoder();

$results = [];

foreach ($urls as $label => $url) {
    fprintf(STDERR, "  %-14s ", $label);

    // Scale iterations down for larger URLs (Encoder gets very slow)
    $urlLen = strlen($url);
    $scaledIterations = $urlLen > 500 ? max(20, (int)($iterations * 100 / $urlLen)) : $iterations;
    $scaledWarmup = min(10, (int)($scaledIterations * 0.1));

    $encResult = benchEncode($encoder, $url, $ecl, $scaledWarmup, $scaledIterations);
    fprintf(STDERR, "Encoder ✓  ");

    $fastResult = benchEncode($fastEncoder, $url, $ecl, $scaledWarmup, $scaledIterations);
    fprintf(STDERR, "FastEncoder ✓\n");

    // Verify both produce valid matrices
    $m1 = $encoder->encode($url, $ecl);
    $m2 = $fastEncoder->encode($url, $ecl);
    assert($m1->getVersion() === $m2->getVersion(), "Version mismatch for $label");
    assert($m1->getSize() === $m2->getSize(), "Size mismatch for $label");

    $speedup = $encResult['p50'] / $fastResult['p50'];

    $results[$label] = [
        'url_bytes'   => strlen($url),
        'version'     => $m1->getVersion(),
        'encoder'     => $encResult,
        'fastencoder' => $fastResult,
        'speedup'     => round($speedup, 1),
    ];
}

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------

if ($outputFormat === 'json') {
    echo json_encode([
        'php_version' => PHP_VERSION,
        'int_size'    => PHP_INT_SIZE,
        'iterations'  => $iterations,
        'warmup'      => $warmup,
        'ecl'         => 'Medium',
        'date'        => date('Y-m-d H:i:s'),
        'results'     => $results,
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

// Table output
echo "\n";
echo "┌─────────────┬─────────────────────────────┬─────────────────────────────┬──────────┐\n";
echo "│             │     Encoder (portable)      │    FastEncoder (64-bit)     │          │\n";
echo "│  Version    │   p50      p95      mean    │   p50      p95      mean    │ Speedup  │\n";
echo "├─────────────┼─────────────────────────────┼─────────────────────────────┼──────────┤\n";

foreach ($results as $label => $r) {
    $e = $r['encoder'];
    $f = $r['fastencoder'];
    printf(
        "│ %-11s │ %6.3fms %6.3fms %6.3fms │ %6.3fms %6.3fms %6.3fms │  %5.1f×  │\n",
        $label,
        $e['p50'], $e['p95'], $e['mean'],
        $f['p50'], $f['p95'], $f['mean'],
        $r['speedup']
    );
}

echo "└─────────────┴─────────────────────────────┴─────────────────────────────┴──────────┘\n";
echo "\n";
echo "PHP " . PHP_VERSION . " (" . (PHP_INT_SIZE * 8) . "-bit) | $iterations iterations | ECL: Medium\n";
echo "p50 = median, p95 = 95th percentile, mean = arithmetic mean\n";
echo "\n";
