<?php

declare(strict_types=1);

use CrazyGoat\ScanMePHP\Builder;

require dirname(__DIR__) . '/src/Builder.php';

if (PHP_OS_FAMILY !== 'Windows') {
    throw new RuntimeException('This probe requires real Windows.');
}

$root = sys_get_temp_dir() . '/scanme path probe ' . bin2hex(random_bytes(6));
$bin = $root . '/tools with spaces';
mkdir($bin, 0777, true);
mkdir($root . '/clib');
$originalPath = getenv('PATH');
$cases = [
    ['cmake and g++', ['cmake', 'g++'], true, true],
    ['clang++ fallback', ['cmake', 'clang++'], true, true],
    ['missing cmake', ['g++'], true, false],
    ['missing compiler', ['cmake'], true, false],
    ['missing all tools', [], true, false],
    ['missing clib', ['cmake', 'g++'], false, false],
];

try {
    // Only the fixture tools and Windows system tools are visible. where.exe is real.
    putenv('PATH=' . $bin . ';' . getenv('SystemRoot') . '\\System32');
    foreach ($cases as [$label, $tools, $hasClib, $expected]) {
        foreach (glob($bin . '/*') as $file) {
            unlink($file);
        }
        foreach ($tools as $tool) {
            file_put_contents($bin . '/' . $tool . '.cmd', "@echo off\r\nexit /b 0\r\n");
        }
        if (!$hasClib) {
            rmdir($root . '/clib');
        }
        $actual = (new Builder($root))->isBuildAvailable();
        if ($actual !== $expected) {
            throw new RuntimeException($label . ': expected ' . var_export($expected, true)
                . ', got ' . var_export($actual, true));
        }
        echo 'PASS: ' . $label . PHP_EOL;
    }
} finally {
    putenv('PATH=' . $originalPath);
    foreach (glob($bin . '/*') as $file) {
        unlink($file);
    }
    if (is_dir($root . '/clib')) {
        rmdir($root . '/clib');
    }
    rmdir($bin);
    rmdir($root);
}
