<?php

declare(strict_types=1);

/**
 * Every output format, and what each is good for.
 *
 * The renderer is chosen by name at call time, so the same symbol can go to a
 * web page, a printer and a terminal without re-encoding it. Files land in
 * examples/generated-assets/, which is regenerated rather than committed.
 *
 * Run: php examples/03_output_formats.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\Renderer\Options\AsciiOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\HtmlOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\PngOptions;
use CrazyGoat\ScanMePHP\Renderer\Options\SvgOptions;
use CrazyGoat\ScanMePHP\Scanme;

$scanme = Scanme::create();
$assets = __DIR__ . '/generated-assets';

if (!is_dir($assets)) {
    mkdir($assets, 0o775, true);
}

$data = 'https://github.com/crazy-goat/ScanMePHP';

echo "=== What is installed ===\n\n";

foreach ($scanme->getRegistry()->renderers() as $renderer) {
    $capabilities = $renderer->getCapabilities();

    printf("%-18s %s\n", $renderer->getFormat(), $renderer->getContentType());
    printf(
        "%-18s shapes: %s | text: %s | colour: %s\n",
        '',
        implode(', ', array_map(static fn ($shape): string => $shape->value, $capabilities->moduleShapes)),
        $capabilities->text ? ($capabilities->textCharacters === null ? 'any' : 'fixed repertoire') : 'no',
        $capabilities->color ? 'yes' : 'no'
    );
    printf("%-18s options: %s\n\n", '', $capabilities->optionsClass ?? 'none');
}

echo "=== Files ===\n\n";

// One encode, many outputs: generate() gives the modules, renderSymbol() draws
// them. For a single output the one-shot render() is shorter.
$symbol = $scanme->generate($data, 'qrcode');

$outputs = [
    'qrcode.svg' => ['svg', new SvgOptions(moduleSize: 8)],
    'qrcode.png' => ['png', new PngOptions(moduleSize: 8)],
    'qrcode-div.html' => ['html-div', new HtmlOptions(moduleSize: 6, fullDocument: true, title: 'ScanMePHP')],
    'qrcode-table.html' => ['html-table', new HtmlOptions(moduleSize: 6, fullDocument: true, title: 'ScanMePHP')],
    'qrcode-blocks.txt' => ['ascii-blocks', new AsciiOptions(sideMargin: 2)],
    'qrcode-half-blocks.txt' => ['ascii-half-blocks', new AsciiOptions(sideMargin: 2)],
    'qrcode-dots.txt' => ['ascii-dots', new AsciiOptions(sideMargin: 2)],
];

foreach ($outputs as $filename => [$format, $options]) {
    $content = $scanme->renderSymbol($symbol, $format, $options);
    file_put_contents($assets . '/' . $filename, $content);
    printf("%-24s %-18s %6d bytes\n", $filename, $format, \strlen($content));
}

echo "\n=== The three terminal styles ===\n\n";

// Half-blocks pack two module rows into one character cell, which is why a QR
// code fits in a normal terminal window. Blocks and dots trade that for
// legibility in fonts that render half-blocks badly.
foreach (['ascii-blocks', 'ascii-half-blocks', 'ascii-dots'] as $format) {
    echo "-- {$format}\n";
    echo $scanme->render('SCANME', 'code128', $format, new AsciiOptions(barHeight: 4, sideMargin: 2));
    echo "\n";
}

echo "Done.\n";
