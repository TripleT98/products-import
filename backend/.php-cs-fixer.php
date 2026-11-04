<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/public', __DIR__ . '/bin', __DIR__ . '/tests', __DIR__ . '/migrations'])
    ->append([__FILE__, __DIR__ . '/bin/console']);

return (new PhpCsFixer\Config())
    ->setRules(['@PSR12' => true, 'no_unused_imports' => true])
    ->setFinder($finder);
