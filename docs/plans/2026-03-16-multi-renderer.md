# Multi-Renderer Refactoring Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Split `AsciiRenderer` into 3 separate renderer classes, add `getContentType()` to `RendererInterface`, remove border support, remove `AsciiStyle` enum, and update examples to show label + invert per renderer.

**Architecture:** Each ASCII style becomes its own renderer class extending `AbstractAsciiRenderer`. The abstract base holds shared logic (margin lines, text centering, label handling). `RendererInterface` gains `getContentType()` so `QRCode.php` no longer needs `instanceof` checks. Border-related code is removed entirely from all renderers.

**Tech Stack:** PHP 8.2+, PHPUnit

---

### Task 1: Add `getContentType()` to `RendererInterface`

**Files:**
- Modify: `src/RendererInterface.php`

**Step 1: Update the interface**

```php
<?php

declare(strict_types=1);

namespace ScanMePHP;

interface RendererInterface
{
    public function render(Matrix $matrix, RenderOptions $options): string;

    public function getContentType(): string;
}
```

**Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit`
Expected: FAIL — `AsciiRenderer` and `SvgRenderer` don't implement `getContentType()` yet.

**Step 3: Commit**

```bash
git add src/RendererInterface.php
git commit -m "feat: add getContentType() to RendererInterface"
```

---

### Task 2: Create `AbstractAsciiRenderer`

**Files:**
- Create: `src/Renderer/AbstractAsciiRenderer.php`

**Step 1: Create the abstract class**

```php
<?php

declare(strict_types=1);

namespace ScanMePHP\Renderer;

use ScanMePHP\Matrix;
use ScanMePHP\RenderOptions;
use ScanMePHP\RendererInterface;

abstract class AbstractAsciiRenderer implements RendererInterface
{
    public function __construct(
        private int $sideMargin = 0,
    ) {
    }

    public function getContentType(): string
    {
        return 'text/plain';
    }

    abstract public function render(Matrix $matrix, RenderOptions $options): string;

    protected function getSideMargin(): int
    {
        return $this->sideMargin;
    }

    protected function createMarginLine(int $qrSize, int $sideMargin): string
    {
        return str_repeat(' ', $qrSize + (2 * $sideMargin));
    }

    protected function centerText(string $text, int $width): string
    {
        $textLength = strlen($text);
        if ($textLength >= $width) {
            return $text;
        }

        $padding = (int) (($width - $textLength) / 2);
        return str_repeat(' ', $padding) . $text;
    }

    protected function appendLabel(array &$lines, ?string $label, int $totalWidth): void
    {
        if ($label !== null && $label !== '') {
            $lines[] = '';
            $lines[] = $this->centerText($label, $totalWidth);
        }
    }
}
```

**Step 2: Commit**

```bash
git add src/Renderer/AbstractAsciiRenderer.php
git commit -m "feat: add AbstractAsciiRenderer base class"
```

---

### Task 3: Create `FullBlocksRenderer`

**Files:**
- Create: `src/Renderer/FullBlocksRenderer.php`

**Step 1: Create the renderer**

```php
<?php

declare(strict_types=1);

namespace ScanMePHP\Renderer;

use ScanMePHP\Matrix;
use ScanMePHP\RenderOptions;

class FullBlocksRenderer extends AbstractAsciiRenderer
{
    public function render(Matrix $matrix, RenderOptions $options): string
    {
        $size = $matrix->getSize();
        $margin = $options->margin;
        $sideMargin = $this->getSideMargin();
        $lines = [];

        for ($i = 0; $i < $margin; $i++) {
            $lines[] = $this->createMarginLine($size, $sideMargin);
        }

        for ($y = 0; $y < $size; $y++) {
            $line = str_repeat(' ', $sideMargin);
            for ($x = 0; $x < $size; $x++) {
                $isDark = $matrix->get($x, $y);
                $isDark = $options->invert ? !$isDark : $isDark;
                $line .= $isDark ? '█' : ' ';
            }
            $line .= str_repeat(' ', $sideMargin);
            $lines[] = $line;
        }

        for ($i = 0; $i < $margin; $i++) {
            $lines[] = $this->createMarginLine($size, $sideMargin);
        }

        $totalWidth = $size + (2 * $sideMargin);
        $this->appendLabel($lines, $options->label, $totalWidth);

        return implode("\n", $lines);
    }
}
```

**Step 2: Commit**

```bash
git add src/Renderer/FullBlocksRenderer.php
git commit -m "feat: add FullBlocksRenderer"
```

---

### Task 4: Create `HalfBlocksRenderer`

**Files:**
- Create: `src/Renderer/HalfBlocksRenderer.php`

**Step 1: Create the renderer**

```php
<?php

declare(strict_types=1);

namespace ScanMePHP\Renderer;

use ScanMePHP\Matrix;
use ScanMePHP\RenderOptions;

class HalfBlocksRenderer extends AbstractAsciiRenderer
{
    public function render(Matrix $matrix, RenderOptions $options): string
    {
        $size = $matrix->getSize();
        $margin = $options->margin;
        $sideMargin = $this->getSideMargin();
        $lines = [];

        for ($i = 0; $i < $margin; $i++) {
            $lines[] = $this->createMarginLine($size, $sideMargin);
        }

        for ($y = 0; $y < $size; $y += 2) {
            $line = str_repeat(' ', $sideMargin);
            for ($x = 0; $x < $size; $x++) {
                $top = $matrix->get($x, $y);
                $bottom = ($y + 1 < $size) ? $matrix->get($x, $y + 1) : false;
                $top = $options->invert ? !$top : $top;
                $bottom = $options->invert ? !$bottom : $bottom;
                $line .= match ([$top, $bottom]) {
                    [false, false] => ' ',
                    [false, true] => '▄',
                    [true, false] => '▀',
                    [true, true] => '█',
                };
            }
            $line .= str_repeat(' ', $sideMargin);
            $lines[] = $line;
        }

        for ($i = 0; $i < $margin; $i++) {
            $lines[] = $this->createMarginLine($size, $sideMargin);
        }

        $totalWidth = $size + (2 * $sideMargin);
        $this->appendLabel($lines, $options->label, $totalWidth);

        return implode("\n", $lines);
    }
}
```

**Step 2: Commit**

```bash
git add src/Renderer/HalfBlocksRenderer.php
git commit -m "feat: add HalfBlocksRenderer"
```

---

### Task 5: Create `SimpleRenderer`

**Files:**
- Create: `src/Renderer/SimpleRenderer.php`

**Step 1: Create the renderer**

```php
<?php

declare(strict_types=1);

namespace ScanMePHP\Renderer;

use ScanMePHP\Matrix;
use ScanMePHP\RenderOptions;

class SimpleRenderer extends AbstractAsciiRenderer
{
    public function render(Matrix $matrix, RenderOptions $options): string
    {
        $size = $matrix->getSize();
        $margin = $options->margin;
        $sideMargin = $this->getSideMargin();
        $lines = [];

        for ($i = 0; $i < $margin; $i++) {
            $lines[] = $this->createMarginLine($size, $sideMargin);
        }

        for ($y = 0; $y < $size; $y++) {
            $line = str_repeat(' ', $sideMargin);
            for ($x = 0; $x < $size; $x++) {
                $isDark = $matrix->get($x, $y);
                $isDark = $options->invert ? !$isDark : $isDark;
                $line .= $isDark ? '●' : ' ';
            }
            $line .= str_repeat(' ', $sideMargin);
            $lines[] = $line;
        }

        for ($i = 0; $i < $margin; $i++) {
            $lines[] = $this->createMarginLine($size, $sideMargin);
        }

        $totalWidth = $size + (2 * $sideMargin);
        $this->appendLabel($lines, $options->label, $totalWidth);

        return implode("\n", $lines);
    }
}
```

**Step 2: Commit**

```bash
git add src/Renderer/SimpleRenderer.php
git commit -m "feat: add SimpleRenderer"
```

---

### Task 6: Add `getContentType()` to `SvgRenderer` and remove border support

**Files:**
- Modify: `src/Renderer/SvgRenderer.php`

**Step 1: Update SvgRenderer**

Remove `$border` and `$borderWidth` constructor params. Remove all border-related rendering logic. Add `getContentType()`.

```php
<?php

declare(strict_types=1);

namespace ScanMePHP\Renderer;

use ScanMePHP\Matrix;
use ScanMePHP\ModuleStyle;
use ScanMePHP\RenderOptions;
use ScanMePHP\RendererInterface;

class SvgRenderer implements RendererInterface
{
    private int $moduleSize = 10;

    public function getContentType(): string
    {
        return 'image/svg+xml';
    }

    public function render(Matrix $matrix, RenderOptions $options): string
    {
        $size = $matrix->getSize();
        $margin = $options->margin;
        $totalModules = $size + (2 * $margin);
        $totalSize = $totalModules * $this->moduleSize;

        $fgColor = $options->getEffectiveForegroundColor();
        $bgColor = $options->getEffectiveBackgroundColor();

        $svg = $this->generateSvgHeader($totalSize);
        $svg .= $this->generateBackground($totalSize, $bgColor);
        $svg .= $this->generateModules($matrix, $margin, $fgColor, $options->moduleStyle);

        if ($options->label !== null && $options->label !== '') {
            $svg .= $this->generateLabel($options->label, $totalSize, $size, $margin);
        }

        $svg .= '</svg>';

        return $svg;
    }

    private function generateSvgHeader(int $size): string
    {
        return sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
            '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" ' .
            'viewBox="0 0 %d %d" width="%d" height="%d">' . "\n",
            $size, $size, $size, $size
        );
    }

    private function generateBackground(int $size, string $color): string
    {
        return sprintf(
            '  <rect width="%d" height="%d" fill="%s"/>' . "\n",
            $size, $size, $this->escapeColor($color)
        );
    }

    private function generateModules(Matrix $matrix, int $margin, string $color, ModuleStyle $style): string
    {
        $size = $matrix->getSize();
        $elements = [];

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if ($matrix->get($x, $y)) {
                    $elements[] = $this->generateModule(
                        $x + $margin,
                        $y + $margin,
                        $color,
                        $style,
                        $this->isFinderPattern($matrix, $x, $y)
                    );
                }
            }
        }

        return implode("\n", $elements) . "\n";
    }

    private function generateModule(int $x, int $y, string $color, ModuleStyle $style, bool $isFinder): string
    {
        $px = $x * $this->moduleSize;
        $py = $y * $this->moduleSize;
        $size = $this->moduleSize;

        if ($isFinder) {
            $radius = $size * 0.15;
            return sprintf(
                '  <rect x="%d" y="%d" width="%d" height="%d" fill="%s" rx="%.1f" ry="%.1f"/>',
                $px, $py, $size, $size, $this->escapeColor($color), $radius, $radius
            );
        }

        return match ($style) {
            ModuleStyle::Square => sprintf(
                '  <rect x="%d" y="%d" width="%d" height="%d" fill="%s"/>',
                $px, $py, $size, $size, $this->escapeColor($color)
            ),
            ModuleStyle::Rounded => sprintf(
                '  <rect x="%d" y="%d" width="%d" height="%d" fill="%s" rx="%.1f" ry="%.1f"/>',
                $px, $py, $size, $size, $this->escapeColor($color), $size * 0.3, $size * 0.3
            ),
            ModuleStyle::Dot => sprintf(
                '  <circle cx="%d" cy="%d" r="%.1f" fill="%s"/>',
                $px + $size / 2, $py + $size / 2, $size * 0.4, $this->escapeColor($color)
            ),
        };
    }

    private function isFinderPattern(Matrix $matrix, int $x, int $y): bool
    {
        $size = $matrix->getSize();
        $finderSize = 7;

        if ($x < $finderSize && $y < $finderSize) {
            return true;
        }

        if ($x >= $size - $finderSize && $y < $finderSize) {
            return true;
        }

        if ($x < $finderSize && $y >= $size - $finderSize) {
            return true;
        }

        return false;
    }

    private function generateLabel(string $label, int $totalSize, int $matrixSize, int $margin): string
    {
        $labelY = ($matrixSize + 2 * $margin + 2) * $this->moduleSize;
        $fontSize = $this->moduleSize * 1.5;

        return sprintf(
            '  <text x="%d" y="%d" text-anchor="middle" font-family="Arial, sans-serif" ' .
            'font-size="%.1f" fill="#000000">%s</text>' . "\n",
            $totalSize / 2,
            $labelY,
            $fontSize,
            htmlspecialchars($label, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        );
    }

    private function escapeColor(string $color): string
    {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return $color;
        }

        return '#000000';
    }
}
```

**Step 2: Commit**

```bash
git add src/Renderer/SvgRenderer.php
git commit -m "refactor: add getContentType() to SvgRenderer, remove border support"
```

---

### Task 7: Delete `AsciiRenderer` and `AsciiStyle`

**Files:**
- Delete: `src/Renderer/AsciiRenderer.php`
- Delete: `src/AsciiStyle.php`

**Step 1: Delete the files**

```bash
rm src/Renderer/AsciiRenderer.php
rm src/AsciiStyle.php
```

**Step 2: Commit**

```bash
git add -A
git commit -m "refactor: remove AsciiRenderer and AsciiStyle enum"
```

---

### Task 8: Update `QRCodeConfig` default engine

**Files:**
- Modify: `src/QRCodeConfig.php`

**Step 1: Change default engine to `FullBlocksRenderer`**

```php
<?php

declare(strict_types=1);

namespace ScanMePHP;

use ScanMePHP\Renderer\FullBlocksRenderer;

readonly class QRCodeConfig
{
    public function __construct(
        public RendererInterface $engine = new FullBlocksRenderer(),
        public ErrorCorrectionLevel $errorCorrectionLevel = ErrorCorrectionLevel::Medium,
        public ?string $label = null,
        public int $size = 0,
        public int $margin = 4,
        public string $foregroundColor = '#000000',
        public string $backgroundColor = '#FFFFFF',
        public ModuleStyle $moduleStyle = ModuleStyle::Square,
        public bool $invert = false,
    ) {
    }

    public function toRenderOptions(): RenderOptions
    {
        return new RenderOptions(
            margin: $this->margin,
            label: $this->label,
            foregroundColor: $this->foregroundColor,
            backgroundColor: $this->backgroundColor,
            moduleStyle: $this->moduleStyle,
            invert: $this->invert,
        );
    }
}
```

**Step 2: Commit**

```bash
git add src/QRCodeConfig.php
git commit -m "refactor: change default engine to FullBlocksRenderer"
```

---

### Task 9: Update `QRCode.php` — remove `instanceof` checks

**Files:**
- Modify: `src/QRCode.php`

**Step 1: Replace instanceof with getContentType()**

Remove the `use ScanMePHP\Renderer\AsciiRenderer;` import. Replace `instanceof` checks in `getDataUri()` and `toHttpResponse()` with `$this->config->engine->getContentType()`.

```php
<?php

declare(strict_types=1);

namespace ScanMePHP;

use ScanMePHP\Encoding\Mode;
use ScanMePHP\Exception\FileWriteException;
use ScanMePHP\Exception\InvalidDataException;
use ScanMePHP\Exception\RenderException;

class QRCode
{
    private string $url;
    private QRCodeConfig $config;
    private ?Matrix $matrix = null;
    private Encoder $encoder;

    public function __construct(string $url, ?QRCodeConfig $config = null)
    {
        $this->validateUrl($url);
        $this->url = $url;
        $this->config = $config ?? new QRCodeConfig();
        $this->encoder = new Encoder();
    }

    private function validateUrl(string $url): void
    {
        if (empty($url)) {
            throw InvalidDataException::emptyData();
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw InvalidDataException::invalidUrl($url);
        }
    }

    private function ensureMatrix(): Matrix
    {
        if ($this->matrix === null) {
            $this->matrix = $this->encoder->encode(
                $this->url,
                $this->config->errorCorrectionLevel,
                $this->config->size
            );
        }

        return $this->matrix;
    }

    public function render(): string
    {
        $matrix = $this->ensureMatrix();
        $renderOptions = $this->config->toRenderOptions();

        return $this->config->engine->render($matrix, $renderOptions);
    }

    public function saveToFile(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw FileWriteException::directoryNotWritable($directory);
        }

        $content = $this->render();
        $result = file_put_contents($path, $content, LOCK_EX);

        if ($result === false) {
            throw FileWriteException::cannotWriteToFile($path);
        }
    }

    public function getDataUri(): string
    {
        $content = $this->render();
        $base64 = base64_encode($content);
        $contentType = $this->config->engine->getContentType();

        return 'data:' . $contentType . ';base64,' . $base64;
    }

    public function toBase64(): string
    {
        return base64_encode($this->render());
    }

    public function toHttpResponse(): never
    {
        $content = $this->render();
        $contentType = $this->config->engine->getContentType();

        header('Content-Type: ' . $contentType);
        echo $content;
        exit;
    }

    public function getMatrix(): Matrix
    {
        return $this->ensureMatrix();
    }

    public function validate(): bool
    {
        try {
            $this->ensureMatrix();
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public function __toString(): string
    {
        return $this->render();
    }

    public static function getMinimumVersion(
        string $data,
        ErrorCorrectionLevel $level,
        ?Mode $forcedMode = null
    ): int {
        $encoder = new Encoder();
        return $encoder->getMinimumVersion($data, $level, $forcedMode);
    }
}
```

**Step 2: Commit**

```bash
git add src/QRCode.php
git commit -m "refactor: replace instanceof checks with getContentType()"
```

---

### Task 10: Update tests

**Files:**
- Modify: `tests/QRCodeTest.php`

**Step 1: Update tests to use new renderer classes**

Replace all `AsciiRenderer` + `AsciiStyle` usage with `FullBlocksRenderer`, `HalfBlocksRenderer`, `SimpleRenderer`. Update `testDifferentAsciiStyles` to use the three separate classes. Update imports.

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\FullBlocksRenderer;
use ScanMePHP\Renderer\HalfBlocksRenderer;
use ScanMePHP\Renderer\SimpleRenderer;
use ScanMePHP\Renderer\SvgRenderer;
use ScanMePHP\ErrorCorrectionLevel;
use ScanMePHP\ModuleStyle;

class QRCodeTest extends TestCase
{
    public function testBasicAsciiQrCode(): void
    {
        $qr = new QRCode('https://example.com');
        $output = $qr->render();

        $this->assertIsString($output);
        $this->assertStringContainsString('█', $output);
    }

    public function testSvgQrCode(): void
    {
        $config = new QRCodeConfig(engine: new SvgRenderer());
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertIsString($output);
        $this->assertStringContainsString('<?xml', $output);
        $this->assertStringContainsString('<svg', $output);
    }

    public function testAsciiWithLabel(): void
    {
        $config = new QRCodeConfig(label: 'Test Label');
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertStringContainsString('Test Label', $output);
    }

    public function testDifferentAsciiRenderers(): void
    {
        $url = 'https://example.com';

        $config = new QRCodeConfig(engine: new FullBlocksRenderer());
        $qr = new QRCode($url, $config);
        $this->assertStringContainsString('█', $qr->render());

        $config = new QRCodeConfig(engine: new HalfBlocksRenderer());
        $qr = new QRCode($url, $config);
        $this->assertIsString($qr->render());

        $config = new QRCodeConfig(engine: new SimpleRenderer());
        $qr = new QRCode($url, $config);
        $this->assertStringContainsString('●', $qr->render());
    }

    public function testSvgWithDifferentStyles(): void
    {
        $url = 'https://example.com';

        $config = new QRCodeConfig(
            engine: new SvgRenderer(),
            moduleStyle: ModuleStyle::Square
        );
        $qr = new QRCode($url, $config);
        $this->assertIsString($qr->render());

        $config = new QRCodeConfig(
            engine: new SvgRenderer(),
            moduleStyle: ModuleStyle::Rounded
        );
        $qr = new QRCode($url, $config);
        $this->assertIsString($qr->render());

        $config = new QRCodeConfig(
            engine: new SvgRenderer(),
            moduleStyle: ModuleStyle::Dot
        );
        $qr = new QRCode($url, $config);
        $this->assertIsString($qr->render());
    }

    public function testErrorCorrectionLevels(): void
    {
        $url = 'https://example.com';

        foreach (ErrorCorrectionLevel::cases() as $level) {
            $config = new QRCodeConfig(errorCorrectionLevel: $level);
            $qr = new QRCode($url, $config);
            $this->assertIsString($qr->render());
        }
    }

    public function testDataUri(): void
    {
        $config = new QRCodeConfig(engine: new SvgRenderer());
        $qr = new QRCode('https://example.com', $config);
        $dataUri = $qr->getDataUri();

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $dataUri);
    }

    public function testAsciiDataUri(): void
    {
        $qr = new QRCode('https://example.com');
        $dataUri = $qr->getDataUri();

        $this->assertStringStartsWith('data:text/plain;base64,', $dataUri);
    }

    public function testBase64(): void
    {
        $qr = new QRCode('https://example.com');
        $base64 = $qr->toBase64();

        $this->assertIsString($base64);
        $this->assertTrue(base64_decode($base64, true) !== false);
    }

    public function testToString(): void
    {
        $qr = new QRCode('https://example.com');
        $output = (string) $qr;

        $this->assertIsString($output);
        $this->assertStringContainsString('█', $output);
    }

    public function testValidation(): void
    {
        $qr = new QRCode('https://example.com');
        $this->assertTrue($qr->validate());
    }

    public function testGetMinimumVersion(): void
    {
        $version = QRCode::getMinimumVersion(
            'https://example.com',
            ErrorCorrectionLevel::Medium
        );

        $this->assertIsInt($version);
        $this->assertGreaterThanOrEqual(1, $version);
        $this->assertLessThanOrEqual(40, $version);
    }

    public function testSaveToFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_qr_' . uniqid() . '.txt';

        try {
            $qr = new QRCode('https://example.com');
            $qr->saveToFile($tempFile);

            $this->assertFileExists($tempFile);
            $this->assertIsString(file_get_contents($tempFile));
        } finally {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testInvertColors(): void
    {
        $config = new QRCodeConfig(
            engine: new SvgRenderer(),
            invert: true,
            foregroundColor: '#FFFFFF',
            backgroundColor: '#000000'
        );
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertIsString($output);
        $this->assertStringContainsString('fill="#FFFFFF"', $output);
    }

    public function testGetContentType(): void
    {
        $this->assertEquals('text/plain', (new FullBlocksRenderer())->getContentType());
        $this->assertEquals('text/plain', (new HalfBlocksRenderer())->getContentType());
        $this->assertEquals('text/plain', (new SimpleRenderer())->getContentType());
        $this->assertEquals('image/svg+xml', (new SvgRenderer())->getContentType());
    }
}
```

**Step 2: Run tests**

Run: `vendor/bin/phpunit`
Expected: All tests pass.

**Step 3: Commit**

```bash
git add tests/QRCodeTest.php
git commit -m "test: update tests for new renderer classes"
```

---

### Task 11: Update example files

**Files:**
- Modify: `examples/ascii_fullblocks.php`
- Modify: `examples/ascii_halfblocks.php`
- Modify: `examples/ascii_simple.php`
- Modify: `examples/svg_example.php`

Each ASCII example should show: default, with label, inverted, and save to file.
SVG example: remove border example, keep the rest.

**Step 1: Update `examples/ascii_fullblocks.php`**

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\FullBlocksRenderer;

echo "=== ScanMePHP - Full Blocks Renderer ===\n\n";

echo "1. Default:\n";
$config = new QRCodeConfig(
    engine: new FullBlocksRenderer(sideMargin: 4),
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo $qr->render();
echo "\n\n";

echo "2. With label:\n";
$config = new QRCodeConfig(
    engine: new FullBlocksRenderer(sideMargin: 4),
    label: 'ScanMePHP'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo $qr->render();
echo "\n\n";

echo "3. Inverted:\n";
$config = new QRCodeConfig(
    engine: new FullBlocksRenderer(sideMargin: 4),
    label: 'Inverted',
    invert: true,
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo $qr->render();
echo "\n\n";

echo "4. Save to file:\n";
$config = new QRCodeConfig(
    engine: new FullBlocksRenderer(),
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile('/tmp/qrcode_fullblocks.txt');
echo "Saved to /tmp/qrcode_fullblocks.txt\n";

echo "\n=== Done! ===\n";
```

**Step 2: Update `examples/ascii_halfblocks.php`**

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\HalfBlocksRenderer;

echo "=== ScanMePHP - Half Blocks Renderer ===\n\n";

echo "1. Default:\n";
$config = new QRCodeConfig(
    engine: new HalfBlocksRenderer(sideMargin: 4),
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo $qr->render();
echo "\n\n";

echo "2. With label:\n";
$config = new QRCodeConfig(
    engine: new HalfBlocksRenderer(sideMargin: 4),
    label: 'ScanMePHP'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo $qr->render();
echo "\n\n";

echo "3. Inverted:\n";
$config = new QRCodeConfig(
    engine: new HalfBlocksRenderer(sideMargin: 4),
    label: 'Inverted',
    invert: true,
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo $qr->render();
echo "\n\n";

echo "4. Save to file:\n";
$config = new QRCodeConfig(
    engine: new HalfBlocksRenderer(),
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile('/tmp/qrcode_halfblocks.txt');
echo "Saved to /tmp/qrcode_halfblocks.txt\n";

echo "\n=== Done! ===\n";
```

**Step 3: Update `examples/ascii_simple.php`**

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\SimpleRenderer;

echo "=== ScanMePHP - Simple Renderer ===\n\n";

echo "1. Default:\n";
$config = new QRCodeConfig(
    engine: new SimpleRenderer(sideMargin: 4),
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo $qr->render();
echo "\n\n";

echo "2. With label:\n";
$config = new QRCodeConfig(
    engine: new SimpleRenderer(sideMargin: 4),
    label: 'ScanMePHP'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo $qr->render();
echo "\n\n";

echo "3. Inverted:\n";
$config = new QRCodeConfig(
    engine: new SimpleRenderer(sideMargin: 4),
    label: 'Inverted',
    invert: true,
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo $qr->render();
echo "\n\n";

echo "4. Save to file:\n";
$config = new QRCodeConfig(
    engine: new SimpleRenderer(),
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile('/tmp/qrcode_simple.txt');
echo "Saved to /tmp/qrcode_simple.txt\n";

echo "\n=== Done! ===\n";
```

**Step 4: Update `examples/svg_example.php`**

Remove the border example (item 8), renumber subsequent items.

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use ScanMePHP\QRCode;
use ScanMePHP\QRCodeConfig;
use ScanMePHP\Renderer\SvgRenderer;
use ScanMePHP\ErrorCorrectionLevel;
use ScanMePHP\ModuleStyle;

echo "=== ScanMePHP - SVG QR Code Example ===\n\n";

echo "1. Basic SVG:\n";
$config = new QRCodeConfig(engine: new SvgRenderer());
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
echo "SVG output length: " . strlen($qr->render()) . " bytes\n\n";

echo "2. Save SVG to file:\n";
$qr->saveToFile('/tmp/qrcode.svg');
echo "Saved to /tmp/qrcode.svg\n\n";

echo "3. SVG with label:\n";
$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    label: 'Scan Me!'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile('/tmp/qrcode_with_label.svg');
echo "Saved to /tmp/qrcode_with_label.svg\n\n";

echo "4. SVG with rounded modules:\n";
$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    moduleStyle: ModuleStyle::Rounded,
    label: 'Rounded Style'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile('/tmp/qrcode_rounded.svg');
echo "Saved to /tmp/qrcode_rounded.svg\n\n";

echo "5. SVG with dot modules:\n";
$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    moduleStyle: ModuleStyle::Dot,
    label: 'Dot Style'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile('/tmp/qrcode_dot.svg');
echo "Saved to /tmp/qrcode_dot.svg\n\n";

echo "6. Dark mode (inverted):\n";
$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    invert: true,
    foregroundColor: '#FFFFFF',
    backgroundColor: '#000000',
    label: 'Dark Mode'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile('/tmp/qrcode_dark.svg');
echo "Saved to /tmp/qrcode_dark.svg\n\n";

echo "7. High error correction level:\n";
$config = new QRCodeConfig(
    engine: new SvgRenderer(),
    errorCorrectionLevel: ErrorCorrectionLevel::High,
    label: 'High ECC'
);
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$qr->saveToFile('/tmp/qrcode_high_ecc.svg');
echo "Saved to /tmp/qrcode_high_ecc.svg\n\n";

echo "8. Data URI:\n";
$config = new QRCodeConfig(engine: new SvgRenderer());
$qr = new QRCode('https://github.com/crazy-goat/ScanMePHP', $config);
$dataUri = $qr->getDataUri();
echo "Data URI (first 100 chars): " . substr($dataUri, 0, 100) . "...\n\n";

echo "9. Base64:\n";
$base64 = $qr->toBase64();
echo "Base64 length: " . strlen($base64) . " bytes\n\n";

echo "10. Get minimum version:\n";
$minVersion = QRCode::getMinimumVersion(
    'https://github.com/crazy-goat/ScanMePHP/blob/main/README.md',
    ErrorCorrectionLevel::Medium
);
echo "Minimum version required: {$minVersion}\n\n";

echo "=== Done! ===\n";
```

**Step 5: Run all tests**

Run: `vendor/bin/phpunit`
Expected: All tests pass.

**Step 6: Run all examples to verify**

```bash
php examples/ascii_fullblocks.php
php examples/ascii_halfblocks.php
php examples/ascii_simple.php
php examples/svg_example.php
```

**Step 7: Commit**

```bash
git add examples/
git commit -m "refactor: update examples for new renderer classes, add invert demos"
```

---

### Task 12: Final verification

**Step 1: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All tests pass, 0 failures, 0 errors.

**Step 2: Verify QR codes are scannable**

```bash
php examples/ascii_fullblocks.php > /dev/null
php examples/svg_example.php > /dev/null
```

**Step 3: Verify no references to old classes remain**

```bash
grep -r "AsciiRenderer\|AsciiStyle" src/ tests/ examples/ --include="*.php"
```
Expected: No matches.
