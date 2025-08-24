<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
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
		// Skip specific directories that don't need refactoring
		__DIR__ . '/vendor',
		__DIR__ . '/build',
		__DIR__ . '/resources',
		__DIR__ . '/tcms-data',
		__DIR__ . '/node_modules',
		__DIR__ . '/javascript',
		__DIR__ . '/css',
		__DIR__ . '/patches',
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