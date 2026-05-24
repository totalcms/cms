<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resource\Service;

use TotalCMS\Domain\DataView\Service\DataViewLister;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceDefinition;
use TotalCMS\Domain\Mcp\Resource\Data\McpResourceTemplateDefinition;
use TotalCMS\Domain\Mcp\Resource\Handler\DataViewResource;

/**
 * Registers per-view MCP resources at `tcms://view/{id}` plus a single
 * `tcms://view/{id}` template covering admin-side template discovery.
 *
 * Why per-view URIs instead of a single `tcms://views/` collection-level
 * resource: views are independently named surfaces (a data view's identity
 * is its id), not interchangeable rows in a collection. Per-view URIs are
 * also what subscription notifications target — when DataViewBuilder
 * completes a rebuild, the listener fires `tcms://view/{id}` not
 * `tcms://dataviews/`.
 *
 * Reservation: `SchemaData::RESERVED_SCHEMAS` blocks `view` and `views` as
 * collection names so the URI namespace can't collide. See Phase 2 Chunk F1.
 */
readonly class DataViewResourceRegistrar
{
	public function __construct(
		private DataViewLister $lister,
		private DataViewResource $resource,
	) {
	}

	public function registerAll(ResourceRegistry $registry): void
	{
		// One template covers every view. Registered at admin scope so
		// resources/templates/list always shows the URI shape to admins; the
		// per-view concrete resources below carry the actual public/admin
		// access metadata that drives resources/list filtering.
		$resource = $this->resource;
		$registry->registerTemplate(new McpResourceTemplateDefinition(
			uriTemplate: 'tcms://view/{id}',
			name: 'view-detail',
			description: 'Fetch a single data view by id. Use list_views or describe_view to discover ids and shapes.',
			mimeType: 'application/json',
			access: 'admin',
			handler: static fn (string $id): array => $resource->read($id),
		));

		foreach ($this->lister->listViews() as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$id = (string)($entry['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$mcp        = is_array($entry['mcp'] ?? null) ? $entry['mcp'] : [];
			$resourceOn = ($mcp['resource'] ?? true) !== false;
			if (!$resourceOn) {
				continue;
			}

			$access = $this->normalizeAccess((string)($mcp['access'] ?? 'admin'));
			$name   = $this->toSdkName($id);
			$desc   = (string)($mcp['description'] ?? $entry['description'] ?? '');
			if ($desc === '') {
				$desc = sprintf('Data view: %s', $entry['name'] ?? $id);
			}

			$registry->register(new McpResourceDefinition(
				uri: sprintf('tcms://view/%s', $id),
				name: $name,
				description: $desc,
				mimeType: 'application/json',
				access: $access,
				handler: static fn (): array => $resource->read($id),
			));
		}
	}

	/**
	 * View ids may contain hyphens (`recent-posts`) which the SDK accepts.
	 * `view-` prefix disambiguates from collection-resource names when both
	 * appear in an agent's combined `resources/list`.
	 */
	private function toSdkName(string $viewId): string
	{
		$safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $viewId);

		return 'view-' . (($safe !== null && $safe !== '') ? $safe : 'unnamed');
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
