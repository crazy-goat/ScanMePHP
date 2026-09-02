<?php

declare(strict_types=1);

use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Format;
use CrazyGoat\ScanMePHP\Generator\Qr\QrGenerator;
use CrazyGoat\ScanMePHP\Generator\Qr\QrOptions;
use CrazyGoat\ScanMePHP\ModuleStyle;
use CrazyGoat\ScanMePHP\Options\RenderOptionsInterface;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Renderer\AsciiRenderer;
use CrazyGoat\ScanMePHP\Renderer\AsciiStyle;
use CrazyGoat\ScanMePHP\Renderer\HtmlMode;
use CrazyGoat\ScanMePHP\Renderer\HtmlRenderer;
use CrazyGoat\ScanMePHP\Renderer\Options\AsciiOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\HtmlOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\SvgOptions;
use CrazyGoat\ScanMePHP\Renderer\PngEncoder;
use CrazyGoat\ScanMePHP\Renderer\PngRenderer;
use CrazyGoat\ScanMePHP\Renderer\RendererInterface;
use CrazyGoat\ScanMePHP\Renderer\SvgRenderer;
use CrazyGoat\ScanMePHP\Symbol;
use PHPUnit\Framework\TestCase;

/**
 * The renderers work on Symbol::toModuleString() with whole-symbol string
 * operations. These tests pin their output to a naive per-module reference and
 * check that every module storage representation renders identically.
 */
class RendererTest extends TestCase
{
    private const QUIET = 4;

    /** Dark modules in one 7×7 QR finder pattern: a 5×5 ring plus a 3×3 centre. */
    private const FINDER_DARK = 33;

    /**
     * bool[]-, int[]- and string-backed copies of one symbol.
     *
     * @return array{Symbol, Symbol, Symbol}
     */
    private function symbols(string $payload = 'https://qrcode.crazy-goat.com/?renderer=test'): array
    {
        $string = (new QrGenerator())->generate($payload, new QrOptions(ErrorCorrectionLevel::Medium));
        $modules = $string->toModuleString();

        $ints = [];
        $bools = [];
        for ($i = 0, $length = \strlen($modules); $i < $length; $i++) {
            $ints[] = $modules[$i] === '1' ? 1 : 0;
            $bools[] = $modules[$i] === '1';
        }

        $rebuild = static fn (array|string $data): Symbol => new Symbol(
            width: $string->getWidth(),
            height: $string->getHeight(),
            modules: $data,
            quietZone: $string->getQuietZone(),
            finderRegions: $string->getFinderRegions(),
            metadata: $string->getMetadata(),
        );

        return [$rebuild($bools), $rebuild($ints), $string];
    }

    /** @return iterable<string, array{RendererInterface, RenderOptionsInterface}> */
    public static function rendererProvider(): iterable
    {
        yield 'blocks' => [new AsciiRenderer(AsciiStyle::Blocks), new AsciiOptions(label: 'Hi', sideMargin: 3)];
        yield 'blocks inverted' => [new AsciiRenderer(AsciiStyle::Blocks), new AsciiOptions(invert: true)];
        yield 'half' => [new AsciiRenderer(AsciiStyle::HalfBlocks), new AsciiOptions(quietZone: 0, sideMargin: 2)];
        yield 'half inverted' => [new AsciiRenderer(AsciiStyle::HalfBlocks), new AsciiOptions(invert: true, label: 'x')];
        yield 'dots' => [new AsciiRenderer(AsciiStyle::Dots), new AsciiOptions()];
        yield 'svg square' => [new SvgRenderer(), new SvgOptions(moduleSize: 8, label: 'Scan <me> & go')];
        yield 'svg inverted' => [new SvgRenderer(), new SvgOptions(invert: true)];
        yield 'svg rounded' => [new SvgRenderer(), new SvgOptions(moduleSize: 7, moduleStyle: ModuleStyle::Rounded)];
        yield 'svg dot' => [new SvgRenderer(), new SvgOptions(moduleSize: 9, quietZone: 1, moduleStyle: ModuleStyle::Dot)];
        yield 'html div' => [new HtmlRenderer(HtmlMode::Div), new HtmlOptions(moduleSize: 6, foregroundColor: '#123456', label: 'L')];
        yield 'html table' => [new HtmlRenderer(HtmlMode::Table), new HtmlOptions(invert: true)];
        yield 'png' => [new PngRenderer(), new PngOptions(moduleSize: 5, quietZone: 2)];
        yield 'png inverted level 6' => [new PngRenderer(), new PngOptions(moduleSize: 4, invert: true, compressionLevel: 6)];
    }

    /** @dataProvider rendererProvider */
    public function testAllModuleRepresentationsRenderIdentically(
        RendererInterface $renderer,
        RenderOptionsInterface $options
    ): void {
        [$bools, $ints, $string] = $this->symbols();

        $expected = $renderer->render($bools, $options);
        $this->assertSame($expected, $renderer->render($ints, $options));
        $this->assertSame($expected, $renderer->render($string, $options));
        // Second render hits the cached module string.
        $this->assertSame($expected, $renderer->render($bools, $options));
    }

    public function testSymbolsAreNotMutatedByRendering(): void
    {
        [, , $string] = $this->symbols();
        $before = $string->toModuleString();

        foreach (self::rendererProvider() as [$renderer, $options]) {
            $renderer->render($string, $options);
        }

        $this->assertSame($before, $string->toModuleString());
        $this->assertSame($before[0] === '1', $string->get(0, 0));
    }

    /** @return iterable<string, array{bool, int, int}> */
    public static function asciiProvider(): iterable
    {
        foreach ([false, true] as $invert) {
            foreach ([[0, 0], [4, 2], [1, 5]] as [$quietZone, $sideMargin]) {
                yield sprintf('invert=%d quiet=%d side=%d', $invert, $quietZone, $sideMargin)
                    => [$invert, $quietZone, $sideMargin];
            }
        }
    }

    /** @dataProvider asciiProvider */
    public function testBlocksMatchPerModuleReference(bool $invert, int $quietZone, int $sideMargin): void
    {
        [$symbol] = $this->symbols();
        $options = new AsciiOptions(quietZone: $quietZone, invert: $invert, label: 'Ref', sideMargin: $sideMargin);
        $background = $invert ? '█' : ' ';
        $size = $symbol->getWidth();
        $width = $size + 2 * $quietZone + 2 * $sideMargin;

        $lines = array_fill(0, $quietZone, str_repeat($background, $width));
        for ($y = 0; $y < $size; $y++) {
            $line = str_repeat($background, $quietZone + $sideMargin);
            for ($x = 0; $x < $size; $x++) {
                $line .= $symbol->get($x, $y) ? '█' : ' ';
            }
            $lines[] = $line . str_repeat($background, $quietZone + $sideMargin);
        }
        $lines[] = str_repeat($background, $width);
        $lines[] = $this->centre(' Ref ', $width, $background);
        for ($i = 0; $i < $quietZone; $i++) {
            $lines[] = str_repeat($background, $width);
        }

        $this->assertSame(
            implode("\n", $lines),
            (new AsciiRenderer(AsciiStyle::Blocks))->render($symbol, $options)
        );
    }

    /** @dataProvider asciiProvider */
    public function testHalfBlocksMatchPerModuleReference(bool $invert, int $quietZone, int $sideMargin): void
    {
        [$symbol] = $this->symbols();
        $options = new AsciiOptions(quietZone: $quietZone, invert: $invert, sideMargin: $sideMargin);
        $background = $invert ? '█' : ' ';
        $size = $symbol->getWidth();
        $width = $size + 2 * $quietZone + 2 * $sideMargin;

        // One text line covers two module rows, so a quiet zone measured in
        // modules costs half as many lines. The old renderer spent one line per
        // module of margin, which drew twice the quiet zone the spec asks for.
        $quietLines = (int) ceil($quietZone / 2);

        $lines = array_fill(0, $quietLines, str_repeat($background, $width));
        for ($y = 0; $y < $size; $y += 2) {
            $line = str_repeat($background, $quietZone + $sideMargin);
            for ($x = 0; $x < $size; $x++) {
                $top = $symbol->get($x, $y) !== $invert;
                $bottom = ($y + 1 < $size && $symbol->get($x, $y + 1)) !== $invert;
                $line .= [' ', '▀', '▄', '█'][(int) $top | ((int) $bottom << 1)];
            }
            $lines[] = $line . str_repeat($background, $quietZone + $sideMargin);
        }
        for ($i = 0; $i < $quietLines; $i++) {
            $lines[] = str_repeat($background, $width);
        }

        $this->assertSame(
            implode("\n", $lines),
            (new AsciiRenderer(AsciiStyle::HalfBlocks))->render($symbol, $options)
        );
    }

    public function testHtmlDivDrawsOneCellPerModuleAndBlocksForTheQuietZone(): void
    {
        [$symbol] = $this->symbols();
        $options = new HtmlOptions(foregroundColor: '#112233', backgroundColor: '#FFEEDD');
        $size = $symbol->getWidth();
        $html = (new HtmlRenderer(HtmlMode::Div))->render($symbol, $options);

        $cell = static fn (string $color): string
            => '<div style="width:10px;height:10px;background:' . $color . '"></div>';

        $expected = '<div style="display:inline-block;background:#FFEEDD;padding:0;line-height:0">';
        // The quiet zone carries no information, so it is four sized blocks
        // rather than the ~700 padding cells a per-module zone would emit.
        $expected .= '<div style="width:' . (($size + 8) * 10) . 'px;height:40px;background:#FFEEDD"></div>';
        $side = '<div style="width:40px;background:#FFEEDD"></div>';
        for ($y = 0; $y < $size; $y++) {
            $expected .= '<div style="display:flex">' . $side;
            for ($x = 0; $x < $size; $x++) {
                $expected .= $cell($symbol->get($x, $y) ? '#112233' : '#FFEEDD');
            }
            $expected .= $side . '</div>';
        }
        $expected .= '<div style="width:' . (($size + 8) * 10) . 'px;height:40px;background:#FFEEDD"></div>';
        $expected .= '</div>';

        $this->assertSame($expected, $html);
        $this->assertSame($size * $size, substr_count($html, '<div style="width:10px'));
    }

    public function testHtmlTableSpansTheQuietZoneSoColumnsDoNotSkew(): void
    {
        [$symbol] = $this->symbols();
        $size = $symbol->getWidth();
        $html = (new HtmlRenderer(HtmlMode::Table))->render($symbol, new HtmlOptions());

        $this->assertSame($size + 2, substr_count($html, '<tr>'), 'one row per module plus two quiet rows');
        $this->assertSame($size * $size, substr_count($html, 'width:10px;height:10px'));
        $this->assertSame(
            substr_count($symbol->toModuleString(), '1'),
            substr_count($html, 'background:#000000"></td>')
        );
        // A fixed-width cell without a colspan would fight the module columns
        // for width in a border-collapsed table and skew the grid.
        $this->assertSame(2, substr_count($html, 'colspan="' . ($size + 8) . '"'));
        $this->assertSame($size, substr_count($html, 'colspan="4"') / 2);
    }

    public function testSvgSquareEmitsOnePathPlusFinderRects(): void
    {
        [$symbol] = $this->symbols();
        $svg = (new SvgRenderer())->render($symbol, new SvgOptions());
        $size = $symbol->getWidth();

        $this->assertSame(1, substr_count($svg, '<path '));
        // Three finder patterns, each dark module its own rounded rect, plus
        // the background rect.
        $this->assertSame(1 + 3 * self::FINDER_DARK, substr_count($svg, '<rect '));
        $this->assertSame(3 * self::FINDER_DARK, substr_count($svg, 'rx="1.5"'));

        // Every dark module outside the finders is covered by exactly one run.
        preg_match('/<path fill="#000000" d="([^"]*)"/', $svg, $m);
        preg_match_all('/M(\d+) (\d+)h(\d+)v10h-\3z/', $m[1], $runs, PREG_SET_ORDER);
        $covered = 0;
        foreach ($runs as [, $px, $py, $pw]) {
            $x = (int) $px / 10 - self::QUIET;
            $y = (int) $py / 10 - self::QUIET;
            for ($i = 0; $i < (int) $pw / 10; $i++) {
                $this->assertTrue($symbol->get($x + $i, $y), "run covers a light module at ($x+$i, $y)");
                $this->assertFalse($this->inFinder($x + $i, $y, $size), 'run covers a finder module');
                $covered++;
            }
        }
        $this->assertSame(substr_count($symbol->toModuleString(), '1') - 3 * self::FINDER_DARK, $covered);
        $this->assertSame(
            \strlen($m[1]),
            \strlen(implode('', array_column($runs, 0))),
            'path holds nothing but runs'
        );
    }

    public function testSvgFinderRoundingCanBeTurnedOff(): void
    {
        [$symbol] = $this->symbols();
        $plain = (new SvgRenderer())->render($symbol, new SvgOptions(roundFinderRegions: false));

        $this->assertSame(1, substr_count($plain, '<rect '), 'background only');
        $this->assertStringNotContainsString('rx="1.5"', $plain);
    }

    public function testSvgRoundedAndDotDrawOneElementPerDarkModule(): void
    {
        [$symbol] = $this->symbols();
        $dark = substr_count($symbol->toModuleString(), '1');
        $size = $symbol->getWidth();

        $rounded = (new SvgRenderer())->render($symbol, new SvgOptions(moduleStyle: ModuleStyle::Rounded));
        $this->assertSame(1 + $dark, substr_count($rounded, '<rect '));
        $this->assertSame($dark - 3 * self::FINDER_DARK, substr_count($rounded, 'rx="3.0"'));

        $dot = (new SvgRenderer())->render($symbol, new SvgOptions(moduleStyle: ModuleStyle::Dot));
        $this->assertSame($dark - 3 * self::FINDER_DARK, substr_count($dot, '<circle '));

        // First dark module of row 8 outside the finders: circle centred on it.
        for ($x = 9; !$symbol->get($x, 8); $x++) {
        }
        $this->assertLessThan($size - 7, $x);
        $this->assertStringContainsString(
            sprintf('<circle cx="%d" cy="%d" r="4.0"', ($x + self::QUIET) * 10 + 5, (8 + self::QUIET) * 10 + 5),
            $dot
        );
    }

    public function testSvgInvertedDrawsLightModules(): void
    {
        [$symbol] = $this->symbols();
        $svg = (new SvgRenderer())->render($symbol, new SvgOptions(quietZone: 0, invert: true));

        preg_match('/<path fill="#FFFFFF" d="([^"]*)"/', $svg, $m);
        preg_match_all('/M(\d+) (\d+)h(\d+)/', $m[1], $runs, PREG_SET_ORDER);
        foreach ($runs as [, $px, $py, $pw]) {
            for ($i = 0; $i < (int) $pw / 10; $i++) {
                $this->assertFalse($symbol->get((int) $px / 10 + $i, (int) $py / 10));
            }
        }
        $this->assertNotEmpty($runs);
    }

    public function testSvgTextGetsItsOwnSpaceBelowTheQuietZone(): void
    {
        [$symbol] = $this->symbols();
        $side = ($symbol->getWidth() + 2 * self::QUIET) * 10;

        $plain = (new SvgRenderer())->render($symbol, new SvgOptions());
        $this->assertStringContainsString(sprintf('viewBox="0 0 %d %d"', $side, $side), $plain);

        // A caption drawn inside the symbol's own box would either sit on the
        // bottom quiet zone or fall outside the viewBox and not render at all.
        $labelled = (new SvgRenderer())->render($symbol, new SvgOptions(label: 'Ticket'));
        $this->assertStringContainsString(sprintf('viewBox="0 0 %d %d"', $side, $side + 20), $labelled);
        $this->assertStringContainsString('>Ticket</text>', $labelled);
    }

    public function testPngPixelsMatchTheSymbolAtEveryCompressionLevel(): void
    {
        [$symbol] = $this->symbols();
        $size = $symbol->getWidth();
        $quiet = 2;
        $reference = null;

        foreach ([[0, false], [1, false], [6, true], [9, false]] as [$level, $invert]) {
            $png = (new PngRenderer())->render($symbol, new PngOptions(
                moduleSize: 3,
                quietZone: $quiet,
                invert: $invert,
                compressionLevel: $level,
            ));
            $image = imagecreatefromstring($png);
            $this->assertNotFalse($image);
            $this->assertSame(($size + 2 * $quiet) * 3, imagesx($image));
            $this->assertSame(imagesx($image), imagesy($image));

            for ($y = -$quiet; $y < $size + $quiet; $y++) {
                for ($x = -$quiet; $x < $size + $quiet; $x++) {
                    $dark = $symbol->get($x, $y) !== $invert;
                    // Sample the centre pixel of the module (1-bit PNG → palette index).
                    $colour = imagecolorsforindex(
                        $image,
                        imagecolorat($image, ($x + $quiet) * 3 + 1, ($y + $quiet) * 3 + 1)
                    );
                    $this->assertSame($dark, $colour['red'] < 128, "module ($x,$y) at level $level");
                }
            }

            if (!$invert) {
                $this->assertTrue($reference === null || \strlen($png) <= $reference, 'higher level never grows');
                $reference = $level === 0 ? null : \strlen($png);
            }
        }
    }

    public function testPngBitPackingFallbackMatchesGmp(): void
    {
        if (!\function_exists('gmp_init')) {
            $this->markTestSkipped('ext-gmp not loaded; only the bindec() path is available here');
        }

        mt_srand(20260825);
        foreach ([8, 56, 64, 112, 656, 1256, 8 * 501] as $length) { // multiples of 8, incl. non-multiples of 56
            $bits = '';
            for ($i = 0; $i < $length; $i++) {
                $bits .= mt_rand(0, 1) ? '1' : '0';
            }
            $cases = [$bits, str_repeat('0', $length), str_repeat('1', $length), str_repeat('0', $length - 1) . '1'];
            foreach ($cases as $case) {
                $bytes = intdiv($length, 8);
                $expected = PngEncoder::packBits($case, $bytes, true);
                $this->assertSame($bytes, \strlen($expected));
                $this->assertSame($expected, PngEncoder::packBits($case, $bytes, false), "length $length");
                $this->assertSame($case, implode('', array_map(
                    static fn (int $byte): string => sprintf('%08b', $byte),
                    array_values((array) unpack('C*', $expected))
                )));
            }
        }
    }

    public function testPngRejectsInvalidCompressionLevel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PngOptions(compressionLevel: 10);
    }

    public function testLinearSymbolBarsBecomePixelRowsNotSquares(): void
    {
        // What EAN-13 and Code128 will hand the renderers: one module row whose
        // height is presentation rather than data.
        $bars = Symbol::linear('10110010', new QuietZone(left: 11, right: 7), 30);

        $png = (new PngRenderer())->render($bars, new PngOptions(moduleSize: 2));
        $ihdr = unpack('Nwidth/Nheight', substr($png, 16, 8));

        $this->assertSame((8 + 11 + 7) * 2, $ihdr['width']);
        $this->assertSame(30 * 2, $ihdr['height']);

        $image = imagecreatefromstring($png);
        $this->assertNotFalse($image);
        foreach ([0, 29, 59] as $y) {
            $colour = imagecolorsforindex($image, imagecolorat($image, 11 * 2 + 1, $y));
            $this->assertTrue($colour['red'] < 128, "first bar must be dark for the whole height at y=$y");
        }
    }

    public function testFormatsAndContentTypesArePinned(): void
    {
        $expected = [
            [new SvgRenderer(), Format::Svg, 'image/svg+xml'],
            [new PngRenderer(), Format::Png, 'image/png'],
            [new HtmlRenderer(HtmlMode::Div), Format::HtmlDiv, 'text/html'],
            [new HtmlRenderer(HtmlMode::Table), Format::HtmlTable, 'text/html'],
            [new AsciiRenderer(AsciiStyle::Blocks), Format::AsciiBlocks, 'text/plain'],
            [new AsciiRenderer(AsciiStyle::HalfBlocks), Format::AsciiHalfBlocks, 'text/plain'],
            [new AsciiRenderer(AsciiStyle::Dots), Format::AsciiDots, 'text/plain'],
        ];

        foreach ($expected as [$renderer, $format, $contentType]) {
            $this->assertSame($format->value, $renderer->getFormat());
            $this->assertSame($contentType, $renderer->getContentType());
        }
    }

    private function inFinder(int $x, int $y, int $size): bool
    {
        return ($x < 7 && $y < 7) || ($x >= $size - 7 && $y < 7) || ($x < 7 && $y >= $size - 7);
    }

    private function centre(string $text, int $width, string $fill): string
    {
        $length = mb_strlen($text);
        if ($length >= $width) {
            return $text;
        }

        $leftPad = intdiv($width - $length, 2);

        return str_repeat($fill, $leftPad) . $text . str_repeat($fill, $width - $length - $leftPad);
    }
}
