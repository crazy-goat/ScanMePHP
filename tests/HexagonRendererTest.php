<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Tests;

use CrazyGoat\ScanMePHP\Encoding\MaxiCode\Specs;
use CrazyGoat\ScanMePHP\Format;
use CrazyGoat\ScanMePHP\Renderer\HexagonLattice;
use CrazyGoat\ScanMePHP\Renderer\Layout;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\SvgOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbology;
use PHPUnit\Framework\TestCase;

/**
 * Drawing a hexagonal lattice, which only MaxiCode needs and only two
 * renderers can do.
 *
 * The geometry is worth asserting on its own because it is the half of
 * MaxiCode a decoder cannot check for us cheaply: the encoder's modules are
 * verified against an independent encoder, but whether the picture of them
 * scans depends on hexagons landing where the rows interlock and on the
 * bullseye being drawn at all. The round-trip suite reads a rendered PNG back,
 * so it catches a lattice that is wrong; what it cannot say is *which* number
 * was wrong, and these tests can.
 */
class HexagonRendererTest extends TestCase
{
    private Scanme $scanme;

    protected function setUp(): void
    {
        $this->scanme = Scanme::create();
    }

    /**
     * Rows interlock rather than stack, so 33 rows are under 29 modules tall.
     * A renderer that treated the row count as a height would leave a strip of
     * white below the symbol and scale it wrongly against its width.
     */
    public function testRowsInterlockSoTheLatticeIsShorterThanItsRowCount(): void
    {
        $this->assertLessThan(1.0, HexagonLattice::ROW_PITCH);
        $this->assertSame(0.0, HexagonLattice::height(0));
        $this->assertSame(1.0, HexagonLattice::height(1), 'one row is one module tall');

        $height = HexagonLattice::height(Specs::ROWS);
        $this->assertGreaterThan(28.0, $height);
        $this->assertLessThan(29.0, $height);
    }

    /** A hexagon is flat between its shoulders and tapers to a point. */
    public function testTheHexagonProfileTapersFromTheShouldersToThePoints(): void
    {
        $this->assertSame(HexagonLattice::HALF_WIDTH, HexagonLattice::halfWidthAt(0.0));
        $this->assertSame(HexagonLattice::HALF_WIDTH, HexagonLattice::halfWidthAt(HexagonLattice::SHOULDER));
        $this->assertSame(HexagonLattice::HALF_WIDTH, HexagonLattice::halfWidthAt(-HexagonLattice::SHOULDER));
        $this->assertSame(0.0, HexagonLattice::halfWidthAt(HexagonLattice::HALF_HEIGHT));
        $this->assertSame(0.0, HexagonLattice::halfWidthAt(1.0));

        $middle = HexagonLattice::halfWidthAt((HexagonLattice::SHOULDER + HexagonLattice::HALF_HEIGHT) / 2);
        $this->assertEqualsWithDelta(HexagonLattice::HALF_WIDTH / 2, $middle, 1.0e-9);

        $corners = HexagonLattice::corners(10.0, 20.0);
        $this->assertCount(6, $corners);
        $this->assertSame([10.0, 20.0 - HexagonLattice::HALF_HEIGHT], $corners[0]);
        $this->assertSame([10.0, 20.0 + HexagonLattice::HALF_HEIGHT], $corners[3]);
    }

    /** Odd rows sit half a module to the right; that is what makes it a lattice. */
    public function testOddRowsAreOffsetHalfAModule(): void
    {
        $symbol = $this->scanme->generate('HELLO', Symbology::MaxiCode);
        $layout = Layout::of($symbol, new SvgOptions());

        $this->assertSame(
            0.5,
            HexagonLattice::centreX($layout, 1, 0) - HexagonLattice::centreX($layout, 0, 0)
        );
        $this->assertEqualsWithDelta(
            HexagonLattice::ROW_PITCH,
            HexagonLattice::centreY($layout, 1) - HexagonLattice::centreY($layout, 0),
            1.0e-9
        );

        $centre = HexagonLattice::bullseye($symbol, $layout);
        $this->assertNotNull($centre);
        $this->assertSame(HexagonLattice::centreX($layout, Specs::BULLSEYE_ROW, Specs::BULLSEYE_COLUMN), $centre[0]);
        $this->assertSame(HexagonLattice::centreY($layout, Specs::BULLSEYE_ROW), $centre[1]);
    }

    /**
     * One hexagon per dark module and three rings, which is the whole of the
     * SVG output. Counting them is what catches a lattice that has quietly
     * stopped drawing the last column of the odd rows, or a bullseye that is
     * being skipped because the finder region went missing.
     */
    public function testTheSvgIsOneHexagonPerDarkModulePlusTheBullseye(): void
    {
        $symbol = $this->scanme->generate('HELLO WORLD', Symbology::MaxiCode);
        $svg = $this->scanme->renderSymbol($symbol, Format::Svg, new SvgOptions(moduleSize: 10));

        $dark = substr_count($symbol->toModuleString(), '1');
        $this->assertGreaterThan(0, $dark);
        $this->assertSame($dark, substr_count($svg, 'M'), 'one sub-path per dark module');
        $this->assertSame($dark, substr_count($svg, 'z'), 'and every one of them closed');
        $this->assertSame(
            \count(HexagonLattice::RING_RADII),
            substr_count($svg, '<circle'),
            'the bullseye is drawn as rings, not as modules'
        );
        $this->assertStringContainsString('fill="none"', $svg, 'the rings are stroked, so the gaps show through');
    }

    /**
     * The canvas is as wide as the module grid and as tall as the interlocked
     * rows, not as tall as the row count.
     */
    public function testTheCanvasFitsTheLatticeRatherThanTheRowCount(): void
    {
        $symbol = $this->scanme->generate('HELLO', Symbology::MaxiCode);
        $mod = 10;

        $svg = $this->scanme->renderSymbol($symbol, Format::Svg, new SvgOptions(moduleSize: $mod));
        preg_match('/viewBox="0 0 (\d+) (\d+)"/', $svg, $box);

        $expectedWidth = (Specs::COLUMNS + 2) * $mod;
        $expectedHeight = (int) ceil((HexagonLattice::height(Specs::ROWS) + 2) * $mod);

        $this->assertSame($expectedWidth, (int) $box[1]);
        $this->assertSame($expectedHeight, (int) $box[2]);
        $this->assertLessThan((Specs::ROWS + 2) * $mod, (int) $box[2], 'stacked rows would be taller than this');

        $png = $this->scanme->renderSymbol($symbol, Format::Png, new PngOptions(moduleSize: $mod));
        $header = unpack('Nwidth/Nheight', substr($png, 16, 8));
        $this->assertSame($expectedWidth, $header['width'], 'both renderers agree on the geometry');
        $this->assertSame($expectedHeight, $header['height']);
    }

    /**
     * Inverting swaps which modules are drawn, and the bullseye is drawn
     * either way — it is the finder, not decoration.
     */
    public function testInvertingStillDrawsTheBullseye(): void
    {
        $symbol = $this->scanme->generate('HELLO', Symbology::MaxiCode);
        $modules = $symbol->toModuleString();

        $inverted = $this->scanme->renderSymbol(
            $symbol,
            Format::Svg,
            new SvgOptions(moduleSize: 10, invert: true)
        );

        $this->assertSame(substr_count($modules, '0'), substr_count($inverted, 'z'));
        $this->assertSame(\count(HexagonLattice::RING_RADII), substr_count($inverted, '<circle'));
    }
}
