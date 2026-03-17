<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\FastEncoder;
use CrazyGoat\ScanMePHP\FfiEncoder;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;

$libraryPath = __DIR__ . '/../clib/build/libscanme_qr.so';

$iterations = isset($argv[1]) ? (int) $argv[1] : 200;

$testCases = [
    ['label' => 'v1  (21x21)  L', 'data' => 'https://ex.io',          'ecl' => ErrorCorrectionLevel::Low],
    ['label' => 'v2  (25x25)  M', 'data' => 'https://example.com',     'ecl' => ErrorCorrectionLevel::Medium],
    ['label' => 'v3  (29x29)  H', 'data' => 'https://example.com',     'ecl' => ErrorCorrectionLevel::High],
    ['label' => 'v5  (37x37)  M', 'data' => 'https://scanmephp.example.com/very/long/url/path?query=value&other=123', 'ecl' => ErrorCorrectionLevel::Medium],
    ['label' => 'v10 (57x57)  M', 'data' => str_repeat('https://example.com/', 13), 'ecl' => ErrorCorrectionLevel::Medium],
    ['label' => 'v10 (57x57)  L', 'data' => str_repeat('https://example.com/', 13), 'ecl' => ErrorCorrectionLevel::Low],
];

function bench(callable $fn, int $n): float
{
    for ($i = 0; $i < min(3, $n); $i++) $fn();
    gc_collect_cycles();
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) $fn();
    return (hrtime(true) - $t) / 1e6 / $n;
}

$fastAvailable = PHP_INT_SIZE >= 8;
$ffiAvailable  = FfiEncoder::isAvailable($libraryPath);

echo "Encoder benchmark — {$iterations} iterations per case\n";
echo "FastEncoder: " . ($fastAvailable ? "available (64-bit PHP)" : "NOT available (requires 64-bit PHP)") . "\n";
echo "FfiEncoder:  " . ($ffiAvailable  ? "available ({$libraryPath})" : "NOT available (build clib first)") . "\n";
echo "\n";

$fmt = "%-22s  %10s  %10s  %10s  %8s  %8s\n";
printf($fmt, 'Test case', 'PHP (ms)', 'Fast (ms)', 'FFI (ms)', 'PHP/Fast', 'PHP/FFI');
echo str_repeat('-', 78) . "\n";

$phpEncoder  = new Encoder();
$fastEncoder = $fastAvailable ? new FastEncoder() : null;
$ffiEncoder  = $ffiAvailable  ? new FfiEncoder($libraryPath) : null;

foreach ($testCases as $tc) {
    $data = $tc['data'];
    $ecl  = $tc['ecl'];

    $phpMs  = bench(fn() => $phpEncoder->encode($data, $ecl), $iterations);
    $fastMs = $fastEncoder !== null ? bench(fn() => $fastEncoder->encode($data, $ecl), $iterations) : null;
    $ffiMs  = $ffiEncoder  !== null ? bench(fn() => $ffiEncoder->encode($data, $ecl), $iterations)  : null;

    printf($fmt,
        $tc['label'],
        number_format($phpMs, 3),
        $fastMs !== null ? number_format($fastMs, 3) : 'N/A',
        $ffiMs  !== null ? number_format($ffiMs,  3) : 'N/A',
        $fastMs !== null ? sprintf('%.2fx', $phpMs / $fastMs) : 'N/A',
        $ffiMs  !== null ? sprintf('%.2fx', $phpMs / $ffiMs)  : 'N/A',
    );
}

echo str_repeat('-', 78) . "\n";
