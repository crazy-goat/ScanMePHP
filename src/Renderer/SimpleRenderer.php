<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Matrix;
use CrazyGoat\ScanMePHP\RenderOptions;

class SimpleRenderer extends AbstractAsciiRenderer
{
    public function render(Matrix $matrix, RenderOptions $options): string
    {
        return $this->renderRows($matrix, $options, ' ', '●', $options->invert ? '●' : ' ');
    }
}
