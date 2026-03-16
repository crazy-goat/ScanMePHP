<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

interface RendererInterface
{
    public function getContentType(): string;

    public function render(Matrix $matrix, RenderOptions $options): string;
}
