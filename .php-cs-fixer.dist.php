<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests'
    ])
    ->append([
        __DIR__ . '/controller.php'
    ])
    ->exclude([
        'vendor',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        '@PER-CS' => true,
        // Imports
        'ordered_imports' => [
            'sort_algorithm' => 'length',
        ],
        'no_unused_imports' => true,
        'group_import' => false,

        // Arrays
        'array_syntax' => [
            'syntax' => 'short',
        ],
        'trailing_comma_in_multiline' => [
            'elements' => [
                'arrays',
                'arguments',
                'parameters',
            ],
        ],

        // Strings
        'single_quote' => true,
        'escape_implicit_backslashes' => true,
        'heredoc_to_nowdoc' => true,

        // Operators
        'binary_operator_spaces' => [
            'default' => 'single_space',
        ],
        'concat_space' => [
            'spacing' => 'one',
        ],
        'unary_operator_spaces' => true,

        // Classes
        'class_definition' => [
            'single_line' => true,
        ],
        'ordered_class_elements' => [
            'order' => [
                'use_trait',
                'constant_private',
                'constant_protected',
                'constant_public',
                'property_private',
                'property_protected',
                'property_public',
                'construct',
                'destruct',
                'magic',
                'method_private',
                'method_protected',
                'method_public',
                'phpunit'
            ],
        ],

        // Functions anf Methods
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
        ],
        'return_type_declaration' => [
            'space_before' => 'none',
        ],
        'function_typehint_space' => true,

        // PHPDoc
        'phpdoc_trim' => true,
        'phpdoc_no_empty_return' => true,
        'phpdoc_scalar' => true,
        'phpdoc_types' => true,
        'phpdoc_align' => [
            'align' => 'vertical',
        ],

        // General
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => false,
        'single_blank_line_at_eof' => true,

        // Modern PHP
        'nullable_type_declaration_for_default_null_value' => true,
        'no_unneeded_control_parentheses' => true,
        'no_unneeded_curly_braces' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,

        // Code Quality
        'no_superfluous_elseif' => true,
        'no_useless_concat_operator' => true,
        'echo_tag_syntax' => [
            'format' => 'long'
        ]
    ])
    ->setFinder($finder);
