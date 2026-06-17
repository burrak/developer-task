<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/migrations',
    ])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRiskyAllowed(false)
    ->setRules([
        'no_unused_imports' => true,
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_blank_line_at_eof' => true,
    ])
    ->setFinder($finder);
