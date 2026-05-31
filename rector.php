<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector;
use Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
	->withPaths([
		__DIR__ . '/src',
		__DIR__ . '/config',
		__DIR__ . '/tests',
	])
	->withSkip([
		// Directories that don't need refactoring.
		__DIR__ . '/vendor',
		__DIR__ . '/build',
		__DIR__ . '/resources',
		__DIR__ . '/tcms-data',
		__DIR__ . '/node_modules',
		__DIR__ . '/javascript',
		__DIR__ . '/css',
		__DIR__ . '/patches',

		// Test fixtures are intentionally "wrong" (dangerous calls, dead code,
		// malformed data) so the code under test has something to react to.
		// Rector MUST NOT touch them — e.g. it would strip the deliberately
		// dangerous calls in dangerous-ext that DangerousCodeScanner must flag.
		// The glob form is required: a bare directory path does not skip
		// recursively here.
		__DIR__ . '/tests/fixtures',
		__DIR__ . '/tests/fixtures/*',

		// eval()-based codegen. These services build closures from a source
		// STRING (to give each handler reflection-visible named params). The
		// captured constructor property ($renderer) and method params ($persona,
		// $prompt, …) are referenced ONLY inside that string, so Rector's
		// dead-code rules see them as unused and strip them — silently breaking
		// the generated closures. eval codegen is fundamentally opaque to static
		// analysis, so these files are exempt from Rector entirely.
		__DIR__ . '/src/Domain/Mcp/Prompt/Service/PromptRegistrar.php',
		__DIR__ . '/src/Domain/Mcp/Tool/Service/SavedQueryToolFactory.php',

		// One call up from the eval: McpServerFactory forwards $persona to
		// PromptRegistrar::registerAll(), which passes it into the eval'd closure.
		// Rector reads the param as unused and would drop the argument here,
		// raising ArgumentCountError at runtime. Keep the call as written.
		RemoveExtraParametersRector::class => [
			__DIR__ . '/src/Domain/Mcp/Service/McpServerFactory.php',
		],
	])
	->withSets([
		// Apply PHP 8.2 level set (current project requirement)
		LevelSetList::UP_TO_PHP_82,

		// Additional useful sets
		SetList::CODE_QUALITY,
		SetList::DEAD_CODE,
		SetList::TYPE_DECLARATION,
		SetList::PRIVATIZATION,
	])
	->withRules([
		// Add specific rules that are helpful for modern PHP
		ExplicitNullableParamTypeRector::class,
	])
	->withPhpSets(php82: true);
