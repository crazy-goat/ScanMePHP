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

use CrazyGoat\ScanMePHP\Format;
use CrazyGoat\ScanMePHP\Generator\Code39\Code39Options;
use CrazyGoat\ScanMePHP\QuietZone;
use CrazyGoat\ScanMePHP\Renderer\Options\AsciiOptions;
use CrazyGoat\ScanMePHP\Scanme;
use CrazyGoat\ScanMePHP\Symbol;
use CrazyGoat\ScanMePHP\Symbology;

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
    'code39' => 'PART-4471',
    'code39ext' => 'Part 4471/a',
    'ean13' => '5901234123457',
    'ean8' => '96385074',
    'upc-a' => '036000291452',
    'upc-e' => '04252614',
    'ean2' => '52',
    'ean5' => '51299',
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

echo "\n=== Code 39 is one set of bars with two readings ===\n\n";

// Standard Code 39 carries 43 characters. Extended mode reaches the rest of
// ASCII by spending two characters on each of the other 85 bytes, so the same
// payload is wider — and a payload the standard set cannot hold at all becomes
// encodable. Nothing in the printed pattern says which reading is meant; that
// is the scanner's configuration, which is why these are two symbologies.
foreach (['PART-4471', 'Part 4471/a'] as $payload) {
    foreach ([Symbology::Code39, Symbology::Code39Extended] as $symbology) {
        $name = $symbology->value;

        if (!$scanme->getRegistry()->getGenerator($name)->canEncode($payload)) {
            printf("%-10s %-14s not encodable\n", $name, $payload);

            continue;
        }

        $symbol = $scanme->generate($payload, $symbology);
        printf(
            "%-10s %-14s %3d modules, drawn as %s\n",
            $name,
            $payload,
            $symbol->getWidth(),
            $symbol->getMetadataValue('characters')
        );
    }
}

// The check character is optional in the standard and off by default: most
// scanners do not verify it and report it as a trailing character instead.
$checked = $scanme->generate('PART-4471', Symbology::Code39, new Code39Options(checkCharacter: true));
printf(
    "\nwith a check character: %s drawn as %s, printed as \"%s\"\n\n",
    $checked->getMetadataValue('checkCharacter'),
    $checked->getMetadataValue('characters'),
    $checked->getText()
);
echo $scanme->renderSymbol($checked, Format::AsciiHalfBlocks, new AsciiOptions(barHeight: 10, sideMargin: 2));

echo "\n=== Add-ons go beside a main symbol, not instead of one ===\n\n";

// An EAN-2 or EAN-5 is a fragment: the issue number of a magazine, the price
// of a book. On its own it is a valid symbol that most scanners will decline
// to report, because on its own it does not identify anything.
//
// Composing one next to an EAN-13 is not done for you yet — a proper job needs
// shorter add-on bars and its own line of digits above them, which Symbol
// cannot express today. Assembling the modules by hand is three lines, and
// what a scanner reads back is "9788375780642" + "51299":
$main = $scanme->generate('9788375780642', Symbology::Ean13);
$addOn = $scanme->generate('51299', Symbology::Ean5);

$composite = Symbol::linear(
    // Row 0 of the EAN-13 is its bars; row 1 carries only the guard
    // descenders. ISO/IEC 15420 asks for at least seven modules of separation,
    // and the add-on's own guard opens with a space, so this leaves eight.
    modules: substr($main->toModuleString(), 0, $main->getWidth())
        . str_repeat('0', 7)
        . $addOn->toModuleString(),
    quietZone: new QuietZone(left: 11, right: 5),
    barHeight: 20,
    text: $main->getText() . ' ' . $addOn->getText(),
);

printf("%s + %s, %d modules wide\n\n", $main->getText(), $addOn->getText(), $composite->getWidth());
echo $scanme->renderSymbol($composite, Format::AsciiHalfBlocks, new AsciiOptions(sideMargin: 2));

echo "\nDone.\n";
