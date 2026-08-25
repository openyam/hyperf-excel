<?php

$finder = PhpCsFixer\Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules(['@PER-CS2.0' => true, 'declare_strict_types' => true, 'ordered_imports' => true])
    ->setFinder($finder);
