--TEST--
encodeRaw()/encodeMatrix() reject bad input instead of crashing
--EXTENSIONS--
scanmeqr
--FILE--
<?php
enum StringBacked: string { case Low = 'L'; }

$encoder = new CrazyGoat\ScanMePHP\NativeEncoderCore();

try {
    $encoder->encodeRaw('HELLO', StringBacked::Low);
} catch (Exception $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    $encoder->encodeRaw('HELLO', new stdClass());
} catch (Exception $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

// Without the library loaded there is no Matrix to build, and the extension has
// to say so rather than dereference a null class entry.
enum Ecl: int { case Low = 0; }
try {
    $encoder->encodeMatrix('HELLO', Ecl::Low);
} catch (Exception $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
Exception: ErrorCorrectionLevel must be an integer backed enum
Exception: ErrorCorrectionLevel must be an integer backed enum
Exception: CrazyGoat\ScanMePHP\Matrix class not found
