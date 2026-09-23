<?php

declare(strict_types=1);

use TotalCMS\Domain\Mcp\Tool\Service\McpToolPrefix;
use TotalCMS\Support\Config;

// One prefix rule for the server factory and the tools validator.
function prefixConfig(mixed $prefix): Config
{
	$config      = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->mcp = $prefix === null ? [] : ['toolPrefix' => $prefix];

	return $config;
}

test('a valid prefix gains its underscore', function (): void {
	expect(McpToolPrefix::fromConfig(prefixConfig('acme')))->toBe('acme_')
		->and(McpToolPrefix::fromConfig(prefixConfig(' site9_x ')))->toBe('site9_x_');
});

test('missing, empty and invalid prefixes are no prefix', function (mixed $prefix): void {
	expect(McpToolPrefix::fromConfig(prefixConfig($prefix)))->toBe('');
})->with([null, '', '9abc', 'Acme', 'has-dash', str_repeat('a', 25)]);
