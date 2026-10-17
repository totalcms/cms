<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mcp\Auth\Data\McpCallerKind;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;

function callerKindContext(): PersonaContext
{
	return new PersonaContext(
		(new ReflectionClass(CollectionFetcher::class))->newInstanceWithoutConstructor(),
		(new ReflectionClass(McpSchemaResolver::class))->newInstanceWithoutConstructor(),
	);
}

test('a fresh context is an anonymous caller and not read-only', function (): void {
	$context = callerKindContext();

	expect($context->callerKind())->toBe(McpCallerKind::Anonymous)
		->and($context->isReadOnly())->toBeFalse();
});

test('only a session caller is read-only', function (): void {
	foreach (McpCallerKind::cases() as $kind) {
		$context = callerKindContext();
		$context->setCallerKind($kind);

		expect($context->callerKind())->toBe($kind)
			->and($context->isReadOnly())->toBe($kind === McpCallerKind::Session);
	}
});
