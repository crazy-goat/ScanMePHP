<?php

declare(strict_types=1);

use CrazyGoat\ScanMePHP\EncoderInterface;
use CrazyGoat\ScanMePHP\NativeEncoder;
use CrazyGoat\ScanMePHP\QRCode;
use PHPUnit\Framework\TestCase;

class ExtensionNameConsistencyTest extends TestCase
{
    private const EXTENSION_NAME = 'scanmeqr';

    public function testAllEncoderSelectionPathsUseTheSameExtensionName(): void
    {
        $expected = sprintf("extension_loaded('%s')", self::EXTENSION_NAME);
        $wrong = "extension_loaded('scanme_qr')";
        $files = [
            'src/QRCode.php',
            'src/NativeEncoder.php',
            'src/Composer/Plugin.php',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents(dirname(__DIR__) . '/' . $file);

            $this->assertStringContainsString(
                $expected,
                $source,
                sprintf('%s must reference the same extension name as the other encoder selection paths', $file)
            );
            $this->assertStringNotContainsString(
                $wrong,
                $source,
                sprintf('%s must not reference the misspelled extension name', $file)
            );
        }
    }

    public function testDefaultEncoderIsNotNativeEncoderWhenExtensionIsMissing(): void
    {
        if (extension_loaded(self::EXTENSION_NAME)) {
            $this->markTestSkipped('scanmeqr extension loaded; NativeEncoder is the expected default');
        }

        $method = new ReflectionMethod(QRCode::class, 'createDefaultEncoder');
        $encoder = $method->invoke(new QRCode('https://example.com'));

        $this->assertInstanceOf(EncoderInterface::class, $encoder);
        $this->assertNotInstanceOf(NativeEncoder::class, $encoder);
    }
}
