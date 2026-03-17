<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

use CrazyGoat\ScanMePHP\Encoding\Mode;
use CrazyGoat\ScanMePHP\Exception\FileWriteException;
use CrazyGoat\ScanMePHP\Exception\InvalidDataException;
use CrazyGoat\ScanMePHP\Exception\RenderException;

class QRCode
{
    private string $url;
    private QRCodeConfig $config;
    private ?Matrix $matrix = null;
    private EncoderInterface $encoder;

    public function __construct(string $url, ?QRCodeConfig $config = null, ?EncoderInterface $encoder = null)
    {
        $this->validateUrl($url);
        $this->url = $url;
        $this->config = $config ?? new QRCodeConfig();
        $this->encoder = $encoder ?? self::createDefaultEncoder();
    }

    private static function createDefaultEncoder(): EncoderInterface
    {
        // Try NativeEncoder (PHP extension) first - fastest option
        if (extension_loaded('scanme_qr') && class_exists('CrazyGoat\\ScanMePHP\\NativeEncoder')) {
            return new NativeEncoder();
        }

        // Try FFI encoder with downloaded binary first (vendor location)
        $vendorBinary = dirname(__DIR__) . '/../../crazy-goat/scanmephp/ffi-binaries/' . PlatformDetector::getCurrentPlatformBinaryName();
        if (FfiEncoder::isAvailable($vendorBinary)) {
            return new FfiEncoder($vendorBinary);
        }

        // Fallback to local build
        $localBuild = dirname(__DIR__) . '/clib/build/libscanme_qr.so';
        if (FfiEncoder::isAvailable($localBuild)) {
            return new FfiEncoder($localBuild);
        }

        // Use FastEncoder on 64-bit PHP
        if (\PHP_INT_SIZE >= 8) {
            return new FastEncoder();
        }

        // Final fallback to portable Encoder
        return new Encoder();
    }

    private function validateUrl(string $url): void
    {
        if (empty($url)) {
            throw InvalidDataException::emptyData();
        }

        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw InvalidDataException::invalidUrl($url);
        }
    }

    private function ensureMatrix(): Matrix
    {
        if ($this->matrix === null) {
            $encoder = $this->encoder;

            // If a specific version is requested and we're using FastEncoder,
            // fall back to Encoder which supports the $requestedVersion parameter
            if ($this->config->size !== 0 && $encoder instanceof FastEncoder) {
                $encoder = new Encoder();
            }

            if ($this->config->size !== 0 && $encoder instanceof Encoder) {
                $this->matrix = $encoder->encode(
                    $this->url,
                    $this->config->errorCorrectionLevel,
                    $this->config->size
                );
            } else {
                $this->matrix = $encoder->encode(
                    $this->url,
                    $this->config->errorCorrectionLevel,
                );
            }
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
