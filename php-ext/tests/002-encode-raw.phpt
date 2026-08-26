--TEST--
encodeRaw() returns the module grid for every correction level
--EXTENSIONS--
scanmeqr
--FILE--
<?php
// The extension reads ->value off any int-backed enum, so the test does not
// need crazy-goat/scanmephp installed to exercise the encoder.
enum Ecl: int { case Low = 0; case Medium = 1; case Quartile = 2; case High = 3; }

$encoder = new CrazyGoat\ScanMePHP\NativeEncoderCore();

foreach (Ecl::cases() as $ecl) {
    $r = $encoder->encodeRaw('HELLO WORLD', $ecl);
    printf("%-8s v%-2d size=%d modules=%d\n", $ecl->name, $r['version'], $r['size'], count($r['data']));
}

// A version-1 symbol is 21x21 with a dark 7x7 finder in three corners: the
// top-left corner module and the one 6 to its right are dark, the module at
// (7,7) just outside the separator is light.
$r = $encoder->encodeRaw('HELLO WORLD', Ecl::Low);
$size = $r['size'];
$at = fn(int $x, int $y) => $r['data'][$y * $size + $x] ? '#' : '.';
echo $at(0, 0), $at(6, 0), $at(0, 6), $at(7, 7), "\n";
var_dump($r['data'][0]);
?>
--EXPECT--
Low      v1  size=21 modules=441
Medium   v1  size=21 modules=441
Quartile v1  size=21 modules=441
High     v2  size=25 modules=625
###.
bool(true)
