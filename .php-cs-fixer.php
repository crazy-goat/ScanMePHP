<?php
/**
 * php-cs-fixer configuration — PSR-12 plus a few project conventions.
 *
 * Run: composer lint:cs (dry-run) / composer lint:cs-fix (apply).
 */
declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->in(__DIR__ . '/bin')
    ->in(__DIR__ . '/examples')
    ->in(__DIR__ . '/bench')
    ->notPath('ffi/');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder);
