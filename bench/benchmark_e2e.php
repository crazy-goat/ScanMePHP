<?php

declare(strict_types=1);

/*
 * Component + end-to-end benchmark used for OPTIMIZATION_RESULTS_2026-08.md.
 *
 * usage: php -d extension=<root>/php-ext/modules/scanmeqr.so \
 *            -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M \
 *            bench/benchmark_e2e.php <repo root> <label>
 *
 * Prints every case to stderr and writes <label>.csv (section,name,version,us,bytes)
 * to the current directory. <root> may point at another checkout (e.g. a git
 * worktree of an older commit with its own vendor/, clib/build and php-ext build).
 *
 * Three sections, because they answer different questions: `encode` compares
 * the four QR backends, `render` compares the output formats over one symbol,
 * and `e2e` measures what a caller actually pays for a Scanme::render() call.
 */
$arguments = $_SERVER['argv'];

if (\count($arguments) < 3) {
    fwrite(STDERR, "usage: php bench/benchmark_e2e.php <repo root> <label>\n");
    exit(1);
}

[, $root, $label] = $arguments;
require "$root/vendor/autoload.php";

use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\FastEncoder;
use CrazyGoat\ScanMePHP\FfiEncoder;
use CrazyGoat\ScanMePHP\Generator\BackendInterface;
use CrazyGoat\ScanMePHP\Generator\Qr\QrGenerator;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\NativeEncoder;
use CrazyGoat\ScanMePHP\Renderer\Options\AsciiOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\HtmlOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\SvgOptions;
use CrazyGoat\ScanMePHP\Scanme;

/**
 * Auto-scaled iteration count: cheap cases run more often, not longer.
 *
 * A closure rather than a function, because the other scripts in bench/ define
 * a bench() of their own with a different signature.
 */
$bench = static function (callable $f): float {
    for ($i = 0; $i < 5; $i++) {
        $f();
    }
    $t = hrtime(true);
    for ($i = 0; $i < 5; $i++) {
        $f();
    }
    $est = (hrtime(true) - $t) / 5;
    $n = max(15, min(3000, (int) (4e8 / $est)));
    gc_collect_cycles();
    $t = hrtime(true);
    for ($i = 0; $i < $n; $i++) {
        $f();
    }

    return (hrtime(true) - $t) / 1e3 / $n;
};

$out = fopen("$label.csv", 'w');

$row = static function (string $sec, string $name, int $ver, float $us, int $bytes) use ($out): void {
    fputcsv($out, [$sec, $name, $ver, round($us, 1), $bytes], ',', '"', '\\');
    fprintf(STDERR, "%-9s %-26s v%-2d %9.1f us %8d B\n", $sec, $name, $ver, $us, $bytes);
};

$payloads = [10, 100, 260, 840, 1440, 2900];
$L = ErrorCorrectionLevel::Low;
$data = static fn (int $len): string => substr(str_repeat('https://example.com/', 200), 0, $len);

// --- encode: the four QR backends, addressed directly ------------------------
// Not through the facade: the point is to compare them, and the facade's whole
// job is to hide which one is running.
$encoders = [
    'Encoder' => new Encoder(),
    'FastEncoder' => new FastEncoder(),
    'FfiEncoder' => new FfiEncoder(sprintf(
        '%s/clib/build/libscanme_qr.%s',
        $root,
        PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so'
    )),
    'NativeEncoderExt' => new NativeEncoder(),
];

foreach ($payloads as $len) {
    $d = $data($len);
    $ver = (new Encoder())->encode($d, $L)->getVersion();
    foreach ($encoders as $n => $e) {
        // FastEncoder's 32-bit windows cannot address the largest versions.
        if ($n === 'FastEncoder' && $ver > 27) {
            continue;
        }
        $row('encode', $n, $ver, $bench(fn () => $e->encode($d, $L)), 0);
    }
}

// --- render: every output format over one already-built symbol ---------------
$scanme = new Scanme($registry = Defaults::registry());

$variants = [
    'svg' => ['svg', new SvgOptions()],
    'svg rounded' => ['svg', new SvgOptions(moduleStyle: \CrazyGoat\ScanMePHP\ModuleStyle::Rounded)],
    'svg dot' => ['svg', new SvgOptions(moduleStyle: \CrazyGoat\ScanMePHP\ModuleStyle::Dot)],
    'png' => ['png', new PngOptions()],
    'html-div' => ['html-div', new HtmlOptions()],
    'html-table' => ['html-table', new HtmlOptions()],
    'ascii-blocks' => ['ascii-blocks', new AsciiOptions()],
    'ascii-half-blocks' => ['ascii-half-blocks', new AsciiOptions()],
    'ascii-dots' => ['ascii-dots', new AsciiOptions()],
];

foreach ([10, 260, 1440, 2900] as $len) {
    $d = $data($len);
    $symbol = $scanme->generate($d, 'qrcode', new QrOptions(errorCorrection: $L));
    $ver = (int) $symbol->getMetadataValue('version');

    foreach ($variants as $name => [$format, $options]) {
        $renderer = $registry->getRenderer($format);
        $bytes = \strlen($renderer->render($symbol, $options));
        $row('render', $name, $ver, $bench(fn () => $renderer->render($symbol, $options)), $bytes);
    }
}

// --- e2e: what a caller pays for one Scanme::render() ------------------------
// Encoding plus rendering plus the facade's own routing, which is the number
// that matters to anyone who is not optimising this library.
$e2e = [
    'ascii-half-blocks' => new AsciiOptions(),
    'svg' => new SvgOptions(),
    'html-div' => new HtmlOptions(),
    'png' => new PngOptions(),
];

foreach ([10, 260, 1440] as $len) {
    $d = $data($len);
    $ver = (int) $scanme->generate($d, 'qrcode', new QrOptions(errorCorrection: $L))->getMetadataValue('version');

    foreach (['portable', 'bitset', 'ffi', 'native'] as $backend) {
        // Each backend gets its own generator so forcing one cannot leak into
        // the next case. A backend absent on this host is skipped rather than
        // reported as slow: an unavailable encoder has no timing, only a reason.
        $generator = new QrGenerator();
        $selector = $generator->getBackendSelector();

        $runnable = array_map(
            static fn (BackendInterface $b): string => $b->getName(),
            $selector->available()
        );

        if (!\in_array($backend, $runnable, true)) {
            fprintf(STDERR, "e2e       %-26s skipped (not available on this host)\n", $backend);

            continue;
        }

        $selector->force($backend);
        $local = new Scanme(Defaults::registry()->addGenerator($generator));

        foreach ($e2e as $format => $options) {
            $render = static fn (): string => $local->render(
                $d,
                'qrcode',
                $format,
                new QrOptions(errorCorrection: $L),
                $options
            );

            $row('e2e', "{$backend} + {$format}", $ver, $bench($render), \strlen($render()));
        }
    }
}

fclose($out);
