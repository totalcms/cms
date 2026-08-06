<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resource\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceDefinition;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceTemplateDefinition;
use TotalCMS\Domain\Mcp\Resource\Handler\CollectionObjectResource;
use TotalCMS\Domain\Mcp\Resource\Handler\CollectionResource;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;

/**
 * Registers per-collection MCP resources into the ResourceRegistry at
 * container build time.
 *
 * For each collection with `mcp.resource: true` (Phase 1 default), registers:
 *  - `tcms://{collectionId}/` → CollectionResource (collection-level read)
 *  - `tcms://{collectionId}/{id}` → CollectionObjectResource template (per-object reads)
 *
 * The collection's `mcp.access` becomes the resource's access level — public
 * collections appear in resources/list for the public persona; admin-only
 * collections only appear for the admin persona; `'authenticated'` collections
 * appear for callers whose OAuth Bearer token carries an `mcp:*` scope.
 *
 * Collections with `mcp.resource: false` are skipped entirely.
 *
 * Wired in McpServerFactory (Task A7) — this class only handles registration;
 * the SDK plumbing happens there.
 */
readonly class CollectionResourceRegistrar
{
	public function __construct(
		private CollectionRepository $collectionRepository,
		private McpSchemaResolver $schemaResolver,
		private CollectionResource $collectionResource,
		private CollectionObjectResource $collectionObjectResource,
	) {
	}

	public function registerAll(ResourceRegistry $registry): void
	{
		foreach ($this->collectionRepository->listAllCollections() as $collection) {
			$this->registerCollection($collection, $registry);
		}
	}

	private function registerCollection(CollectionData $collection, ResourceRegistry $registry): void
	{
		$mcp = $this->schemaResolver->forCollection($collection);
		if ($mcp['resource'] === false) {
			return;
		}

		$access       = $this->normalizeAccess($mcp['access']);
		$displayName  = $collection->name !== '' ? $collection->name : ucfirst($collection->id);
		$sdkName      = $this->toSdkName($collection->id);
		$desc         = (string)($mcp['description'] ?? '');
		if ($desc === '') {
			$desc = \sprintf('%s collection — recent items.', $displayName);
		}

		$collectionId       = $collection->id;
		$collectionResource = $this->collectionResource;
		$objectResource     = $this->collectionObjectResource;

		$registry->register(new McpResourceDefinition(
			uri: \sprintf('tcms://%s/', $collectionId),
			name: $sdkName,
			description: $desc,
			mimeType: 'application/json',
			access: $access,
			handler: static fn (): array => $collectionResource->read($collectionId),
			collectionId: $collectionId,
		));

		// Task 10b: NOW collectionId-gated, matching the collection-level
		// resource above. Task 10 deliberately left this ungated because
		// GetObjectTool had no per-collection group-read requirement at the
		// time (only draft visibility was authority-aware) — gating the
		// template's VISIBILITY then would have been stricter than what
		// GetObjectTool itself enforced at call time. Task 10b closes that
		// gap: GetObjectTool::handler() now enforces PersonaContext::
		// canReadCollection() inline (see its docblock), so this template's
		// visibility can finally match its handler without becoming
		// artificially stricter — resources/templates/list now agrees with
		// what resources/read on this template's URIs will actually allow.
		$registry->registerTemplate(new McpResourceTemplateDefinition(
			uriTemplate: \sprintf('tcms://%s/{id}', $collectionId),
			name: $sdkName . '-item',
			description: \sprintf('Fetch a single %s by id.', $displayName),
			mimeType: 'application/json',
			access: $access,
			handler: static fn (string $id): array => $objectResource->read($collectionId, $id),
			collectionId: $collectionId,
		));
	}

	/**
	 * Convert a collection ID (which may contain hyphens, underscores, or
	 * alphanumerics) to a name string accepted by the MCP SDK.
	 *
	 * The SDK enforces `/^[a-zA-Z0-9_-]+$/` on the `name` field. Collection
	 * IDs already satisfy this pattern (they're validated on creation), so we
	 * use the ID directly as the SDK name rather than the human-readable display
	 * name (which can contain spaces, em-dashes, etc.).
	 */
	private function toSdkName(string $collectionId): string
	{
		// Collection IDs are already alphanumeric + hyphens/underscores.
		// Strip any residual characters just in case, and ensure non-empty.
		$safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $collectionId);

		return ($safe !== null && $safe !== '') ? $safe : 'collection';
	}

	private function normalizeAccess(string $access): string
	{
		return match ($access) {
			'public'        => 'public',
			'authenticated' => 'authenticated',
			default         => 'admin',
		};
	}
}
