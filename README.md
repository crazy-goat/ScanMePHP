# ScanMePHP

A pure PHP QR code generator library with **zero dependencies** and **zero PHP extensions required**. Supports PHP 8.1+ with modern features like strict types, enums, readonly properties, and more.

## Features

- **Zero Dependencies** - No Composer dependencies, no `ext-gd`, no `ext-imagick`, no shell calls
- **Pure PHP Implementation** - All QR encoding logic implemented natively
- **Multiple Renderers** - ASCII and SVG renderers included, easy to extend
- **Full QR Standard Support** - Versions 1-40, all error correction levels
- **Modern PHP** - Uses PHP 8.1+ features: strict types, enums, readonly properties
- **Auto Version Detection** - Automatically selects smallest QR version that fits your data
- **Customizable** - Colors, styles, labels, margins, and more

## Installation

```bash
composer require crazy-goat/scanmephp
```

## Quick Start

```php
use ScanMePHP\QRCode;

// Simple ASCII QR code
$qr = new QRCode('https://example.com');
echo $qr->render();
```

## Usage Examples

### Basic ASCII Output

```php
use ScanMePHP\QRCode;

$qr = new QRCode('https://example.com');
echo $qr->render();
```

Output:
```
██████████████████████████████
██████████████████████████████
████    ████  ████████    ████
████    ████  ████████    ████
████    ████  ████████    ████
████    ████  ████████    ████
████    ████  ████████    ████
████    ████  ████████    ████
██████████████████████████████
██████████████████████████████
████    ██  ██  ██  ██    ████
████    ██  ██  ██  ██    ████
████    ██  ██  ██  ██    ████
████    ██  ██  ██  ██    ████
██████████████████████████████
██████████████████████████████
████    ████  ████████    ████
████    ████  ████████    ████
████    ████  ████████    ████
████    ████  ████████    ████
████    ████  ████████    ████
████    ████  ████████    ████
██████████████████████████████
██████████████████████████████
```

### SVG Output

```php
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\SvgRenderer;

$config = new QRCodeConfig(engine: new SvgRenderer());
$qr = new QRCode('https://example.com', $config);

// Save to file
$qr->saveToFile('/tmp/qrcode.svg');

// Or get as string
$svg = $qr->render();
```

### With Label

```php
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;

$config = new QRCodeConfig(label: 'Scan Me!');
$qr = new QRCode('https://example.com', $config);
echo $qr->render();
```

### Different ASCII Styles

```php
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\AsciiRenderer;
use ScanMePHP\AsciiStyle;

// Full blocks (default)
$config = new QRCodeConfig(engine: new AsciiRenderer(style: AsciiStyle::FullBlocks));

// Half blocks (compact, 50% height)
$config = new QRCodeConfig(engine: new AsciiRenderer(style: AsciiStyle::HalfBlocks));

// Simple (fallback for non-Unicode)
$config = new QRCodeConfig(engine: new AsciiRenderer(style: AsciiStyle::Simple));
```

### ASCII Side Margin

Add spacing on the left and right sides of the ASCII QR code:

```php
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\AsciiRenderer;

// Add 4 spaces on each side (default is 0)
$config = new QRCodeConfig(
    engine: new AsciiRenderer(sideMargin: 4),
    label: 'Centered QR'
);

$qr = new QRCode('https://example.com', $config);
echo $qr->render();
```

### SVG Styling

```php
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\SvgRenderer;
use ScanMePHP\ModuleStyle;
use ScanMePHP\ErrorCorrectionLevel;

$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    moduleStyle: ModuleStyle::Rounded,  // Square, Rounded, or Dot
    errorCorrectionLevel: ErrorCorrectionLevel::High,
    foregroundColor: '#000000',
    backgroundColor: '#FFFFFF',
    invert: false,
    margin: 4,
    label: 'My QR Code'
);

$qr = new QRCode('https://example.com', $config);
```

### Dark Mode

```php
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\SvgRenderer;

$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    invert: true,
    foregroundColor: '#FFFFFF',
    backgroundColor: '#000000'
);

$qr = new QRCode('https://example.com', $config);
```

### HTTP Response (Direct Output)

```php
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\SvgRenderer;

$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    label: 'Scan Me'
);

$qr = new QRCode('https://example.com', $config);
$qr->toHttpResponse();  // Sends headers, outputs SVG, and exits
```

### Data URI

```php
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\SvgRenderer;

$config = new QRCodeConfig(engine: new SvgRenderer());
$qr = new QRCode('https://example.com', $config);

// Get data URI for embedding in HTML
$dataUri = $qr->getDataUri();
// Result: data:image/svg+xml;base64,PHN2Zy4u.

// Or just base64
$base64 = $qr->toBase64();
```

### Version Control

```php
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\ErrorCorrectionLevel;

// Auto-detect version (default)
$config = new QRCodeConfig(size: 0);

// Force specific version (1-40)
$config = new QRCodeConfig(size: 5);

// Get minimum required version
$minVersion = QRCode::getMinimumVersion(
    'https://example.com',
    ErrorCorrectionLevel::Medium
);
echo "Minimum version: {$minVersion}";
```

### Validation

```php
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;

$config = new QRCodeConfig(size: 1);  // Version 1 (very small)
$qr = new QRCode('https://example.com/very/long/url/that/wont/fit', $config);

if (!$qr->validate()) {
    echo "Data too large for this QR version!";
}
```

## Custom Renderer

You can easily create your own renderer by implementing `RendererInterface`:

```php
use ScanMePHP\RendererInterface;
use ScanMePHP\Matrix;
use ScanMePHP\RenderOptions;

class PngRenderer implements RendererInterface
{
    public function render(Matrix $matrix, RenderOptions $options): string
    {
        // Your PNG generation logic here
        // Return PNG binary data
    }
}

// Usage
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;

$config = new QRCodeConfig(engine: new PngRenderer());
$qr = new QRCode('https://example.com', $config);
$qr->saveToFile('/tmp/qrcode.png');
```

## API Reference

### QRCode

Main entry point class.

**Constructor:**
- `__construct(string $url, ?QRCodeConfig $config = null)`
  - Validates URL and creates QR code instance

**Methods:**
- `render(): string` - Returns rendered QR code
- `saveToFile(string $path): void` - Saves to file
- `getDataUri(): string` - Returns data URI
- `toBase64(): string` - Returns base64 encoded output
- `toHttpResponse(): never` - Sends HTTP response and exits
- `getMatrix(): Matrix` - Returns raw QR matrix
- `validate(): bool` - Validates if data fits
- `__toString(): string` - Alias for render()

**Static Methods:**
- `getMinimumVersion(string $data, ErrorCorrectionLevel $level): int`

### QRCodeConfig

Configuration value object.

**Properties:**
- `RendererInterface $engine` - Render engine (default: AsciiRenderer)
- `ErrorCorrectionLevel $errorCorrectionLevel` - ECC level (default: Medium)
- `?string $label` - Optional label text
- `int $size` - QR version 1-40, 0 = auto (default: 0)
- `int $margin` - Quiet zone in modules (default: 4)
- `string $foregroundColor` - Default: #000000
- `string $backgroundColor` - Default: #FFFFFF
- `ModuleStyle $moduleStyle` - Visual style (default: Square)
- `bool $invert` - Swap colors (default: false)

### Enums

**ErrorCorrectionLevel:**
- `Low` (~7% correction)
- `Medium` (~15% correction) - default
- `Quartile` (~25% correction)
- `High` (~30% correction)

**ModuleStyle:** (SVG only)
- `Square` - Rectangular modules (default)
- `Rounded` - Rounded corners
- `Dot` - Circular modules

**AsciiStyle:**
- `FullBlocks` - `██` (default)
- `HalfBlocks` - `▄▀` (compact)
- `Simple` - `##` (fallback)

### AsciiRenderer

ASCII renderer with configurable style and side margin.

**Constructor:**
- `__construct(AsciiStyle $style = AsciiStyle::FullBlocks, int $sideMargin = 0)`
  - `$style` - Visual style (FullBlocks, HalfBlocks, Simple)
  - `$sideMargin` - Number of spaces on left/right sides (default: 0)

## Error Correction Levels

Higher error correction = larger QR code:

- **Low (L)**: ~7% damage recovery
- **Medium (M)**: ~15% damage recovery (default)
- **Quartile (Q)**: ~25% damage recovery
- **High (H)**: ~30% damage recovery

## Requirements

- PHP >= 8.1
- No extensions required
- No external dependencies

## Testing

```bash
composer install
composer test
```

## License

MIT License - see LICENSE file for details.

## Contributing

Contributions welcome! Please ensure:
- Code follows PSR-12 style
- All tests pass
- New features include tests
- Documentation is updated

## Credits

Created by Crazy Goat with love for pure PHP implementations.
