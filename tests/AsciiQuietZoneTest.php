<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use CrazyGoat\ScanMePHP\QRCode;
use CrazyGoat\ScanMePHP\QRCodeConfig;
use CrazyGoat\ScanMePHP\Renderer\FullBlocksRenderer;
use CrazyGoat\ScanMePHP\Renderer\HalfBlocksRenderer;

/**
 * Test for GitHub Issue #35: ASCII QR codes missing quiet zone in inverted mode
 * 
 * When generating ASCII QR codes with invert=true, the output should have
 * symmetric quiet zone (margin) at both top and bottom. The bottom margin
 * was missing, causing a white line artifact in inverted mode.
 */
class AsciiQuietZoneTest extends TestCase
{
    private const TEST_URL = 'https://qrcode.crazy-goat.com';
    private const MARGIN_SIZE = 4;

    /**
     * Count margin lines at top and bottom
     * 
     * @param string[] $lines
     * @param bool $inverted
     * @param string $marginChar Character used for margin (█ for inverted, ' ' for normal)
     * @return array [topCount, bottomCount]
     */
    private function countMarginLines(array $lines, bool $inverted, string $marginChar): array
    {
        $top = 0;
        foreach ($lines as $line) {
            if ($line !== '' && !preg_match('/[^' . preg_quote($marginChar, '/') . ']/', $line)) {
                $top++;
            } else {
                break;
            }
        }

        $bottom = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if ($lines[$i] !== '' && !preg_match('/[^' . preg_quote($marginChar, '/') . ']/', $lines[$i])) {
                $bottom++;
            } else {
                break;
            }
        }

        return [$top, $bottom];
    }

    /**
     * Test that HalfBlocksRenderer has symmetric margins in normal mode
     */
    public function testHalfBlocksRendererHasSymmetricMargins(): void
    {
        $config = new QRCodeConfig(
            engine: new HalfBlocksRenderer(sideMargin: self::MARGIN_SIZE),
            margin: self::MARGIN_SIZE,
        );
        $qr = new QRCode(self::TEST_URL, $config);
        $output = $qr->render();
        $lines = explode("\n", $output);

        [$top, $bottom] = $this->countMarginLines($lines, false, ' ');

        $this->assertEquals(
            self::MARGIN_SIZE,
            $top,
            "Top margin should be " . self::MARGIN_SIZE . " lines, got $top"
        );
        $this->assertEquals(
            self::MARGIN_SIZE,
            $bottom,
            "Bottom margin should be " . self::MARGIN_SIZE . " lines, got $bottom"
        );
    }

    /**
     * Test that HalfBlocksRenderer has symmetric margins in inverted mode
     * This is the main bug from issue #35
     */
    public function testHalfBlocksRendererInvertedHasSymmetricMargins(): void
    {
        $config = new QRCodeConfig(
            engine: new HalfBlocksRenderer(sideMargin: self::MARGIN_SIZE),
            margin: self::MARGIN_SIZE,
            invert: true,
        );
        $qr = new QRCode(self::TEST_URL, $config);
        $output = $qr->render();
        $lines = explode("\n", $output);

        // In inverted mode, margin lines are filled with '█' character
        [$top, $bottom] = $this->countMarginLines($lines, true, '█');

        $this->assertEquals(
            self::MARGIN_SIZE,
            $top,
            "Top margin should be " . self::MARGIN_SIZE . " lines in inverted mode, got $top"
        );
        $this->assertEquals(
            self::MARGIN_SIZE,
            $bottom,
            "Bottom margin should be " . self::MARGIN_SIZE . " lines in inverted mode, got $bottom"
        );
    }

    /**
     * Test that FullBlocksRenderer has symmetric margins in normal mode
     */
    public function testFullBlocksRendererHasSymmetricMargins(): void
    {
        $config = new QRCodeConfig(
            engine: new FullBlocksRenderer(sideMargin: self::MARGIN_SIZE),
            margin: self::MARGIN_SIZE,
        );
        $qr = new QRCode(self::TEST_URL, $config);
        $output = $qr->render();
        $lines = explode("\n", $output);

        [$top, $bottom] = $this->countMarginLines($lines, false, ' ');

        $this->assertEquals(
            self::MARGIN_SIZE,
            $top,
            "Top margin should be " . self::MARGIN_SIZE . " lines, got $top"
        );
        $this->assertEquals(
            self::MARGIN_SIZE,
            $bottom,
            "Bottom margin should be " . self::MARGIN_SIZE . " lines, got $bottom"
        );
    }

    /**
     * Test that FullBlocksRenderer has symmetric margins in inverted mode
     */
    public function testFullBlocksRendererInvertedHasSymmetricMargins(): void
    {
        $config = new QRCodeConfig(
            engine: new FullBlocksRenderer(sideMargin: self::MARGIN_SIZE),
            margin: self::MARGIN_SIZE,
            invert: true,
        );
        $qr = new QRCode(self::TEST_URL, $config);
        $output = $qr->render();
        $lines = explode("\n", $output);

        // In inverted mode, margin lines are filled with '█' character
        [$top, $bottom] = $this->countMarginLines($lines, true, '█');

        $this->assertEquals(
            self::MARGIN_SIZE,
            $top,
            "Top margin should be " . self::MARGIN_SIZE . " lines in inverted mode, got $top"
        );
        $this->assertEquals(
            self::MARGIN_SIZE,
            $bottom,
            "Bottom margin should be " . self::MARGIN_SIZE . " lines in inverted mode, got $bottom"
        );
    }

    /**
     * Test that the last line of QR code is not visible as white line in inverted mode
     * This is the visual bug reported in issue #35
     */
    public function testNoWhiteLineAtBottomInInvertedMode(): void
    {
        $config = new QRCodeConfig(
            engine: new HalfBlocksRenderer(sideMargin: self::MARGIN_SIZE),
            margin: self::MARGIN_SIZE,
            invert: true,
        );
        $qr = new QRCode(self::TEST_URL, $config);
        $output = $qr->render();
        $lines = explode("\n", $output);

        // Get the last line
        $lastLine = $lines[count($lines) - 1];

        // In inverted mode with proper margins, last line should be all '█' (black)
        // If there's no bottom margin, last line will contain '▄' characters (white modules)
        $this->assertStringNotContainsString(
            '▄',
            $lastLine,
            "Last line should not contain '▄' characters (white modules) - indicates missing bottom margin"
        );

        // Last line should be all black (filled with '█')
        $this->assertMatchesRegularExpression(
            '/^█+$/u',
            $lastLine,
            "Last line should be all black margin (█ characters only)"
        );
    }
}
