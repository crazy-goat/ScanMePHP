<?php

declare(strict_types=1);

use CrazyGoat\ScanMePHP\Generator\Qr\QrGenerator;
use CrazyGoat\ScanMePHP\Renderer\AsciiRenderer;
use CrazyGoat\ScanMePHP\Renderer\AsciiStyle;
use CrazyGoat\ScanMePHP\Renderer\Options\AsciiOptions;
use CrazyGoat\ScanMePHP\Symbol;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for GitHub issue #35: ASCII symbols were missing the bottom
 * quiet zone in inverted mode, which showed up as a white line along the
 * bottom edge and cost the symbol its bottom margin.
 *
 * The quiet zone is now measured in modules and comes from the symbology, so
 * the half-blocks style — two module rows per text line — spends half as many
 * lines on it as the blocks style. The old renderer spent one line per module
 * on both, which silently doubled the zone for half blocks.
 */
class AsciiQuietZoneTest extends TestCase
{
    private const URL = 'https://qrcode.crazy-goat.com';

    private function symbol(): Symbol
    {
        return (new QrGenerator())->generate(self::URL);
    }

    /**
     * Count fully-blank lines at the top and at the bottom.
     *
     * @param list<string> $lines
     * @return array{int, int}
     */
    private function countMarginLines(array $lines, string $marginChar): array
    {
        $blank = static fn (string $line): bool => $line !== ''
            && preg_match('/[^' . preg_quote($marginChar, '/') . ']/u', $line) !== 1;

        $top = 0;
        foreach ($lines as $line) {
            if (!$blank($line)) {
                break;
            }
            $top++;
        }

        $bottom = 0;
        foreach (array_reverse($lines) as $line) {
            if (!$blank($line)) {
                break;
            }
            $bottom++;
        }

        return [$top, $bottom];
    }

    /** @return iterable<string, array{AsciiStyle, bool, int}> */
    public static function styleProvider(): iterable
    {
        // Expected margin lines for a 4-module quiet zone: one line per module
        // for blocks and dots, one line per two modules for half blocks.
        yield 'blocks' => [AsciiStyle::Blocks, false, 4];
        yield 'blocks inverted' => [AsciiStyle::Blocks, true, 4];
        yield 'dots' => [AsciiStyle::Dots, false, 4];
        yield 'dots inverted' => [AsciiStyle::Dots, true, 4];
        yield 'half blocks' => [AsciiStyle::HalfBlocks, false, 2];
        yield 'half blocks inverted' => [AsciiStyle::HalfBlocks, true, 2];
    }

    /** @dataProvider styleProvider */
    public function testQuietZoneIsSymmetricAndSpecSized(AsciiStyle $style, bool $invert, int $expected): void
    {
        $symbol = $this->symbol();
        $this->assertSame(4, $symbol->getQuietZone()->top, 'ISO/IEC 18004 requires 4 modules');

        $output = (new AsciiRenderer($style))->render(
            $symbol,
            new AsciiOptions(invert: $invert, sideMargin: 4)
        );

        $marginChar = $invert ? ($style === AsciiStyle::Dots ? '●' : '█') : ' ';
        [$top, $bottom] = $this->countMarginLines(explode("\n", $output), $marginChar);

        $this->assertSame($expected, $top, 'top quiet zone');
        $this->assertSame($expected, $bottom, 'bottom quiet zone');
    }

    /**
     * The visual symptom of #35: with the bottom quiet zone gone, the final
     * line of an inverted half-blocks symbol held module glyphs instead of
     * background.
     */
    public function testNoModuleGlyphsSurviveOnTheLastLineWhenInverted(): void
    {
        $output = (new AsciiRenderer(AsciiStyle::HalfBlocks))->render(
            $this->symbol(),
            new AsciiOptions(invert: true, sideMargin: 4)
        );
        $lines = explode("\n", $output);
        $lastLine = end($lines);

        $this->assertStringNotContainsString('▄', $lastLine);
        $this->assertStringNotContainsString('▀', $lastLine);
        $this->assertMatchesRegularExpression('/^█+$/u', $lastLine);
    }

    public function testSideMarginWidensTheZoneWithoutReplacingIt(): void
    {
        $symbol = $this->symbol();
        $width = static fn (string $output): int => mb_strlen(explode("\n", $output)[0]);

        $bare = (new AsciiRenderer(AsciiStyle::Blocks))->render($symbol, new AsciiOptions());
        $padded = (new AsciiRenderer(AsciiStyle::Blocks))->render($symbol, new AsciiOptions(sideMargin: 6));

        $this->assertSame($symbol->getWidth() + 8, $width($bare), 'symbology quiet zone alone');
        $this->assertSame($symbol->getWidth() + 8 + 12, $width($padded), 'plus the caller side margin');
    }

    public function testAnExplicitZeroQuietZoneIsHonoured(): void
    {
        // A caller squeezing a preview into a tight layout is entitled to drop
        // the zone; it is their call, and the renderer must not quietly
        // reinstate the symbology's minimum.
        $output = (new AsciiRenderer(AsciiStyle::Blocks))->render(
            $this->symbol(),
            new AsciiOptions(quietZone: 0)
        );
        [$top, $bottom] = $this->countMarginLines(explode("\n", $output), ' ');

        $this->assertSame(0, $top);
        $this->assertSame(0, $bottom);
    }
}
