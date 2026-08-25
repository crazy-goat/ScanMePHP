<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP\Renderer;

use CrazyGoat\ScanMePHP\Matrix;
use CrazyGoat\ScanMePHP\RenderOptions;

class FullBlocksRenderer extends AbstractAsciiRenderer
{
    public function render(Matrix $matrix, RenderOptions $options): string
    {
        // Module glyphs do not change with invert (dark is always '█'); only the
        // quiet zone flips, which keeps the symbol scannable on dark terminals.
        return $this->renderRows($matrix, $options, ' ', '█', $options->invert ? '█' : ' ');
    }
}
