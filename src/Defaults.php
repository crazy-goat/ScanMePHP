<?php

declare(strict_types=1);

namespace CrazyGoat\ScanMePHP;

use CrazyGoat\ScanMePHP\Generator\Aztec\AztecGenerator;
use CrazyGoat\ScanMePHP\Generator\Codabar\CodabarGenerator;
use CrazyGoat\ScanMePHP\Generator\Code128\Code128Generator;
use CrazyGoat\ScanMePHP\Generator\Code39\Code39Generator;
use CrazyGoat\ScanMePHP\Generator\Code39\Mode as Code39Mode;
use CrazyGoat\ScanMePHP\Generator\Code93\Code93Generator;
use CrazyGoat\ScanMePHP\Generator\DataBarExpanded\DataBarExpandedGenerator;
use CrazyGoat\ScanMePHP\Generator\DataBarExpandedStacked\DataBarExpandedStackedGenerator;
use CrazyGoat\ScanMePHP\Generator\DataBarLimited\DataBarLimitedGenerator;
use CrazyGoat\ScanMePHP\Generator\DataBarOmni\DataBarOmniGenerator;
use CrazyGoat\ScanMePHP\Generator\DataMatrix\DataMatrixGenerator;
use CrazyGoat\ScanMePHP\Generator\Ean13\Ean13Generator;
use CrazyGoat\ScanMePHP\Generator\Ean2\Ean2Generator;
use CrazyGoat\ScanMePHP\Generator\Ean5\Ean5Generator;
use CrazyGoat\ScanMePHP\Generator\Ean8\Ean8Generator;
use CrazyGoat\ScanMePHP\Generator\Gs1128\Gs1128Generator;
use CrazyGoat\ScanMePHP\Generator\Gs1DataMatrix\Gs1DataMatrixGenerator;
use CrazyGoat\ScanMePHP\Generator\Gs1Qr\Gs1QrGenerator;
use CrazyGoat\ScanMePHP\Generator\Itf\ItfGenerator;
use CrazyGoat\ScanMePHP\Generator\Itf14\Itf14Generator;
use CrazyGoat\ScanMePHP\Generator\MaxiCode\MaxiCodeGenerator;
use CrazyGoat\ScanMePHP\Generator\Pdf417\Pdf417Generator;
use CrazyGoat\ScanMePHP\Generator\Qr\QrGenerator;
use CrazyGoat\ScanMePHP\Generator\UpcA\UpcAGenerator;
use CrazyGoat\ScanMePHP\Generator\UpcE\UpcEGenerator;
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
            ->addGenerator(new Gs1QrGenerator())
            ->addGenerator(new Code128Generator())
            ->addGenerator(new Gs1128Generator())
            // One class, two registry entries: the reading mode is part of
            // the symbology rather than an option. See Code39\Mode.
            ->addGenerator(new Code39Generator(Code39Mode::Standard))
            ->addGenerator(new Code39Generator(Code39Mode::Extended))
            ->addGenerator(new Code93Generator())
            ->addGenerator(new CodabarGenerator())
            ->addGenerator(new Ean13Generator())
            ->addGenerator(new Ean8Generator())
            ->addGenerator(new UpcAGenerator())
            ->addGenerator(new UpcEGenerator())
            ->addGenerator(new Ean2Generator())
            ->addGenerator(new Ean5Generator())
            ->addGenerator(new ItfGenerator())
            ->addGenerator(new Itf14Generator())
            ->addGenerator(new DataMatrixGenerator())
            ->addGenerator(new Gs1DataMatrixGenerator())
            ->addGenerator(new AztecGenerator())
            ->addGenerator(new Pdf417Generator())
            ->addGenerator(new MaxiCodeGenerator())
            ->addGenerator(new DataBarOmniGenerator())
            ->addGenerator(new DataBarLimitedGenerator())
            ->addGenerator(new DataBarExpandedGenerator())
            ->addGenerator(new DataBarExpandedStackedGenerator())
            ->addRenderer(new SvgRenderer())
            ->addRenderer(new PngRenderer())
            ->addRenderer(new HtmlRenderer(HtmlMode::Div))
            ->addRenderer(new HtmlRenderer(HtmlMode::Table))
            ->addRenderer(new AsciiRenderer(AsciiStyle::Blocks))
            ->addRenderer(new AsciiRenderer(AsciiStyle::HalfBlocks))
            ->addRenderer(new AsciiRenderer(AsciiStyle::Dots));
    }
}
