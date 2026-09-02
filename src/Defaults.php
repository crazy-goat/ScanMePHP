<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

use CrazyGoat\ScanMePHP\Generator\Code128\Code128Generator;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\DataMatrixGenerator;
use CrazyGoat\ScanMePHP\Generator\Ean13\Ean13Generator;
use CrazyGoat\ScanMePHP\Generator\Qr\QrGenerator;
use CrazyGoat\ScanMePHP\Renderer\AsciiRenderer;
use CrazyGoat\ScanMePHP\Renderer\AsciiStyle;
use CrazyGoat\ScanMePHP\Renderer\HtmlMode;
use CrazyGoat\ScanMePHP\Renderer\HtmlRenderer;
use CrazyGoat\ScanMePHP\Renderer\PngRenderer;
use CrazyGoat\ScanMePHP\Renderer\SvgRenderer;

/**
 * The symbologies and output formats registered out of the box.
 *
 * Building on top of this rather than replacing it is the normal path: take
 * the registry, add your own generator or renderer, hand it to Scanme. Nothing
 * here is privileged — a registration under an existing name replaces it.
 */
final class Defaults
{
    public static function registry(): Registry
    {
        return (new Registry())
            ->addGenerator(new QrGenerator())
            ->addGenerator(new Code128Generator())
            ->addGenerator(new Ean13Generator())
            ->addGenerator(new DataMatrixGenerator())
            ->addRenderer(new SvgRenderer())
            ->addRenderer(new PngRenderer())
            ->addRenderer(new HtmlRenderer(HtmlMode::Div))
            ->addRenderer(new HtmlRenderer(HtmlMode::Table))
            ->addRenderer(new AsciiRenderer(AsciiStyle::Blocks))
            ->addRenderer(new AsciiRenderer(AsciiStyle::HalfBlocks))
            ->addRenderer(new AsciiRenderer(AsciiStyle::Dots));
    }
}
