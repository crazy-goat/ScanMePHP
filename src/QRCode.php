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
    private EncoderInterface $encoder;

    public function __construct(string $url, ?QRCodeConfig $config = null, ?EncoderInterface $encoder = null)
    {
        $this->validateUrl($url);
        $this->url = $url;
        $this->config = $config ?? new QRCodeConfig();
        $this->encoder = $encoder ?? new Encoder();
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
            if ($this->config->size !== 0 && $this->encoder instanceof Encoder) {
                $this->matrix = $this->encoder->encode(
                    $this->url,
                    $this->config->errorCorrectionLevel,
                    $this->config->size
                );
            } else {
                $this->matrix = $this->encoder->encode(
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
