# ScanMePHP

**The fastest pure PHP QR code generator with optional native C++ acceleration.**

Generate QR codes in PHP without dependencies — then go **10× faster** with a single C++ library. Zero bloat, maximum speed, production-ready.

QR encoding algorithms are based on [Nayuki's QR Code generator](https://www.nayuki.io/page/qr-code-generator-library).

## Why ScanMePHP?

**🚀 Blazing Fast — 3-Tier Performance**
- **Native C++ via FFI**: 10–12× faster than pure PHP (sub-millisecond generation)
- **64-bit Optimized**: 2× faster with int-pair bit packing (no extensions needed)
- **Portable Fallback**: Works on any PHP 8.1+, 32-bit or 64-bit

Auto-selects the fastest encoder available — no configuration needed.

**📦 Zero Dependencies**
- No Composer packages to install
- No GD, Imagick, or extensions required
- Single `composer require`, instant QR codes

**🎨 8 Output Formats**
SVG, PNG (pure PHP, 1-bit), HTML (div/table), ASCII (3 styles). Works in terminals, browsers, emails, and print.

**🔧 Full QR Spec Support**
- All versions v1–v40 (17 to 2953 bytes)
- All error correction levels (L/M/Q/H)
- Custom styling, colors, labels, dark mode

## Features

- **Zero dependencies** — no external packages, no PHP extensions required
- **8 built-in renderers** — SVG, PNG, HTML (div/table), ASCII (full/half/simple blocks)
- **All QR versions** — v1–v40, all error correction levels (L/M/Q/H)
- **High performance** — 3 encoder tiers: native C++ FFI (10–12× faster), FastEncoder (2×), portable Encoder
- **Customizable** — module styles, colors, labels, dark mode, margins
- **Type-safe** — strict types, enums, readonly properties, PHP 8.1+ idioms

## Installation

```bash
composer require crazy-goat/scanmephp
```

## Binary Auto-Download

When you install or update the package via Composer, the library will automatically:

1. Detect your platform (Linux glibc/musl, macOS Intel/ARM, Windows)
2. Try to download and install the PHP extension (`scanmeqr`) — **fastest option** (13–21× faster)
3. Fall back to FFI library if extension is not available — **10–12× faster**
4. Use pure PHP encoder as final fallback — works everywhere

### PHP Extension Installation (Recommended)

The PHP extension provides the best performance. The Composer plugin will attempt to download it automatically.

#### Auto-Download

During `composer install` or `composer update`, the plugin will:

1. Check if the `scanmeqr` extension is already loaded
2. Download the appropriate prebuilt binary for your platform
3. Provide instructions to enable it in `php.ini`

#### Manual Installation

1. Download the appropriate binary from [GitHub Releases](https://github.com/crazy-goat/ScanMePHP/releases):

| Platform | PHP 8.1 | PHP 8.2 | PHP 8.3 | PHP 8.4 |
|----------|---------|---------|---------|---------|
| Linux (glibc) | `php-ext-linux-glibc-x86_64-php81.so` | `php-ext-linux-glibc-x86_64-php82.so` | `php-ext-linux-glibc-x86_64-php83.so` | `php-ext-linux-glibc-x86_64-php84.so` |
| Linux (musl/Alpine) | `php-ext-linux-musl-x86_64-php81.so` | `php-ext-linux-musl-x86_64-php82.so` | `php-ext-linux-musl-x86_64-php83.so` | `php-ext-linux-musl-x86_64-php84.so` |
| macOS Intel | `php-ext-macos-x86_64-php81.so` | `php-ext-macos-x86_64-php82.so` | `php-ext-macos-x86_64-php83.so` | `php-ext-macos-x86_64-php84.so` |
| macOS Apple Silicon | `php-ext-macos-arm64-php81.so` | `php-ext-macos-arm64-php82.so` | `php-ext-macos-arm64-php83.so` | `php-ext-macos-arm64-php84.so` |

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

#### Building from Source

Requirements:
- PHP 8.1+ with `php-dev`/`phpize`
- CMake 3.10+
- C++ compiler (g++ or clang++)
- Make

```bash
# Build the C++ library first
cd clib
cmake -B build -S . -DCMAKE_BUILD_TYPE=Release
cmake --build build -j$(nproc)
cd ..

# Build the PHP extension
cd php-ext
phpize
./configure --with-scanmeqr="$PWD/../clib"
make -j$(nproc)
make install
cd ..
```

Then add `extension=scanmeqr.so` to your `php.ini`.

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
| Windows x86_64 | `scanme_qr-windows-x86_64.dll` |

## Quick Start

```php
use CrazyGoat\ScanMePHP\QRCode;

$qr = new QRCode('https://example.com');
echo $qr->render();
```

## Renderers

ScanMePHP ships with 8 renderers. Each implements `RendererInterface` and can be passed as the `engine` parameter.

| Renderer | Output | Constructor Options |
|---|---|---|
| `FullBlocksRenderer` | ASCII `█` blocks | `sideMargin` (int, default: 0) |
| `HalfBlocksRenderer` | ASCII `▀▄█` compact | `sideMargin` (int, default: 0) |
| `SimpleRenderer` | ASCII `●` dots | `sideMargin` (int, default: 0) |
| `SvgRenderer` | SVG XML | `moduleSize` (int, default: 10) |
| `PngRenderer` | PNG image (1-bit) | `moduleSize` (int, default: 10) |
| `HtmlDivRenderer` | HTML `<div>` grid | `moduleSize` (int, default: 10), `fullHtml` (bool, default: false) |
| `HtmlTableRenderer` | HTML `<table>` | `moduleSize` (int, default: 10), `fullHtml` (bool, default: false) |

### ASCII — FullBlocksRenderer (default)

**Example:** [qrcode_fullblocks.txt](examples/generated-assets/qrcode_fullblocks.txt)

```php
use CrazyGoat\ScanMePHP\QRCode;
use CrazyGoat\ScanMePHP\QRCodeConfig;
use CrazyGoat\ScanMePHP\Renderer\FullBlocksRenderer;

$config = new QRCodeConfig(
    engine: new FullBlocksRenderer(sideMargin: 4),
    label: 'ScanMePHP',
);
$qr = new QRCode('https://example.com', $config);
echo $qr->render();
```

### ASCII — HalfBlocksRenderer

**Example:** [qrcode_halfblocks.txt](examples/generated-assets/qrcode_halfblocks.txt)

Compact output — two rows per character using `▀▄█` half-block characters.

```php
use CrazyGoat\ScanMePHP\Renderer\HalfBlocksRenderer;

$config = new QRCodeConfig(
    engine: new HalfBlocksRenderer(sideMargin: 4),
);
```

### ASCII — SimpleRenderer

**Example:** [qrcode_simple.txt](examples/generated-assets/qrcode_simple.txt)

Uses `●` dots. Works in terminals without full Unicode block support.

```php
use CrazyGoat\ScanMePHP\Renderer\SimpleRenderer;

$config = new QRCodeConfig(
    engine: new SimpleRenderer(sideMargin: 4),
);
```

### SVG — SvgRenderer

**Examples:** [qrcode.svg](examples/generated-assets/qrcode.svg) | [qrcode_rounded.svg](examples/generated-assets/qrcode_rounded.svg) | [qrcode_dark.svg](examples/generated-assets/qrcode_dark.svg) | [qrcode_with_label.svg](examples/generated-assets/qrcode_with_label.svg)

```php
use CrazyGoat\ScanMePHP\Renderer\SvgRenderer;
use CrazyGoat\ScanMePHP\ModuleStyle;

$config = new QRCodeConfig(
    engine: new SvgRenderer(moduleSize: 12),
    moduleStyle: ModuleStyle::Rounded, // Square, Rounded, or Dot
    label: 'Scan Me!',
);
$qr = new QRCode('https://example.com', $config);
$qr->saveToFile('qrcode.svg');
```

### PNG — PngRenderer

**Examples:** [qrcode.png](examples/generated-assets/qrcode.png) | [qrcode_small.png](examples/generated-assets/qrcode_small.png) | [qrcode_large.png](examples/generated-assets/qrcode_large.png) | [qrcode_high_ecc.png](examples/generated-assets/qrcode_high_ecc.png)

Generates valid PNG files in pure PHP — no GD, no Imagick, no external libraries. Black and white only, 1-bit monochrome. Ideal for email attachments, API responses, and print.

> **Note:** Labels are not supported in PNG output (no font engine). Passing a `label` will throw a `RenderException`.

```php
use CrazyGoat\ScanMePHP\Renderer\PngRenderer;

$config = new QRCodeConfig(
    engine: new PngRenderer(moduleSize: 10),
);
$qr = new QRCode('https://example.com', $config);
$qr->saveToFile('qrcode.png');

// Or use as data URI (e.g. in <img> tags)
$dataUri = $qr->getDataUri(); // data:image/png;base64,...
```

### HTML — HtmlDivRenderer

**Examples:** [qrcode_div.html](examples/generated-assets/qrcode_div.html) | [qrcode_div_full.html](examples/generated-assets/qrcode_div_full.html) | [qrcode_div_inverted.html](examples/generated-assets/qrcode_div_inverted.html) | [qrcode_div_label.html](examples/generated-assets/qrcode_div_label.html)

Renders QR as a `<div>` flexbox grid with inline styles. No external CSS needed.

```php
use CrazyGoat\ScanMePHP\Renderer\HtmlDivRenderer;

$config = new QRCodeConfig(
    engine: new HtmlDivRenderer(moduleSize: 10, fullHtml: false),
    label: 'ScanMePHP',
);
$qr = new QRCode('https://example.com', $config);

// Fragment only (for embedding)
$html = $qr->render();

// Full HTML page
$config = new QRCodeConfig(
    engine: new HtmlDivRenderer(fullHtml: true),
);
```

### HTML — HtmlTableRenderer

**Examples:** [qrcode_table.html](examples/generated-assets/qrcode_table.html) | [qrcode_table_full.html](examples/generated-assets/qrcode_table_full.html) | [qrcode_table_inverted.html](examples/generated-assets/qrcode_table_inverted.html) | [qrcode_table_label.html](examples/generated-assets/qrcode_table_label.html)

Same as above but uses `<table>` with `<td>` elements.

```php
use CrazyGoat\ScanMePHP\Renderer\HtmlTableRenderer;

$config = new QRCodeConfig(
    engine: new HtmlTableRenderer(moduleSize: 8, fullHtml: true),
);
```

## Configuration

All options are set via `QRCodeConfig`:

```php
use CrazyGoat\ScanMePHP\QRCodeConfig;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\ModuleStyle;
use CrazyGoat\ScanMePHP\Renderer\SvgRenderer;

$config = new QRCodeConfig(
    engine: new SvgRenderer(),                          // renderer instance
    errorCorrectionLevel: ErrorCorrectionLevel::Medium,  // Low, Medium, Quartile, High
    label: 'My QR Code',                                // optional label below QR
    size: 0,                                             // QR version 1-40, 0 = auto
    margin: 4,                                           // quiet zone in modules
    foregroundColor: '#000000',
    backgroundColor: '#FFFFFF',
    moduleStyle: ModuleStyle::Square,                    // Square, Rounded, Dot (SVG only)
    invert: false,                                       // swap foreground/background
);
```

## Dark Mode (Inverted)

```php
$config = new QRCodeConfig(
    engine: new FullBlocksRenderer(sideMargin: 4),
    invert: true,
    label: 'Dark Mode',
);
```

For SVG/HTML renderers, set explicit colors:

```php
$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    invert: true,
    foregroundColor: '#FFFFFF',
    backgroundColor: '#000000',
);
```

## Output Methods

```php
$qr = new QRCode('https://example.com', $config);

$qr->render();              // returns string
$qr->saveToFile('qr.svg');  // writes to file
$qr->getDataUri();          // data:image/svg+xml;base64,...
$qr->toBase64();            // raw base64
$qr->toHttpResponse();      // sends Content-Type header, outputs, exits
$qr->getMatrix();           // raw Matrix object
$qr->validate();            // true if data fits in QR version
echo $qr;                   // __toString() calls render()
```

## Custom Renderer

Implement `RendererInterface`:

```php
use CrazyGoat\ScanMePHP\RendererInterface;
use CrazyGoat\ScanMePHP\Matrix;
use CrazyGoat\ScanMePHP\RenderOptions;

class MyCustomRenderer implements RendererInterface
{
    public function render(Matrix $matrix, RenderOptions $options): string
    {
        $size = $matrix->getSize();
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $isDark = $matrix->get($x, $y);
                // ... your rendering logic
            }
        }
        return $output;
    }

    public function getContentType(): string
    {
        return 'text/plain';
    }
}
```

## Performance

ScanMePHP includes four encoder implementations. `QRCode` auto-selects the fastest available:

| Encoder | Versions | Requirements | Relative Speed |
|---|---|---|---|
| `NativeEncoderExt` | v1–v27 | 64-bit PHP + `scanmeqr` extension | **13–21×** faster |
| `FfiEncoder` | v1–v40 | 64-bit PHP + FFI + `libscanme_qr.so` | **10–12×** faster |
| `FastEncoder` | v1–v27 | 64-bit PHP | **~2×** faster |
| `Encoder` | v1–v40 | any PHP 8.1+ | baseline |

### Capacity (Byte Mode)

Maximum data length for URL/text encoding (Byte mode) at different QR versions:

| Version | Size | L (Low) | M (Medium) | Q (Quartile) | H (High) |
|---|---|---|---|---|---|
| v1 | 21×21 | 17 | 14 | 11 | 7 |
| v10 | 57×57 | 271 | 213 | 151 | 119 |
| v27 | 125×125 | 1465 | 1125 | 805 | 625 |
| v40 | 177×177 | **2953** | **2331** | **1663** | **1273** |

**Note:** FastEncoder and FfiEncoder support up to v27 (1465 bytes max). For larger data, the portable Encoder (v1–v40) is automatically used.

### Benchmark Results

Measured on PHP 8.4, 200 iterations per case, median latency:

| Test case | Encoder | FastEncoder | FfiEncoder | Speedup (Encoder/FFI) |
|---|---|---|---|---|
| v1 (21×21) L | 0.72 ms | 0.38 ms | 0.07 ms | **10×** |
| v2 (25×25) M | 1.03 ms | 0.52 ms | 0.10 ms | **10×** |
| v5 (37×37) M | 2.48 ms | 1.18 ms | 0.25 ms | **10×** |
| v10 (57×57) M | 7.71 ms | 3.35 ms | 0.75 ms | **10×** |

All three encoders produce identical, spec-compliant QR codes verified against [nayuki's reference implementation](https://www.nayuki.io/page/qr-code-generator-library).

Run the benchmark yourself:

```bash
php bench/benchmark_encoder.php        # 200 iterations
php bench/benchmark_encoder.php 500    # 500 iterations
```

See [BENCHMARK.md](BENCHMARK.md) for full results with percentiles.

### Building the C++ Library (optional)

The native C++ encoder is optional — ScanMePHP works without it. To enable `FfiEncoder`:

```bash
cmake -B clib/build -S clib -DCMAKE_BUILD_TYPE=Release
cmake --build clib/build -j$(nproc)
cp clib/build/libscanme_qr.so .
```

Then pass the library path when creating the encoder:

```php
use CrazyGoat\ScanMePHP\FfiEncoder;

$encoder = new FfiEncoder(__DIR__ . '/libscanme_qr.so');
$qr = new QRCode('https://example.com', encoder: $encoder);
```

Or let `QRCode` auto-detect it (looks for `clib/build/libscanme_qr.so` in the project root).

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
| Windows x86_64 | `scanme_qr-windows-x86_64.dll` | [Latest Release](../../releases/latest) |

Place the downloaded binary in your project directory. The `FfiEncoder` will automatically detect and load it.

## Requirements

- PHP >= 8.1
- No extensions required
- No external dependencies
- Optional: C++20 compiler + CMake for native FFI encoder

## Testing

```bash
composer test
```

## Examples

See the `examples/` directory. Run any example:

```bash
php examples/ascii_fullblocks.php
php examples/svg_example.php
php examples/png_example.php
php examples/html_div.php
php examples/html_table.php
```

Generated output files are saved to `examples/generated-assets/`.

## License

MIT — see [LICENSE](LICENSE).
