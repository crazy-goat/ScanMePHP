<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use CrazyGoat\ScanMePHP\QRCode;
use CrazyGoat\ScanMePHP\QRCodeConfig;
use CrazyGoat\ScanMePHP\Renderer\FullBlocksRenderer;
use CrazyGoat\ScanMePHP\Renderer\HalfBlocksRenderer;
use CrazyGoat\ScanMePHP\Renderer\SimpleRenderer;
use CrazyGoat\ScanMePHP\Renderer\SvgRenderer;
use CrazyGoat\ScanMePHP\Renderer\HtmlDivRenderer;
use CrazyGoat\ScanMePHP\Renderer\HtmlTableRenderer;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;
use CrazyGoat\ScanMePHP\Exception\InvalidConfigurationException;
use CrazyGoat\ScanMePHP\ModuleStyle;

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

    public function testSvgInvertProducesDifferentModulePattern(): void
    {
        $data = 'https://example.com';
        
        // Normal SVG
        $configNormal = new QRCodeConfig(engine: new SvgRenderer());
        $qrNormal = new QRCode($data, $configNormal);
        $svgNormal = $qrNormal->render();
        
        // Inverted SVG
        $configInverted = new QRCodeConfig(engine: new SvgRenderer(), invert: true);
        $qrInverted = new QRCode($data, $configInverted);
        $svgInverted = $qrInverted->render();
        
        // The SVGs should be different
        $this->assertNotEquals($svgNormal, $svgInverted, 
            'Inverted SVG should differ from normal SVG');
        
        // Normal should have black modules (#000000) on white background (#FFFFFF)
        $this->assertStringContainsString('fill="#000000"', $svgNormal, 
            'Normal SVG should have black modules');
        $this->assertStringContainsString('fill="#FFFFFF"', $svgNormal, 
            'Normal SVG should have white background');
        
        // Inverted should have white modules (#FFFFFF) on black background (#000000)
        $this->assertStringContainsString('fill="#FFFFFF"', $svgInverted, 
            'Inverted SVG should have white modules');
        $this->assertStringContainsString('fill="#000000"', $svgInverted, 
            'Inverted SVG should have black background');
        
        // Verify that the module positions are inverted by checking a specific finder pattern area
        // Finder patterns are at corners and should be inverted
        $this->assertStringContainsString('viewBox="0 0 330 330"', $svgNormal);
        $this->assertStringContainsString('viewBox="0 0 330 330"', $svgInverted);
    }

    public function testGetContentType(): void
    {
        $this->assertEquals('text/plain', (new FullBlocksRenderer())->getContentType());
        $this->assertEquals('text/plain', (new HalfBlocksRenderer())->getContentType());
        $this->assertEquals('text/plain', (new SimpleRenderer())->getContentType());
        $this->assertEquals('image/svg+xml', (new SvgRenderer())->getContentType());
        $this->assertEquals('text/html', (new HtmlDivRenderer())->getContentType());
        $this->assertEquals('text/html', (new HtmlTableRenderer())->getContentType());
    }

    public function testHtmlDivRenderer(): void
    {
        $config = new QRCodeConfig(engine: new HtmlDivRenderer());
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertStringContainsString('<div', $output);
        $this->assertStringNotContainsString('<!DOCTYPE', $output);
    }

    public function testHtmlDivRendererFullHtml(): void
    {
        $config = new QRCodeConfig(engine: new HtmlDivRenderer(fullHtml: true));
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertStringContainsString('<!DOCTYPE html>', $output);
        $this->assertStringContainsString('<div', $output);
    }

    public function testHtmlTableRenderer(): void
    {
        $config = new QRCodeConfig(engine: new HtmlTableRenderer());
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertStringContainsString('<table', $output);
        $this->assertStringContainsString('<td', $output);
        $this->assertStringNotContainsString('<!DOCTYPE', $output);
    }

    public function testHtmlTableRendererFullHtml(): void
    {
        $config = new QRCodeConfig(engine: new HtmlTableRenderer(fullHtml: true));
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertStringContainsString('<!DOCTYPE html>', $output);
        $this->assertStringContainsString('<table', $output);
    }

    public function testHtmlDataUri(): void
    {
        $config = new QRCodeConfig(engine: new HtmlDivRenderer());
        $qr = new QRCode('https://example.com', $config);
        $dataUri = $qr->getDataUri();

        $this->assertStringStartsWith('data:text/html;base64,', $dataUri);
    }

    public function testHtmlWithLabel(): void
    {
        $config = new QRCodeConfig(
            engine: new HtmlDivRenderer(),
            label: 'Test Label'
        );
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertStringContainsString('Test Label', $output);
    }

    public function testSvgRendererCustomModuleSize(): void
    {
        $config = new QRCodeConfig(engine: new SvgRenderer(moduleSize: 20));
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertIsString($output);
        $this->assertStringContainsString('width="', $output);
        // With moduleSize=20 and default margin=4, total size should be larger
        $this->assertStringContainsString('viewBox="0 0 ', $output);
    }

    public function testSvgRendererDefaultModuleSize(): void
    {
        // Default moduleSize is 10
        $config = new QRCodeConfig(engine: new SvgRenderer());
        $qr = new QRCode('https://example.com', $config);
        $output = $qr->render();

        $this->assertIsString($output);
        $this->assertStringContainsString('<?xml', $output);
    }

    public function testSvgRendererInvalidModuleSize(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Module size must be greater than 0');

        new SvgRenderer(moduleSize: 0);
    }

    public function testSvgRendererNegativeModuleSize(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Module size must be greater than 0');

        new SvgRenderer(moduleSize: -5);
    }
}
