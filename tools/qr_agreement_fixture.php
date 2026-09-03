<?php

declare(strict_types=1);

/**
 * Regenerate tests/fixtures/qr_agreement.csv from the verified PHP encoder.
 *
 * This is not a reference fixture and must not be mistaken for one. The
 * reference fixture is tests/fixtures/qr_reference.csv, which comes from
 * Nayuki's qrcodegen and is what says our encoder is right. This file holds
 * *our own* output, frozen, and exists for one reason: the C++ core is tested
 * from C++, where it cannot call the PHP encoder to compare against.
 *
 * So the chain is: qrcodegen verifies Encoder module for module with the mask
 * pinned; QrAgreementFixtureTest asserts this file still is Encoder's output;
 * clib/tests/test_csv_fixtures compares the C++ core against this file. The
 * PHP backends skip the middle step and compare against Encoder directly in
 * QrBackendAgreementTest.
 *
 * The mask is not a column here, deliberately. What is being checked is that
 * the C++ core reaches the same symbol as the encoder — mask included — and a
 * pinned mask is not something either it or the bitset fast path can be told.
 *
 * Run:  php tools/qr_agreement_fixture.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CrazyGoat\ScanMePHP\Encoder;
use CrazyGoat\ScanMePHP\ErrorCorrectionLevel;

$payloads = file(
    __DIR__ . '/qr_reference_payloads.txt',
    FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
);

if ($payloads === false || $payloads === []) {
    fwrite(STDERR, "no payloads in tools/qr_reference_payloads.txt\n");
    exit(1);
}

$levels = [
    'L' => ErrorCorrectionLevel::Low,
    'M' => ErrorCorrectionLevel::Medium,
    'Q' => ErrorCorrectionLevel::Quartile,
    'H' => ErrorCorrectionLevel::High,
];

$fixture = __DIR__ . '/../tests/fixtures/qr_agreement.csv';
$handle = fopen($fixture, 'w');
if ($handle === false) {
    fwrite(STDERR, sprintf("cannot write %s\n", $fixture));
    exit(1);
}

fputcsv($handle, ['url', 'ecl', 'version', 'size', 'bits'], ',', '"', '');

$rows = 0;
$encoder = new Encoder();
foreach ($payloads as $payload) {
    foreach ($levels as $name => $level) {
        $matrix = $encoder->encode($payload, $level);
        fputcsv($handle, [
            $payload,
            $name,
            $matrix->getVersion(),
            $matrix->getSize(),
            $matrix->toModuleString(),
        ], ',', '"', '');
        $rows++;
    }
}

fclose($handle);
printf("tests/fixtures/qr_agreement.csv: %d rows from %d payloads\n", $rows, \count($payloads));
