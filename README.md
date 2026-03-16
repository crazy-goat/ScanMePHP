# ScanMePHP

Pure PHP QR code generator. Zero dependencies, zero extensions. PHP 8.1+.

## Installation

```bash
composer require crazy-goat/scanmephp
```

## Quick Start

```php
use ScanMePHP\QRCode;

$qr = new QRCode('https://example.com');
echo $qr->render();
```

## Renderers

ScanMePHP ships with 8 renderers. Each implements `RendererInterface` and can be passed as the `engine` parameter.

### ASCII — FullBlocksRenderer (default)

```php
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\FullBlocksRenderer;

$config = new QRCodeConfig(
    engine: new FullBlocksRenderer(sideMargin: 4),
    label: 'ScanMePHP',
);
$qr = new QRCode('https://example.com', $config);
echo $qr->render();
```

### ASCII — HalfBlocksRenderer

Compact output — two rows per character using `▀▄█` half-block characters.

```php
use ScanMePHP\Renderer\HalfBlocksRenderer;

$config = new QRCodeConfig(
    engine: new HalfBlocksRenderer(sideMargin: 4),
);
```

### ASCII — SimpleRenderer

Uses `●` dots. Works in terminals without full Unicode block support.

```php
use ScanMePHP\Renderer\SimpleRenderer;

$config = new QRCodeConfig(
    engine: new SimpleRenderer(sideMargin: 4),
);
```

### SVG — SvgRenderer

```php
use ScanMePHP\Renderer\SvgRenderer;
use ScanMePHP\ModuleStyle;

$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    moduleStyle: ModuleStyle::Rounded, // Square, Rounded, or Dot
    label: 'Scan Me!',
);
$qr = new QRCode('https://example.com', $config);
$qr->saveToFile('qrcode.svg');
```

### PNG — PngRenderer

Generates valid PNG files in pure PHP — no GD, no Imagick, no external libraries. Black and white only, 1-bit monochrome. Ideal for email attachments, API responses, and print.

> **Note:** Labels are not supported in PNG output (no font engine). Passing a `label` will throw a `RenderException`.

```php
use ScanMePHP\Renderer\PngRenderer;

$config = new QRCodeConfig(
    engine: new PngRenderer(moduleSize: 10),
);
$qr = new QRCode('https://example.com', $config);
$qr->saveToFile('qrcode.png');

// Or use as data URI (e.g. in <img> tags)
$dataUri = $qr->getDataUri(); // data:image/png;base64,...
```

### HTML — HtmlDivRenderer

Renders QR as a `<div>` flexbox grid with inline styles. No external CSS needed.

```php
use ScanMePHP\Renderer\HtmlDivRenderer;

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

Same as above but uses `<table>` with `<td>` elements.

```php
use ScanMePHP\Renderer\HtmlTableRenderer;

$config = new QRCodeConfig(
    engine: new HtmlTableRenderer(moduleSize: 8, fullHtml: true),
);
```

## Configuration

All options are set via `QRCodeConfig`:

```php
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
use ScanMePHP\RendererInterface;
use ScanMePHP\Matrix;
use ScanMePHP\RenderOptions;

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

## Renderer Reference

| Renderer | Output | Constructor Options |
|---|---|---|
| `FullBlocksRenderer` | ASCII `█` blocks | `sideMargin` (int, default: 0) |
| `HalfBlocksRenderer` | ASCII `▀▄█` compact | `sideMargin` (int, default: 0) |
| `SimpleRenderer` | ASCII `●` dots | `sideMargin` (int, default: 0) |
| `SvgRenderer` | SVG XML | — |
| `PngRenderer` | PNG image (1-bit) | `moduleSize` (int, default: 10) |
| `HtmlDivRenderer` | HTML `<div>` grid | `moduleSize` (int, default: 10), `fullHtml` (bool, default: false) |
| `HtmlTableRenderer` | HTML `<table>` | `moduleSize` (int, default: 10), `fullHtml` (bool, default: false) |

## Requirements

- PHP >= 8.1
- No extensions required
- No external dependencies

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
```

Generated output files are saved to `examples/generated-assets/`.

## License

MIT — see [LICENSE](LICENSE).
