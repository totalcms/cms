<?php

declare(strict_types=1);

use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Mcp\Tool\Service\SchemaToolRegistrar;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * The read-only rule for browser-session callers keys on annotations: a tool
 * with none is read-only by default. A write tool that forgot to declare
 * readOnlyHint: false would therefore leak into a session caller's tools/list.
 * This pins every core tool whose name says it writes.
 */
beforeEach(function (): void {
	$this->setUpApp(bootstrap());
	$container = $this->app->getContainer();
	$this->registry = $container->get(ToolRegistry::class);
	$container->get(SchemaToolRegistrar::class)->register($this->registry);
});

test('every create/update/patch/delete/clear tool declares readOnlyHint false', function (): void {
	$writes = [];
	foreach ($this->registry->all() as $tool) {
		if (preg_match('/^(create|update|patch|delete|clear)_/', $tool->name) !== 1) {
			continue;
		}
		$writes[$tool->name] = $tool->annotations instanceof ToolAnnotations ? $tool->annotations->readOnlyHint : null;
	}

	expect($writes)->not->toBe([])
		->and(array_filter($writes, static fn (?bool $hint): bool => $hint !== false))->toBe([]);
});

test('the read tools are read-only by declaration or by default', function (): void {
	foreach (['query_collection', 'get_object', 'list_collections', 'describe_collection', 'search_collection'] as $name) {
		$tool = $this->registry->get($name);

		expect($tool)->not->toBeNull()
			->and($tool->annotations?->readOnlyHint ?? true)->toBeTrue();
	}
});
