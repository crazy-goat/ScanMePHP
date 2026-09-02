# Upgrading

## 0.5.x → the barcode rewrite

ScanMePHP up to 0.5.x was a QR code generator. This release makes it a barcode
library, and that changed the shape of the API rather than only its surface:
a QR-shaped API cannot describe an EAN-13, which is two module rows tall,
carries digits that must be printed, and has no error correction level to
choose.

**This is a full break.** `QRCode`, `QRCodeConfig`, `RenderOptions` and the
seven per-renderer classes are gone. There is no compatibility shim, because a
shim would have to answer questions the old API cannot ask — which symbology,
which quiet zone, whether the human-readable text fits — and would answer them
by guessing.

The replacement is smaller. Most call sites become one line.

### The core move

```php
// before
use CrazyGoat\ScanMePHP\QRCode;
use CrazyGoat\ScanMePHP\QRCodeConfig;
use CrazyGoat\ScanMePHP\Renderer\SvgRenderer;

$config = new QRCodeConfig(engine: new SvgRenderer(moduleSize: 8));
$qr = new QRCode('https://example.com', $config);
echo $qr->render();
```

```php
// after
use CrazyGoat\ScanMePHP\Renderer\Options\SvgOptions;
use CrazyGoat\ScanMePHP\Scanme;

$scanme = Scanme::create();
echo $scanme->render('https://example.com', 'qrcode', 'svg', new SvgOptions(moduleSize: 8));
```

Build `Scanme::create()` once and keep it; it holds no per-symbol state.

### Renderers are names, not classes

You no longer construct a renderer or pass it as an `engine`. The format is a
name (or a `Format` enum case), and the renderer's own options bag carries what
its constructor used to take.

| Old renderer | New format | Options class |
| --- | --- | --- |
| `SvgRenderer` | `svg` | `SvgOptions` |
| `PngRenderer` | `png` | `PngOptions` |
| `HtmlDivRenderer` | `html-div` | `HtmlOptions` |
| `HtmlTableRenderer` | `html-table` | `HtmlOptions` |
| `FullBlocksRenderer` | `ascii-blocks` | `AsciiOptions` |
| `HalfBlocksRenderer` | `ascii-half-blocks` | `AsciiOptions` |
| `SimpleRenderer` | `ascii-dots` | `AsciiOptions` |

`SimpleRenderer` drew `●` dots, so it maps to `ascii-dots` rather than to
anything named "simple".

### Configuration is split in two

`QRCodeConfig` mixed encoding concerns with drawing concerns. They are now
separate bags, routed by the interface they implement, and you pass either,
both, or neither in any order.

| Old `QRCodeConfig` | New |
| --- | --- |
| `engine:` | the `$format` argument |
| `errorCorrectionLevel:` | `QrOptions(errorCorrection: …)` |
| `size:` (QR version, 0 = auto) | `QrOptions(version: …)`, `null` = auto |
| `margin:` | `quietZone:` on the render options |
| `label:` | `label:` on the render options |
| `foregroundColor:` | unchanged, on the render options |
| `backgroundColor:` | unchanged, on the render options |
| `moduleStyle:` | `moduleStyle:` on `SvgOptions` |
| `invert:` | `invert:` on the render options |

Two renames worth reading twice:

- **`size` → `version`.** It was always a QR version number, and `size: 0`
  meant "choose one". It is now `version: null`, which is the default, and a
  number is a floor rather than an exact size: data that does not fit still
  grows the symbol.
- **`margin` → `quietZone`.** Same meaning, and it is now optional in a way it
  was not: leaving it `null` uses the width the symbology requires — 4 modules
  for QR, 11 left and 7 right for EAN-13 — rather than a single default that
  happened to be right for QR.

### Output methods

| Old | New |
| --- | --- |
| `$qr->render()` | `$scanme->render($data, $symbology, $format, …$options)` |
| `$qr->saveToFile($path)` | `$scanme->toFile($path, $data, $symbology, $format, …)` |
| `$qr->getDataUri()` | `$scanme->dataUri($data, $symbology, $format, …)` |
| `$qr->toBase64()` | `base64_encode($scanme->render(…))` |
| `$qr->toHttpResponse()` | build the response yourself (see below) |
| `$qr->getMatrix()` | `$scanme->generate($data, $symbology)` — returns a `Symbol` |
| `$qr->validate()` | `$scanme->getRegistry()->getGenerator($symbology)->canEncode($data)` |
| `echo $qr;` | `echo $scanme->render(…);` |

`toHttpResponse()` is gone deliberately: it sent headers and called `exit`,
which no framework wants and no test can call twice. The MIME type is still the
library's to know:

```php
return new Response(
    $scanme->render($payload, Symbology::QrCode, Format::Svg),
    200,
    ['Content-Type' => $scanme->getContentType(Format::Svg)],
);
```

### Matrix → Symbol

`Matrix` was square, one bit per cell, QR-only. `Symbol` is a rectangle with a
width, a height, optional per-row heights (EAN guard bars descend below the
others), a quiet zone, optional human-readable text, and symbology metadata.

| Old `Matrix` | New `Symbol` |
| --- | --- |
| `getSize()` | `getWidth()` / `getHeight()` — no longer necessarily equal |
| `get($x, $y)` | `get($x, $y)`, unchanged |
| `getVersion()` | `getMetadataValue('version')` |
| `getRawData()` | `toModuleString()` or `rows()` |

### Custom renderers

The interface moved to `CrazyGoat\ScanMePHP\Renderer\RendererInterface` and
gained two methods. `render()` now takes a `Symbol` and a nullable options bag.

```php
// after
final class MyRenderer implements RendererInterface
{
    public function getFormat(): string { return 'my-format'; }
    public function getContentType(): string { return 'text/plain'; }

    public function getCapabilities(): RendererCapabilities
    {
        // What you can actually draw: module shapes, whether you can print
        // text, whether you honour colours, whether you can draw rows of
        // differing heights. The facade refuses pairs you cannot serve
        // instead of letting you emit a symbol that does not scan.
        return new RendererCapabilities(text: false);
    }

    public function render(Symbol $symbol, ?RenderOptionsInterface $options = null): string
    {
        // ...
    }
}

$scanme = new Scanme(Defaults::registry()->addRenderer(new MyRenderer()));
```

Declaring capabilities is the one genuinely new obligation, and it is the point
of the rewrite: a renderer that cannot print an EAN's digits now says so and
gets an exception naming the reason, instead of quietly producing a barcode
that is missing half of what the standard requires be printed.

### The encoders did not move

`Encoder`, `FastEncoder`, `FfiEncoder` and `NativeEncoder` are unchanged and
still take an `ErrorCorrectionLevel` and return a `Matrix`. If you were using
them directly — benchmarking, or driving your own pipeline — nothing breaks.
Inside the library they are now wrapped as QR *backends*, selected at runtime
by priority and availability:

```php
$qr = new QrGenerator();
$qr->getActiveBackend()?->getName();           // 'native', 'ffi', 'bitset' or 'portable'
$qr->getBackendSelector()->force('portable');  // pin one
```

### Things you get for free

Nothing to do here, but worth knowing they exist now:

- **Six more symbologies** — Code 128, EAN-13, EAN-8, UPC-A, UPC-E, Data Matrix
- **`Symbology` and `Format` enums**, accepted anywhere a name is
- **`generatorsFor($data)`** — which symbologies accept this payload
- **`describeGenerators()`** — what is installed and what each one requires
- **Independent decoder verification** — every symbology is round-tripped
  through zxing-cpp in CI

### Getting help

The seven files in [`examples/`](examples/) are each runnable and cover the API
end to end; `01_quickstart.php` is the two-minute version. If an old call has no
obvious replacement here, please open an issue — a missing row in this table is
a bug in the upgrade path.
