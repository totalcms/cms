<?php

declare(strict_types=1);

namespace TestVendor\McpResources;

use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;

/**
 * Fixture extension exercising the Phase 2 Chunk C extension resource hooks:
 *
 *  - registerMcpResource() with a custom (non-`tcms://`) URI scheme
 *  - registerMcpResourceTemplate() with a `{id}` placeholder
 *
 * Tests load this through the same ExtensionManager pipeline a real
 * installation would use, then assert the resources land in the
 * ResourceRegistry with the right access and handler delegation.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		// Concrete resource: a list of all widgets, addressable by URI.
		$context->registerMcpResource(
			uri: 'acme://widgets/all',
			description: 'Acme widget inventory — full list of widgets, in inventory order.',
			// JSON resources return the data array directly — the SDK's
			// ResourceResultFormatter JSON-encodes it under the default
			// 'application/json' mimeType. Never wrap in ['contents' => ...].
			handler: fn (): array => ['widgets' => [
				['id' => 'w-001', 'name' => 'Sprocket'],
				['id' => 'w-002', 'name' => 'Cog'],
			]],
			access: 'public',
			name: 'Acme Widgets',
		);

		// Resource template: per-widget detail, URI parameterized on widget id.
		$context->registerMcpResourceTemplate(
			uriTemplate: 'acme://widgets/{id}',
			description: 'A single Acme widget by id. Use list_collections to discover available widget ids via the inventory resource.',
			handler: fn (string $id): array => ['id' => $id, 'name' => "Widget {$id}"],
			access: 'public',
			name: 'Acme Widget Detail',
		);
	}

	public function boot(ExtensionContext $context): void
	{
		// No boot-time work — registration in register() is sufficient.
	}
}
