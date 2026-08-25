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
 */
if ($argc < 3) {
    fwrite(STDERR, "usage: php bench/benchmark_e2e.php <repo root> <label>\n");
    exit(1);
}
[, $root, $label] = $argv;
require "$root/vendor/autoload.php";
use CrazyGoat\ScanMePHP\{QRCode, QRCodeConfig, Encoder, FastEncoder, FfiEncoder, NativeEncoder, ErrorCorrectionLevel, RenderOptions, ModuleStyle};
use CrazyGoat\ScanMePHP\Renderer\{SvgRenderer, HtmlDivRenderer, HtmlTableRenderer, HalfBlocksRenderer, FullBlocksRenderer, SimpleRenderer, PngRenderer};
function bench(callable $f): float { for ($i=0;$i<5;$i++) $f(); $t=hrtime(true); for($i=0;$i<5;$i++) $f(); $est=(hrtime(true)-$t)/5; $n=max(15,min(3000,(int)(4e8/$est))); gc_collect_cycles(); $t=hrtime(true); for($i=0;$i<$n;$i++) $f(); return (hrtime(true)-$t)/1e3/$n; }
$out = fopen("$label.csv", 'w');
function row($out,$sec,$name,$ver,$us,$bytes){ fputcsv($out,[$sec,$name,$ver,round($us,1),$bytes],',','"','\\'); fprintf(STDERR,"%-9s %-22s v%-2d %9.1f us %8d B\n",$sec,$name,$ver,$us,$bytes); }
$payloads = [10, 100, 260, 840, 1440, 2900];
$L = ErrorCorrectionLevel::Low;
$encoders = ['Encoder'=>new Encoder(), 'FastEncoder'=>new FastEncoder(), 'FfiEncoder'=>((new \ReflectionClass(FfiEncoder::class))->getConstructor()->getNumberOfRequiredParameters() ? new FfiEncoder("$root/clib/build/libscanme_qr.dylib") : new FfiEncoder()), 'NativeEncoderExt'=>new NativeEncoder()];
foreach ($payloads as $len) {
  $d = substr(str_repeat('https://example.com/', 200), 0, $len);
  $ver = (new Encoder())->encode($d,$L)->getVersion();
  foreach ($encoders as $n=>$e) {
    if ($n==='FastEncoder' && $ver>27) continue;
    row($out,'encode',$n,$ver,bench(fn()=>$e->encode($d,$L)),0);
  }
}
$renderers = ['SvgRenderer'=>[new SvgRenderer(), new RenderOptions()], 'SvgRenderer rounded'=>[new SvgRenderer(), new RenderOptions(moduleStyle: ModuleStyle::Rounded)], 'SvgRenderer dot'=>[new SvgRenderer(), new RenderOptions(moduleStyle: ModuleStyle::Dot)],
  'HtmlDivRenderer'=>[new HtmlDivRenderer(), new RenderOptions()], 'HtmlTableRenderer'=>[new HtmlTableRenderer(), new RenderOptions()], 'FullBlocksRenderer'=>[new FullBlocksRenderer(), new RenderOptions()],
  'HalfBlocksRenderer'=>[new HalfBlocksRenderer(), new RenderOptions()], 'SimpleRenderer'=>[new SimpleRenderer(), new RenderOptions()], 'PngRenderer'=>[new PngRenderer(), new RenderOptions()]];
foreach ([10, 260, 1440, 2900] as $len) {
  $d = substr(str_repeat('https://example.com/', 200), 0, $len);
  $m = (new NativeEncoder())->encode($d,$L); $m->getRawData(); $ver=$m->getVersion();
  foreach ($renderers as $n=>[$r,$o]) { $b=strlen($r->render($m,$o)); row($out,'render',$n,$ver,bench(fn()=>$r->render($m,$o)),$b); }
}
// e2e: QRCode->render() with explicit encoder
$e2eR = ['HalfBlocksRenderer'=>new HalfBlocksRenderer(), 'SvgRenderer'=>new SvgRenderer(), 'HtmlDivRenderer'=>new HtmlDivRenderer(), 'PngRenderer'=>new PngRenderer()];
foreach ([10, 260, 1440] as $len) {
  $d = substr(str_repeat('https://example.com/', 200), 0, $len);
  foreach (['Encoder'=>new Encoder(), 'NativeEncoderExt'=>new NativeEncoder()] as $en=>$enc) {
    foreach ($e2eR as $rn=>$r) {
      $cfg = new QRCodeConfig(engine: $r, errorCorrectionLevel: $L);
      $ver = (new QRCode($d,$cfg,$enc))->getMatrix()->getVersion();
      $b = strlen((new QRCode($d,$cfg,$enc))->render());
      row($out,'e2e',"$en + $rn",$ver,bench(fn()=>(new QRCode($d,$cfg,$enc))->render()),$b);
    }
  }
}
fclose($out);
