<?php
// Guard: if php-cs-fixer classes are not available, print a helpful message and return null to avoid fatal errors.
if (!class_exists('PhpCsFixer\\Finder') || !class_exists('PhpCsFixer\\Config')) {
    // When running on an environment without php-cs-fixer installed, avoid fatal errors.
    // Install friendsofphp/php-cs-fixer via composer to enable formatting: composer require --dev friendsofphp/php-cs-fixer
    fwrite(STDERR, "PHP CS Fixer classes not found. Install friendsofphp/php-cs-fixer to enable formatting.\n");
    return null;
}

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/../../')
    ->exclude(['vendor', 'node_modules', 'storage', 'cache', 'dist', 'build'])
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'blank_line_before_statement' => ['statements' => ['return']],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_trailing_whitespace' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'single_quote' => true
    ])
    ->setFinder($finder);
