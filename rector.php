<?php
/**
 * Rector configuration — safe, modernizing rules only.
 *
 * Run: composer lint:rector (dry-run) / composer lint:rector-fix (apply).
 */
declare(strict_types=1);

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
        // examples/ and bench/ are linted by php-cs-fixer and PHPStan but not
        // modernised here: their job is to be read. Rector's rewrites — an
        // inline fully-qualified instanceof in place of a null check — are
        // correct and unreadable, which is the wrong trade for a file whose
        // whole purpose is to explain the API to someone who has not used it.
        __DIR__ . '/examples',
        __DIR__ . '/bench',
        __DIR__ . '/vendor',
        __DIR__ . '/php-ext',
    ]);
