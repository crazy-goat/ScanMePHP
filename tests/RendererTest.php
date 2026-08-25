<?php

declare(strict_types=1);

use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Matrix;
use CrazyGoat\ScanMePHP\ModuleStyle;
use CrazyGoat\ScanMePHP\Renderer\FullBlocksRenderer;
use CrazyGoat\ScanMePHP\Renderer\HalfBlocksRenderer;
use CrazyGoat\ScanMePHP\Renderer\HtmlDivRenderer;
use CrazyGoat\ScanMePHP\Renderer\HtmlTableRenderer;
use CrazyGoat\ScanMePHP\Renderer\PngEncoder;
use CrazyGoat\ScanMePHP\Renderer\PngRenderer;
use CrazyGoat\ScanMePHP\Renderer\SimpleRenderer;
use CrazyGoat\ScanMePHP\Renderer\SvgRenderer;
use CrazyGoat\ScanMePHP\RendererInterface;
use CrazyGoat\ScanMePHP\RenderOptions;
use PHPUnit\Framework\TestCase;

/**
 * The renderers work on Matrix::toModuleString() with whole-matrix string
 * operations. These tests pin their output to a naive per-module reference
 * and check that every Matrix representation renders identically.
 */
class RendererTest extends TestCase
{
    /** @return array{Matrix, Matrix, Matrix} bool[]-, int[]- and string-backed copies of one symbol */
    private function matrices(string $payload = 'https://qrcode.crazy-goat.com/?renderer=test'): array
    {
        $bools = (new Encoder())->encode($payload, ErrorCorrectionLevel::Medium);
        $raw = $bools->getRawData();
        $version = $bools->getVersion();

        $ints = new Matrix($version, array_map(intval(...), $raw), normalized: false);
        $string = Matrix::fromModuleString($version, implode('', array_map(intval(...), $raw)));

        return [$bools, $ints, $string];
    }

    /** @return iterable<string, array{RendererInterface, RenderOptions}> */
    public static function rendererProvider(): iterable
    {
        yield 'full' => [new FullBlocksRenderer(3), new RenderOptions(label: 'Hi')];
        yield 'full inverted' => [new FullBlocksRenderer(), new RenderOptions(invert: true)];
        yield 'half' => [new HalfBlocksRenderer(2), new RenderOptions(margin: 0)];
        yield 'half inverted' => [new HalfBlocksRenderer(), new RenderOptions(label: 'x', invert: true)];
        yield 'simple' => [new SimpleRenderer(), new RenderOptions()];
        yield 'svg square' => [new SvgRenderer(8), new RenderOptions(label: 'Scan <me> & go')];
        yield 'svg inverted' => [new SvgRenderer(), new RenderOptions(invert: true)];
        yield 'svg rounded' => [new SvgRenderer(7), new RenderOptions(moduleStyle: ModuleStyle::Rounded)];
        yield 'svg dot' => [new SvgRenderer(9), new RenderOptions(margin: 1, moduleStyle: ModuleStyle::Dot)];
        yield 'html div' => [new HtmlDivRenderer(6, true), new RenderOptions(label: 'L', foregroundColor: '#123456')];
        yield 'html table' => [new HtmlTableRenderer(), new RenderOptions(invert: true)];
        yield 'png' => [new PngRenderer(5), new RenderOptions(margin: 2)];
        yield 'png inverted level 6' => [new PngRenderer(4, 6), new RenderOptions(invert: true)];
    }

    /** @dataProvider rendererProvider */
    public function testAllMatrixRepresentationsRenderIdentically(RendererInterface $renderer, RenderOptions $options): void
    {
        [$bools, $ints, $string] = $this->matrices();

        $expected = $renderer->render($bools, $options);
        $this->assertSame($expected, $renderer->render($ints, $options));
        $this->assertSame($expected, $renderer->render($string, $options));
        // Second render hits the cached module string.
        $this->assertSame($expected, $renderer->render($bools, $options));
    }

    /** @return iterable<string, array{bool, int, int}> */
    public static function asciiProvider(): iterable
    {
        foreach ([false, true] as $invert) {
            foreach ([[0, 0], [4, 2], [1, 5]] as [$margin, $sideMargin]) {
                yield sprintf('invert=%d margin=%d side=%d', $invert, $margin, $sideMargin) => [$invert, $margin, $sideMargin];
            }
        }
    }

    /** @dataProvider asciiProvider */
    public function testFullBlocksMatchesPerModuleReference(bool $invert, int $margin, int $sideMargin): void
    {
        [$matrix] = $this->matrices();
        $options = new RenderOptions(margin: $margin, label: 'Ref', invert: $invert);
        $bg = $invert ? '█' : ' ';
        $size = $matrix->getSize();

        $lines = array_fill(0, $margin, str_repeat($bg, $size + 2 * $sideMargin));
        for ($y = 0; $y < $size; $y++) {
            $line = str_repeat($bg, $sideMargin);
            for ($x = 0; $x < $size; $x++) {
                $line .= $matrix->get($x, $y) ? '█' : ' ';
            }
            $lines[] = $line . str_repeat($bg, $sideMargin);
        }
        $lines[] = str_repeat($bg, $size + 2 * $sideMargin);
        $lines[] = $this->centre(' Ref ', $size + 2 * $sideMargin, $bg);
        for ($i = 0; $i < $margin; $i++) {
            $lines[] = str_repeat($bg, $size + 2 * $sideMargin);
        }

        $this->assertSame(implode("\n", $lines), (new FullBlocksRenderer($sideMargin))->render($matrix, $options));
    }

    /** @dataProvider asciiProvider */
    public function testHalfBlocksMatchesPerModuleReference(bool $invert, int $margin, int $sideMargin): void
    {
        [$matrix] = $this->matrices();
        $options = new RenderOptions(margin: $margin, invert: $invert);
        $bg = $invert ? '█' : ' ';
        $size = $matrix->getSize();
        $width = $size + 2 * $sideMargin;

        $lines = array_fill(0, $margin, str_repeat($bg, $width));
        for ($y = 0; $y < $size; $y += 2) {
            $line = str_repeat($bg, $sideMargin);
            for ($x = 0; $x < $size; $x++) {
                $top = $matrix->get($x, $y) !== $invert;
                $bottom = ($y + 1 < $size && $matrix->get($x, $y + 1)) !== $invert;
                $line .= [' ', '▀', '▄', '█'][(int) $top | ((int) $bottom << 1)];
            }
            $lines[] = $line . str_repeat($bg, $sideMargin);
        }
        for ($i = 0; $i < $margin; $i++) {
            $lines[] = str_repeat($bg, $width);
        }

        $this->assertSame(implode("\n", $lines), (new HalfBlocksRenderer($sideMargin))->render($matrix, $options));
    }

    public function testHtmlDivMatchesPerModuleReference(): void
    {
        [$matrix] = $this->matrices();
        $options = new RenderOptions(margin: 2, foregroundColor: '#112233', backgroundColor: '#FFEEDD');
        $size = $matrix->getSize();
        $total = $size + 4;

        $cell = static fn (string $color): string => '<div style="width:10px;height:10px;background:' . $color . '"></div>';
        $expected = '<div style="display:inline-block;background:#FFEEDD;padding:0;line-height:0">';
        for ($y = -2; $y < $size + 2; $y++) {
            $expected .= '<div style="display:flex">';
            for ($x = -2; $x < $size + 2; $x++) {
                $expected .= $cell($matrix->get($x, $y) ? '#112233' : '#FFEEDD');
            }
            $expected .= '</div>';
        }
        $expected .= '</div>';

        $this->assertSame($expected, (new HtmlDivRenderer())->render($matrix, $options));
        $this->assertSame($total * $total, substr_count($expected, '<div style="width'));
    }

    public function testHtmlTableHasOneCellPerModuleIncludingMargin(): void
    {
        [$matrix] = $this->matrices();
        $total = $matrix->getSize() + 8;
        $html = (new HtmlTableRenderer())->render($matrix, new RenderOptions());

        $this->assertSame($total, substr_count($html, '<tr>'));
        $this->assertSame($total * $total, substr_count($html, '<td '));
        $dark = substr_count($matrix->toModuleString(), '1');
        $this->assertSame($dark, substr_count($html, 'background:#000000"></td>'));
    }

    public function testSvgSquareEmitsOnePathPlusFinderRects(): void
    {
        [$matrix] = $this->matrices();
        $svg = (new SvgRenderer(10))->render($matrix, new RenderOptions(margin: 4));
        $size = $matrix->getSize();

        $this->assertSame(1, substr_count($svg, '<path '));
        // 3 finder patterns × 33 dark modules, each its own rounded rect; plus the background rect.
        $this->assertSame(1 + 3 * 33, substr_count($svg, '<rect '));
        $this->assertSame(3 * 33, substr_count($svg, 'rx="1.5"'));

        // Every dark module outside the finders is covered by exactly one run.
        preg_match('/<path fill="#000000" d="([^"]*)"/', $svg, $m);
        preg_match_all('/M(\d+) (\d+)h(\d+)v10h-\3z/', $m[1], $runs, PREG_SET_ORDER);
        $covered = 0;
        foreach ($runs as [, $px, $py, $pw]) {
            $x = (int) $px / 10 - 4;
            $y = (int) $py / 10 - 4;
            for ($i = 0; $i < (int) $pw / 10; $i++) {
                $this->assertTrue($matrix->get($x + $i, $y), "run covers a light module at ($x+$i, $y)");
                $this->assertFalse($this->inFinder($x + $i, $y, $size), 'run covers a finder module');
                $covered++;
            }
        }
        $this->assertSame(substr_count($matrix->toModuleString(), '1') - 3 * 33, $covered);
        $this->assertSame(\strlen($m[1]), \strlen(implode('', array_column($runs, 0))), 'path holds nothing but runs');
    }

    public function testSvgRoundedAndDotDrawOneElementPerDarkModule(): void
    {
        [$matrix] = $this->matrices();
        $dark = substr_count($matrix->toModuleString(), '1');

        $rounded = (new SvgRenderer(10))->render($matrix, new RenderOptions(moduleStyle: ModuleStyle::Rounded));
        $this->assertSame(1 + $dark, substr_count($rounded, '<rect '));
        $this->assertSame($dark - 3 * 33, substr_count($rounded, 'rx="3.0"'));

        $dot = (new SvgRenderer(10))->render($matrix, new RenderOptions(moduleStyle: ModuleStyle::Dot));
        $this->assertSame($dark - 3 * 33, substr_count($dot, '<circle '));
        // first dark module of row 8 outside the finders: circle centred in that module
        $size = $matrix->getSize();
        for ($x = 9; !$matrix->get($x, 8); $x++) {
        }
        $this->assertStringContainsString(sprintf('<circle cx="%d" cy="125" r="4.0"', ($x + 4) * 10 + 5), $dot);
        $this->assertLessThan($size - 7, $x);
    }

    public function testSvgInvertedDrawsLightModules(): void
    {
        [$matrix] = $this->matrices();
        $svg = (new SvgRenderer())->render($matrix, new RenderOptions(margin: 0, invert: true));
        preg_match('/<path fill="#FFFFFF" d="([^"]*)"/', $svg, $m);
        preg_match_all('/M(\d+) (\d+)h(\d+)/', $m[1], $runs, PREG_SET_ORDER);
        foreach ($runs as [, $px, $py, $pw]) {
            for ($i = 0; $i < (int) $pw / 10; $i++) {
                $this->assertFalse($matrix->get((int) $px / 10 + $i, (int) $py / 10));
            }
        }
        $this->assertNotEmpty($runs);
    }

    public function testPngPixelsMatchMatrixAtEveryCompressionLevel(): void
    {
        [$matrix] = $this->matrices();
        $size = $matrix->getSize();
        $reference = null;

        foreach ([[0, false], [1, false], [6, true], [9, false]] as [$level, $invert]) {
            $png = (new PngRenderer(3, $level))->render($matrix, new RenderOptions(margin: 2, invert: $invert));
            $image = imagecreatefromstring($png);
            $this->assertNotFalse($image);
            $this->assertSame(($size + 4) * 3, imagesx($image));

            for ($y = -2; $y < $size + 2; $y++) {
                for ($x = -2; $x < $size + 2; $x++) {
                    $dark = $matrix->get($x, $y) !== $invert;
                    // sample the centre pixel of the module (1-bit PNG → palette index)
                    $colour = imagecolorsforindex($image, imagecolorat($image, ($x + 2) * 3 + 1, ($y + 2) * 3 + 1));
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
            foreach ([$bits, str_repeat('0', $length), str_repeat('1', $length), str_repeat('0', $length - 1) . '1'] as $case) {
                $bytes = intdiv($length, 8);
                $expected = PngEncoder::packBits($case, $bytes, true);
                $this->assertSame($bytes, \strlen($expected));
                $this->assertSame($expected, PngEncoder::packBits($case, $bytes, false), "length $length");
                $this->assertSame($case, implode('', array_map(static fn (int $b): string => sprintf('%08b', $b), array_values(unpack('C*', $expected)))));
            }
        }
    }

    public function testPngRejectsInvalidCompressionLevel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PngRenderer(10, 10);
    }

    public function testRenderersDoNotMutateAStringBackedMatrix(): void
    {
        [, , $string] = $this->matrices();
        $before = $string->toModuleString();
        foreach (self::rendererProvider() as [$renderer, $options]) {
            $renderer->render($string, $options);
        }
        $this->assertSame($before, $string->toModuleString());
        $this->assertSame($before, implode('', array_map(intval(...), $string->getRawData())));
    }

    private function inFinder(int $x, int $y, int $size): bool
    {
        return ($x < 7 && $y < 7) || ($x >= $size - 7 && $y < 7) || ($x < 7 && $y >= $size - 7);
    }

    private function centre(string $text, int $width, string $fill): string
    {
        $len = mb_strlen($text);
        $left = intdiv($width - $len, 2);

        return str_repeat($fill, $left) . $text . str_repeat($fill, $width - $len - $left);
    }
}
