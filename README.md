# ScanMePHP

**A universal barcode library for PHP — with optional native C++ acceleration for QR.**

Seven symbologies, seven output formats, one call. No dependencies, no GD, no
Imagick, no extensions required — then go **7–9× faster** on QR with a single
C++ library if you generate them in volume.

QR encoding algorithms are based on [Nayuki's QR Code generator](https://www.nayuki.io/page/qr-code-generator-library).

```php
use CrazyGoat\ScanMePHP\Scanme;

$scanme = Scanme::create();

echo $scanme->render('https://example.com', 'qrcode', 'svg');
echo $scanme->render('5901234123457', 'ean13', 'png');
```

## Why ScanMePHP?

**📇 Seven symbologies, one API**

| Symbology | Accepts | Notes |
| --- | --- | --- |
| `qrcode` | any byte string, up to 2953 bytes | v1–v40, error correction L/M/Q/H |
| `data-matrix` | any byte string, up to 1556 bytes | ECC200, square or rectangular |
| `code128` | printable ASCII | automatic set switching |
| `ean13` | 12 digits, or 13 with a check digit | the retail default |
| `ean8` | 7 digits, or 8 with a check digit | for packaging that cannot carry an EAN-13 |
| `upc-a` | 11 digits, or 12 with a check digit | bit for bit an EAN-13 with a leading zero |
| `upc-e` | 7 or 8 digits, or a UPC-A that compresses | zero-suppressed, parity-carried check digit |

Adding your own is a first-class path, not a fork: implement one interface,
register it, and it resolves by name and alias like everything else.

**🎨 Seven output formats**

SVG, PNG (pure PHP, 1-bit), HTML (div/table) and three terminal styles. Works
in browsers, emails, print and an SSH session.

**✅ Verified against an independent decoder**

Every symbology in this library is round-tripped through
[zxing-cpp](https://github.com/zxing-cpp/zxing-cpp) on every CI build: a real
scanner reads the payload back out of a rendered PNG. Checking an encoder
against tables transcribed from the same standard it implements cannot catch a
table that is wrong in the same direction as its test — and a barcode that is
wrong but scannable fails at the till, not in the suite.

**🚀 Blazing fast on QR — three tiers**
- **Fast pure PHP**: bitwise mask selection and packed Reed–Solomon — a v10 code in ~60 µs with the JIT, no extensions needed
- **Native C++ via FFI / extension**: another 6–8× (a v10 code in ~8–9 µs); SIMD mask selection with runtime AVX2/AVX-512 dispatch on x86-64, NEON on arm64
- **Portable fallback**: works on any PHP 8.2+, 32-bit or 64-bit

Auto-selects the fastest encoder available — no configuration needed. The
linear symbologies are pure PHP throughout and cost microseconds either way.

**📦 Zero dependencies**
- No Composer packages to install
- No GD, Imagick, or extensions required
- Single `composer require`, instant barcodes

**🔧 Type-safe by construction**
- Strict types, enums instead of magic strings, readonly option bags
- Incompatible symbology/renderer pairs are reported by name, never drawn wrong
- PHP 8.2+ idioms throughout

## Installation

```bash
composer require crazy-goat/scanmephp
```

## Binary Auto-Download

When you install or update the package via Composer, the library will automatically:

1. Detect your platform (Linux glibc/musl, macOS Intel/ARM)
2. Try to download and install the PHP extension (`scanmeqr`) — **fastest option** (190–360× faster)
3. Fall back to FFI library if extension is not available — **90–130× faster**
4. Use pure PHP encoder as final fallback — works everywhere

If no binary matches your platform — arm64 Linux, an unusual PHP build — the extension can be
compiled on the spot with [PIE](https://github.com/php/pie): `pie install crazy-goat/qrcode-ext`.

### PHP Extension Installation (Recommended)

The PHP extension provides the best performance. The Composer plugin will attempt to download it automatically.

#### Auto-Download

During `composer install` or `composer update`, the plugin will:

1. Check if the `scanmeqr` extension is already loaded
2. Download the appropriate prebuilt binary for your platform
3. Provide instructions to enable it in `php.ini`

#### Manual Installation

1. Download the appropriate binary from [GitHub Releases](https://github.com/crazy-goat/ScanMePHP/releases):

| Platform | PHP 8.2 | PHP 8.3 | PHP 8.4 |
|----------|---------|---------|---------|
| Linux (glibc) | `php-ext-linux-glibc-x86_64-php82.so` | `php-ext-linux-glibc-x86_64-php83.so` | `php-ext-linux-glibc-x86_64-php84.so` |
| Linux (musl/Alpine) | `php-ext-linux-musl-x86_64-php82.so` | `php-ext-linux-musl-x86_64-php83.so` | `php-ext-linux-musl-x86_64-php84.so` |
| macOS Intel | `php-ext-macos-x86_64-php82.so` | `php-ext-macos-x86_64-php83.so` | `php-ext-macos-x86_64-php84.so` |
| macOS Apple Silicon | `php-ext-macos-arm64-php82.so` | `php-ext-macos-arm64-php83.so` | `php-ext-macos-arm64-php84.so` |

> **Note:** Binaries are built for specific PHP versions due to ABI compatibility. Make sure to download the binary matching your PHP version (check with `php -v`).

2. Copy to your PHP extensions directory:
   ```bash
   cp php-ext-linux-glibc-x86_64.so $(php-config --extension-dir)/
   ```

3. Add to your `php.ini`:
   ```ini
   extension=scanmeqr.so
   ```

4. Restart your web server or PHP-FPM:
   ```bash
   sudo systemctl restart php-fpm
   # or
   sudo systemctl restart apache2
   ```

5. Verify installation:
   ```bash
   php -m | grep scanmeqr
   ```

#### Installing with PIE

The extension is published as a [PIE](https://github.com/php/pie) package, which builds it from
source for whatever PHP you are running — including platforms no prebuilt binary covers, such as
arm64 Linux:

```bash
composer require crazy-goat/scanmephp
pie install crazy-goat/qrcode-ext
```

Both halves are needed: the extension builds a `CrazyGoat\ScanMePHP\Matrix` and can only throw
without the library loaded. Building needs a C++20 compiler and takes a few seconds; there is
nothing else to install, since the C++ core is compiled into the extension rather than linked
against `libscanme_qr`.

[crazy-goat/qrcode-ext](https://github.com/crazy-goat/qrcode-ext) is generated from `php-ext/`
and `clib/` by `bin/build-ext-mirror.sh` — issues and pull requests belong in this repository.

#### Building from Source

Requirements:
- PHP 8.2+ with `php-dev`/`phpize`
- C++20 compiler (GCC 10+ or Clang 12+)
- Make

```bash
cd php-ext
phpize
./configure          # finds ../clib on its own
make -j$(nproc)
make install
cd ..
```

Then add `extension=scanmeqr.so` to your `php.ini`.

CMake is only needed for the FFI library and the C++ test suite; the extension does not use it.

### FFI Library Installation

If the PHP extension is not available, the plugin will download the FFI library instead.

#### Requirements for Auto-Download

- FFI extension (`extension=ffi` in php.ini)
- cURL extension for downloading
- Write permissions to `ffi-binaries/` directory in your project

#### Manual Binary Installation

If auto-download doesn't work, you can manually download binaries from the
[GitHub releases page](https://github.com/crazy-goat/scanmephp/releases) and place
them in your project directory.

Prebuilt FFI library binaries are available for:

| Platform | Binary |
|----------|--------|
| Linux (glibc) | `libscanme_qr-linux-glibc-x86_64.so` |
| Linux (musl/Alpine) | `libscanme_qr-linux-musl-x86_64.so` |
| macOS Intel | `libscanme_qr-macos-x86_64.dylib` |
| macOS Apple Silicon | `libscanme_qr-macos-arm64.dylib` |

> **Windows:** no prebuilt binaries are published. ScanMePHP still works —
> it falls back to the pure-PHP encoder, which needs no extension and no FFI.
> For native speed on Windows, build `clib/` from source with MSVC and point
> `FfiEncoder` at the resulting `scanme_qr.dll`.

## Quick Start

```php
use CrazyGoat\ScanMePHP\Scanme;

$scanme = Scanme::create();

// Pick a symbology, pick a format, get bytes.
echo $scanme->render('https://example.com', 'qrcode', 'svg');
```

That is the whole API surface for the common case. There is no builder to
assemble, no engine to inject, and no object to keep alive between calls.

Enums exist for everything built in, so a typo is a static-analysis concern
rather than an exception at render time:

```php
use CrazyGoat\ScanMePHP\Format;
use CrazyGoat\ScanMePHP\Symbology;

echo $scanme->render('5901234123457', Symbology::Ean13, Format::Png);
```

Both forms are accepted everywhere, because the registry is open: a generator
you register yourself must be addressable as a first-class citizen, and a
closed enum would make it a second-class one.

## Symbologies

```php
$scanme->render('https://example.com', 'qrcode', 'svg');
$scanme->render('ScanMePHP', 'data-matrix', 'svg');
$scanme->render('SHIPMENT-4471', 'code128', 'png');
$scanme->render('5901234123457', 'ean13', 'svg');
$scanme->render('96385074', 'ean8', 'svg');
$scanme->render('036000291452', 'upc-a', 'svg');
$scanme->render('04252614', 'upc-e', 'svg');
```

Aliases resolve too — `ean`, `ean-13`, `upc`, `upca`, `dm`, `ecc200`, `qr`.

If you have a payload and are not sure which symbologies accept it, ask rather
than guess:

```php
$scanme->getRegistry()->generatorsFor('036000291452');
// ['qrcode', 'code128', 'ean13', 'upc-a', 'data-matrix']
```

And to see what is installed, with the rules each one enforces:

```php
foreach ($scanme->getRegistry()->describeGenerators() as $name => $capabilities) {
    printf("%-12s %s — %s\n", $name, $capabilities->title, $capabilities->dataDescription);
}
```

## Output formats

| Format | Content type | Options class |
| --- | --- | --- |
| `svg` | `image/svg+xml` | `SvgOptions` |
| `png` | `image/png` | `PngOptions` |
| `html-div` | `text/html` | `HtmlOptions` |
| `html-table` | `text/html` | `HtmlOptions` |
| `ascii-blocks` | `text/plain` | `AsciiOptions` |
| `ascii-half-blocks` | `text/plain` | `AsciiOptions` |
| `ascii-dots` | `text/plain` | `AsciiOptions` |

`ascii-half-blocks` packs two module rows into one character cell, which is why
a QR code fits in a normal terminal window.

## Options

Options come in two kinds, and the split is deliberate. **Generator options**
change what is encoded — a higher QR error correction level spends symbol
capacity and can grow the symbol. **Render options** change how the same
modules are drawn.

```php
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\ModuleStyle;
use CrazyGoat\ScanMePHP\Renderer\Options\SvgOptions;

echo $scanme->render(
    'https://example.com',
    'qrcode',
    'svg',
    new QrOptions(errorCorrection: ErrorCorrectionLevel::High),
    new SvgOptions(
        moduleSize: 8,
        foregroundColor: '#1B3A57',
        backgroundColor: '#F5F0E1',
        moduleStyle: ModuleStyle::Rounded,
        label: 'Scan me',
    ),
);
```

Bags are routed by the interface they implement, so order does not matter and
either may be omitted. A bag nobody claims is an error rather than a silent
no-op — the options that do not fit are exactly the ones you cared about.

### Render options

Every renderer's bag carries these:

| Option | Default | Meaning |
| --- | --- | --- |
| `moduleSize` | `10` | Pixels (or CSS px) per module; fixed at 1 for ASCII |
| `quietZone` | `null` | Override the margin; `null` uses what the symbology requires |
| `barHeight` | `null` | Height of the bars, in modules; `null` uses the symbology's own |
| `foregroundColor` | `#000000` | Dark modules |
| `backgroundColor` | `#FFFFFF` | Light modules |
| `invert` | `false` | Swap the two |
| `label` | `null` | Caption drawn under the symbol |
| `showText` | `true` | Print the human-readable interpretation a linear symbology carries |

Plus, per renderer: `SvgOptions` adds `moduleStyle` and `roundFinderRegions`,
`PngOptions` adds `compressionLevel` (0–9), `HtmlOptions` adds `fullDocument`
and `title`, and `AsciiOptions` adds `sideMargin`.

The quiet zone default is worth knowing about: four modules for QR, eleven left
and seven right for EAN-13, nine and seven for UPC-E. Those widths are part of
being scannable, not a matter of taste. An explicit value still wins, including
a smaller or zero one — a caller rendering a preview into a tight layout is
entitled to that, and it is their call to make.

### Generator options

`QrOptions` takes `errorCorrection` (L/M/Q/H) and an optional `version` floor.
`DataMatrixOptions` takes `rectangular` and an optional exact `size`. The
linear symbologies take none: their geometry is fixed by the standard.

## Output methods

```php
// A string
$svg = $scanme->render('https://example.com', 'qrcode', 'svg');

// A file, written under LOCK_EX so a concurrent request cannot read half of it
$scanme->toFile('/var/www/qr.png', 'https://example.com', 'qrcode', 'png');

// A data: URI for an <img src> or a CSS background
$uri = $scanme->dataUri('https://example.com', 'qrcode', 'svg');

// The modules themselves, for a custom renderer or an image pipeline
$symbol = $scanme->generate('https://example.com', 'qrcode');

// One encode, many outputs
$png = $scanme->renderSymbol($symbol, 'png');
$svg = $scanme->renderSymbol($symbol, 'svg');

// The MIME type, so a controller never hardcodes one
$scanme->getContentType('svg');   // image/svg+xml
```

In a controller that is:

```php
return new Response(
    $scanme->render($payload, Symbology::QrCode, Format::Svg),
    200,
    ['Content-Type' => $scanme->getContentType(Format::Svg)],
);
```

## When a pair does not fit

Renderers are swappable, including ones written outside this library, so the
facade cannot assume every renderer copes with every symbol. A renderer that
paints character cells has no way to draw MaxiCode's hexagons; one with no font
engine cannot print the digits an EAN symbol supplies. The alternative to
reporting the mismatch is emitting something that looks like a barcode and does
not scan.

```php
$scanme->supports('ean13', 'svg');   // true — asked without encoding anything
```

`supports()` answers before encoding, so it sees only symbology-level facts.
For a question about one particular payload, ask about a built symbol:

```php
use CrazyGoat\ScanMePHP\Compatibility;

$reasons = Compatibility::check($symbol, $renderer, $options);
// [] when it renders; otherwise one plain-language reason per problem
```

Rendering an impossible pair throws `IncompatibleRendererException` naming
every reason. Data a symbology cannot take at all throws
`UnsupportedDataException`, naming what it does accept:

```
The UPC-E symbology cannot encode the given data;
it accepts 7 or 8 UPC-E digits, or a UPC-A that compresses to one
```

## Extending

Nothing in the default registry is privileged. Take it, add your own, hand it
to `Scanme` — a registration under an existing name replaces it, which is how
you swap the SVG renderer for one that suits your house style without forking.

```php
use CrazyGoat\ScanMePHP\Defaults;
use CrazyGoat\ScanMePHP\Scanme;

$scanme = new Scanme(
    Defaults::registry()
        ->addRenderer(new MyPdfRenderer())
        ->addGenerator(new PharmacodeGenerator())
);

echo $scanme->render('117', 'pharmacode', 'pdf');
```

A renderer implements four methods (`getFormat`, `getContentType`,
`getCapabilities`, `render`) and receives a `Symbol` — a plain two-level bitmap
with a width, a height and optional per-row heights — so it needs no knowledge
of the symbology that produced it. A generator implements four as well
(`getCapabilities`, `canEncode`, `generate`, `getActiveBackend`) and owns its
own backend selection; there is no base class to inherit.

`examples/07_extending.php` builds all three — a renderer, a symbology and a
backend — end to end.

## Performance

QR ships four interchangeable encoding backends producing identical modules.
The generator picks the fastest one that can run on the host, at runtime, with
no configuration — which one won is visible only through introspection:

```php
use CrazyGoat\ScanMePHP\Generator\Qr\QrGenerator;

$qr = new QrGenerator();
$qr->getActiveBackend()?->getName();          // 'native', 'ffi', 'bitset' or 'portable'
$qr->getBackendSelector()->force('portable'); // pin one, for a benchmark or a test
```


| Backend | Versions | Requirements | Relative Speed |
|---|---|---|---|
| `native` | v1–v40 | 64-bit PHP + `scanmeqr` extension | **7–9×** faster (a v10 code in ~7 µs) |
| `ffi` | v1–v40 | 64-bit PHP + FFI + `libscanme_qr.so` | **6–8×** faster (a v10 code in ~8 µs) |
| `bitset` | v1–v27 | 64-bit PHP | baseline (bitset fast path) |
| `portable` | v1–v40 | PHP 8.2+, 32- or 64-bit | baseline — same fast path for v1–v27, scalar pipeline for v28–v40 |

The other six symbologies are pure PHP throughout and encode in single-digit
microseconds; for them the renderer is the cost that matters.

### Capacity (Byte Mode)

Maximum data length for URL/text encoding (Byte mode) at different QR versions:

| Version | Size | L (Low) | M (Medium) | Q (Quartile) | H (High) |
|---|---|---|---|---|---|
| v1 | 21×21 | 17 | 14 | 11 | 7 |
| v10 | 57×57 | 271 | 213 | 151 | 119 |
| v27 | 125×125 | 1465 | 1125 | 805 | 625 |
| v40 | 177×177 | **2953** | **2331** | **1663** | **1273** |

**Note:** the `bitset` backend supports up to v27 (1465 bytes max). For larger data, the portable Encoder's v28–v40 pipeline is automatically used.

### Benchmark Results

Measured on PHP 8.5 (`opcache.jit=tracing`) / Apple M-series, 500 iterations per case, median latency:

| Test case | `portable` | `bitset` | `ffi` | `native` | Speedup (portable/native) |
|---|---|---|---|---|---|
| v1 (21×21) L | 0.016 ms | 0.015 ms | 0.003 ms | 0.002 ms | **7×** |
| v5 (37×37) L | 0.031 ms | 0.031 ms | 0.004 ms | 0.004 ms | **8×** |
| v10 (57×57) L | 0.061 ms | 0.060 ms | 0.008 ms | 0.009 ms | **7×** |
| v20 (97×97) L | 0.195 ms | 0.200 ms | 0.025 ms | 0.030 ms | **6.5×** |

Before the 2026-08 optimisation pass pure PHP took 0.425 ms (v1) and 3.2 ms
(v10); the pure-PHP encoders are now 20–50× faster, so the native tiers matter
mostly for high-volume generation. Without the JIT pure PHP is ~4× slower.

The C++ library alone encodes v1 in ~1.5 µs, v10 in ~6 µs and v40 in ~80 µs
(`clib/bench/scanme_bench`); the rest is the PHP boundary.

All four encoders produce identical, spec-compliant QR codes verified against [nayuki's reference implementation](https://www.nayuki.io/page/qr-code-generator-library).

Run the benchmark yourself:

```bash
php bench/benchmark_encoder.php        # QR encoding, 200 iterations
php bench/benchmark_encoder.php 500    # 500 iterations
php -d extension=php-ext/modules/scanmeqr.so bench/benchmark_all.php 500   # incl. the extension

php bench/benchmark_render.php all 200            # every output format, one QR symbol
php bench/benchmark_render.php svg 500 1400       # one format, a 1400-byte payload
php bench/benchmark_render.php png 200 300 ean13  # a different symbology
```

See [BENCHMARK.md](BENCHMARK.md) for full results, the C++-only benchmark and
a description of the SIMD mask-selection kernel.

### Building the C++ Library (optional)

The native C++ encoder is optional — ScanMePHP works without it. To enable `FfiEncoder`:

```bash
cmake -B clib/build -S clib -DCMAKE_BUILD_TYPE=Release
cmake --build clib/build -j$(nproc)
cp clib/build/libscanme_qr.so .
```

Then pass the library path when creating the encoder:

The `ffi` backend finds it automatically — it looks for
`clib/build/libscanme_qr.so` in the project root, and for the binary the
Composer plugin downloads. To point at one somewhere else, construct the
encoder yourself:

```php
use CrazyGoat\ScanMePHP\FfiEncoder;

$encoder = new FfiEncoder(__DIR__ . '/libscanme_qr.so');
```

### Prebuilt Binaries

Prebuilt binaries are available from [GitHub Releases](https://github.com/crazy-goat/ScanMePHP/releases). Download the appropriate binary for your platform:

#### PHP Extension Binaries (Recommended)

| Platform | Binary | Download |
|----------|--------|----------|
| Linux (glibc) | `php-ext-linux-glibc-x86_64.so` | [Latest Release](../../releases/latest) |
| Linux (musl/Alpine) | `php-ext-linux-musl-x86_64.so` | [Latest Release](../../releases/latest) |
| macOS Intel | `php-ext-macos-x86_64.so` | [Latest Release](../../releases/latest) |
| macOS Apple Silicon | `php-ext-macos-arm64.so` | [Latest Release](../../releases/latest) |

#### FFI Library Binaries

| Platform | Binary | Download |
|----------|--------|----------|
| Linux (glibc) | `libscanme_qr-linux-glibc-x86_64.so` | [Latest Release](../../releases/latest) |
| Linux (musl/Alpine) | `libscanme_qr-linux-musl-x86_64.so` | [Latest Release](../../releases/latest) |
| macOS Intel | `libscanme_qr-macos-x86_64.dylib` | [Latest Release](../../releases/latest) |
| macOS Apple Silicon | `libscanme_qr-macos-arm64.dylib` | [Latest Release](../../releases/latest) |

> **Windows:** no prebuilt binaries are published. ScanMePHP still works —
> it falls back to the pure-PHP encoder, which needs no extension and no FFI.
> For native speed on Windows, build `clib/` from source with MSVC and point
> `FfiEncoder` at the resulting `scanme_qr.dll`.

Place the downloaded binary in your project directory. The `FfiEncoder` will automatically detect and load it.

## Requirements

- PHP >= 8.2
- No extensions required
- Upgrading from 0.x? See [UPGRADING.md](UPGRADING.md)
- No external dependencies
- Optional: C++20 compiler + CMake for native FFI encoder

## Testing

```bash
composer test                 # the full suite
composer lint                 # php-cs-fixer, PHPStan, Rector, knowledge-base lint

composer decoders:install     # zxing-cpp + pillow, in a local venv
composer test:roundtrip       # every symbology, read back by a real decoder
```

The round-trip suite skips when the decoder is absent, unless
`SCANME_REQUIRE_DECODER=1` is set — which CI does, because a gate that silently
disappears is worse than no gate at all.

## Examples

Seven runnable examples live in [`examples/`](examples/), each printing what it
is doing:

| File | What it covers |
| --- | --- |
| `01_quickstart.php` | The one call this library is built around |
| `02_symbologies.php` | Every symbology, its payload rules, and how the family relates |
| `03_output_formats.php` | Every output format and what each is good for |
| `04_options.php` | Generator options change the symbol; render options change the picture |
| `05_files_and_web.php` | Files, data URIs, and serving a symbol over HTTP |
| `06_compatibility.php` | What happens when a symbology and a renderer do not fit |
| `07_extending.php` | Your own renderer, symbology and encoding backend |

```bash
php examples/01_quickstart.php
```

Generated files are written to `examples/generated-assets/`, which is
regenerated rather than committed. `tests/ExamplesTest.php` runs all seven on
every CI build — an example that nothing executes is a claim, not a fact.

## License

MIT — see [LICENSE](LICENSE).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).
