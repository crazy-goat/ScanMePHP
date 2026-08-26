--TEST--
encodeMatrix() hands the modules to CrazyGoat\ScanMePHP\Matrix::fromModuleString()
--EXTENSIONS--
scanmeqr
--FILE--
<?php
namespace CrazyGoat\ScanMePHP;

enum Ecl: int { case Low = 0; }

// Stands in for the real Matrix; the extension looks the class up by name and
// calls this one static factory, which is the whole contract between them.
class Matrix
{
    private function __construct(public readonly int $version, public readonly string $modules) {}

    public static function fromModuleString(int $version, string $modules): self
    {
        return new self($version, $modules);
    }
}

$m = (new NativeEncoderCore())->encodeMatrix('HELLO WORLD', Ecl::Low);

var_dump($m instanceof Matrix);
var_dump($m->version);
var_dump(strlen($m->modules));
var_dump(strspn($m->modules, '01') === strlen($m->modules));
echo substr($m->modules, 0, 7), "\n";
?>
--EXPECT--
bool(true)
int(1)
int(441)
bool(true)
1111111
