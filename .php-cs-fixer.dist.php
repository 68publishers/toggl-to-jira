<?php

$finder = PhpCsFixer\Finder::create()
	->in(__DIR__ . '/src')
;

return (new PhpCsFixer\Config)
	->setUsingCache(FALSE)
	->setIndent("\t")
	->setRules([
		'@PSR2' => TRUE,
		'array_syntax' => ['syntax' => 'short'],
		'trailing_comma_in_multiline' => TRUE,
		'no_trailing_comma_in_singleline_array' => TRUE,
		'no_whitespace_before_comma_in_array' => TRUE,
		'constant_case' => [
			'case' => 'upper',
		],
		'declare_strict_types' => TRUE,
		'phpdoc_align' => TRUE,
		'blank_line_after_opening_tag' => TRUE,
		'blank_line_before_statement' => [
			'statements' => ['break', 'continue', 'declare', 'return'],
		],
		'blank_line_after_namespace' => TRUE,
		'single_blank_line_before_namespace' => TRUE,
		'return_type_declaration' => [
			'space_before' => 'none',
		],
		'ordered_imports' => [
			'sort_algorithm' => 'length',
		],
		'no_unused_imports' => TRUE,
		'single_line_after_imports' => TRUE,
		'no_leading_import_slash' => TRUE,
	])
	->setRiskyAllowed(TRUE)
	->setFinder($finder)
;
