<?php

declare(strict_types=1);

/**
 * Every symbology this library ships, and what each one accepts.
 *
 * The list is not hardcoded here — it comes from the registry, so this file
 * stays correct as symbologies are added. That is also how you would build a
 * format picker in an application.
 *
 * Run: php examples/02_symbologies.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\Renderer\Options\AsciiOptions;
use CrazyGoat\ScanMePHP\Scanme;

$scanme = Scanme::create();

echo "=== What is installed ===\n\n";

foreach ($scanme->getRegistry()->describeGenerators() as $name => $capabilities) {
    printf("%-12s %s\n", $name, $capabilities->title);
    printf("%-12s accepts: %s\n", '', $capabilities->dataDescription);
    printf("%-12s %s, %s modules\n", '', $capabilities->dimension->value, $capabilities->moduleShape->value);

    if ($capabilities->aliases !== []) {
        printf("%-12s also known as: %s\n", '', implode(', ', $capabilities->aliases));
    }

    if ($capabilities->hasErrorCorrection()) {
        printf("%-12s error correction: %s\n", '', implode(', ', $capabilities->errorCorrectionLevels));
    }

    if ($capabilities->providesText) {
        printf("%-12s carries human-readable text a renderer should print\n", '');
    }

    if ($capabilities->optionsClass !== null) {
        printf("%-12s options: %s\n", '', $capabilities->optionsClass);
    }

    echo "\n";
}

echo "=== One payload each ===\n\n";

// A representative payload per symbology. The retail family is numeric with a
// check digit; QR, Code 128 and Data Matrix take text.
$payloads = [
    'qrcode' => 'https://scanmephp.dev',
    'code128' => 'SCANME-2026',
    'ean13' => '5901234123457',
    'ean8' => '96385074',
    'upc-a' => '036000291452',
    'upc-e' => '04252614',
    'data-matrix' => 'ScanMePHP',
];

foreach ($payloads as $symbology => $data) {
    $symbol = $scanme->generate($data, $symbology);

    printf(
        "%-12s %-22s %d x %d modules%s\n",
        $symbology,
        $data,
        $symbol->getWidth(),
        $symbol->getHeight(),
        $symbol->getText() === null ? '' : ', text "' . $symbol->getText() . '"'
    );
}

echo "\n=== Asking rather than guessing ===\n\n";

// A caller holding a number does not have to know which retail symbology it
// belongs to: the registry can be asked which generators accept it.
foreach (['5901234123457', '036000291452', 'https://example.com'] as $data) {
    printf("%-22s encodable as: %s\n", $data, implode(', ', $scanme->getRegistry()->generatorsFor($data)));
}

echo "\n=== UPC-A is an EAN-13 with a leading zero ===\n\n";

// Not an approximation — the same bars, bit for bit. The symbology a scanner
// reports for these modules depends only on what it was asked to look for.
$upcA = $scanme->generate('036000291452', 'upc-a');
$ean13 = $scanme->generate('0036000291452', 'ean13');

printf("upc-a  036000291452  %s\n", substr($upcA->toModuleString(), 0, $upcA->getWidth()));
printf("ean13 0036000291452  %s\n", substr($ean13->toModuleString(), 0, $ean13->getWidth()));
printf(
    "identical: %s\n",
    substr($upcA->toModuleString(), 0, $upcA->getWidth())
    === substr($ean13->toModuleString(), 0, $ean13->getWidth()) ? 'yes' : 'no'
);

echo "\n=== And UPC-E is the same article number, compressed ===\n\n";

$upcE = $scanme->generate('04252614', 'upc-e');
printf("drawn digits: %s\n", $upcE->getText());
printf("stands for:   %s\n", $upcE->getMetadataValue('upca'));
echo $scanme->renderSymbol($upcE, 'ascii-half-blocks', new AsciiOptions(barHeight: 10, sideMargin: 2));

echo "\nDone.\n";
