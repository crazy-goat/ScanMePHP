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
 * symmetric quiet zone (margin) at both top and bottom. Currently, the bottom
 * margin is missing, causing a white line artifact in inverted mode.
 */
class AsciiQuietZoneTest extends TestCase
{
    private const TEST_URL = 'https://qrcode.crazy-goat.com';
    private const MARGIN_SIZE = 4;

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

        // Count empty lines at top (should be MARGIN_SIZE)
        $topEmptyLines = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                $topEmptyLines++;
            } else {
                break;
            }
        }

        // Count empty lines at bottom (should be MARGIN_SIZE)
        $bottomEmptyLines = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (trim($lines[$i]) === '') {
                $bottomEmptyLines++;
            } else {
                break;
            }
        }

        $this->assertEquals(
            self::MARGIN_SIZE,
            $topEmptyLines,
            "Top margin should be " . self::MARGIN_SIZE . " lines, got $topEmptyLines"
        );
        $this->assertEquals(
            self::MARGIN_SIZE,
            $bottomEmptyLines,
            "Bottom margin should be " . self::MARGIN_SIZE . " lines, got $bottomEmptyLines"
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
        $expectedMarginLine = str_repeat('█', self::MARGIN_SIZE * 2 + 21); // sideMargin*2 + QR size

        // Count margin lines at top
        $topMarginLines = 0;
        foreach ($lines as $line) {
            if ($line === $expectedMarginLine) {
                $topMarginLines++;
            } else {
                break;
            }
        }

        // Count margin lines at bottom
        $bottomMarginLines = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if ($lines[$i] === $expectedMarginLine) {
                $bottomMarginLines++;
            } else {
                break;
            }
        }

        $this->assertEquals(
            self::MARGIN_SIZE,
            $topMarginLines,
            "Top margin should be " . self::MARGIN_SIZE . " lines in inverted mode, got $topMarginLines"
        );
        $this->assertEquals(
            self::MARGIN_SIZE,
            $bottomMarginLines,
            "Bottom margin should be " . self::MARGIN_SIZE . " lines in inverted mode, got $bottomMarginLines"
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

        // Count empty lines at top
        $topEmptyLines = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                $topEmptyLines++;
            } else {
                break;
            }
        }

        // Count empty lines at bottom
        $bottomEmptyLines = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (trim($lines[$i]) === '') {
                $bottomEmptyLines++;
            } else {
                break;
            }
        }

        $this->assertEquals(
            self::MARGIN_SIZE,
            $topEmptyLines,
            "Top margin should be " . self::MARGIN_SIZE . " lines, got $topEmptyLines"
        );
        $this->assertEquals(
            self::MARGIN_SIZE,
            $bottomEmptyLines,
            "Bottom margin should be " . self::MARGIN_SIZE . " lines, got $bottomEmptyLines"
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
        $expectedMarginLine = str_repeat('█', self::MARGIN_SIZE * 2 + 21); // sideMargin*2 + QR size

        // Count margin lines at top
        $topMarginLines = 0;
        foreach ($lines as $line) {
            if ($line === $expectedMarginLine) {
                $topMarginLines++;
            } else {
                break;
            }
        }

        // Count margin lines at bottom
        $bottomMarginLines = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if ($lines[$i] === $expectedMarginLine) {
                $bottomMarginLines++;
            } else {
                break;
            }
        }

        $this->assertEquals(
            self::MARGIN_SIZE,
            $topMarginLines,
            "Top margin should be " . self::MARGIN_SIZE . " lines in inverted mode, got $topMarginLines"
        );
        $this->assertEquals(
            self::MARGIN_SIZE,
            $bottomMarginLines,
            "Bottom margin should be " . self::MARGIN_SIZE . " lines in inverted mode, got $bottomMarginLines"
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
            '/^█+$/',
            $lastLine,
            "Last line should be all black margin (█ characters only)"
        );
    }
}
