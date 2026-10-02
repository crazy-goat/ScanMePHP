<?php
/**
 * Rector configuration — safe, modernizing rules only.
 *
 * Run: bin/lint.sh (check) / bin/lint.sh --fix (apply).
 */
declare(strict_types=1);

use Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withSets([
        SetList::PHP_81,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
    ])
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/bin',
    ])
    ->withSkip([
        __DIR__ . '/clib',
        __DIR__ . '/vendor',
        __DIR__ . '/php-ext',
        // FfiEncoder::localBuildPath() returns this path to callers; the rewrite would change
        // the returned string (it would keep a "/src/.." segment), so it is not neutral.
        DirnameDirConcatStringToDirectStringPathRector::class,
    ]);
