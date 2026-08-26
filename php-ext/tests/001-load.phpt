--TEST--
scanmeqr loads and registers NativeEncoderCore
--EXTENSIONS--
scanmeqr
--FILE--
<?php
var_dump(extension_loaded('scanmeqr'));
$rc = new ReflectionClass('CrazyGoat\ScanMePHP\NativeEncoderCore');
var_dump($rc->isInternal());
$methods = array_map(fn($m) => $m->getName(), $rc->getMethods());
sort($methods);
print_r($methods);
?>
--EXPECT--
bool(true)
bool(true)
Array
(
    [0] => encodeMatrix
    [1] => encodeRaw
)
