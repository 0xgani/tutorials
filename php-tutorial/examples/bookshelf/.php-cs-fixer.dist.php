<?php

declare(strict_types=1);

// Coding style: PER Coding Style 3.0, adjusted to this project's habits, plus a few rules.
// Check with `composer cs`, fix with `composer cs:fix`.
$finder = new PhpCsFixer\Finder()
    ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/config', __DIR__ . '/public'])
    ->append([__DIR__ . '/bin/bookshelf']);

return new PhpCsFixer\Config()
    ->setRules([
        '@PER-CS3.0' => true,
        'function_declaration' => ['closure_fn_spacing' => 'one'],     // fn (int $x) rather than fn(int $x)
        'single_line_empty_body' => false,                              // ) {\n} for empty constructor bodies
        'method_argument_space' => ['on_multiline' => 'ignore'],
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
    ])
    ->setRiskyAllowed(true)          // declare_strict_types can change behavior, so it counts as "risky"
    ->setFinder($finder);
