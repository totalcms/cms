<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Discovery;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * `describe_collection(collection)` — the detail layer in the three-step
 * discovery workflow:
 *
 *   1. `list_collections`     — what collections exist? (lean overview)
 *   2. `describe_collection`  — what's IN this collection? (per-property detail)
 *   3. `query_collection` / `search_collection` / `get_object` — actual data
 *
 * Splitting list and describe keeps the discovery cost proportional to what
 * the agent actually drills into. For a 44-collection site, `list_collections`
 * pays ~6 KB once; each `describe_collection` call adds ~1.5 KB only for the
 * collections the agent cares about. The fat-list alternative pays ~50 KB
 * upfront regardless of usage.
 *
 * The `properties[]` array includes every exposed property (not just the
 * filterable ones) — non-indexed properties appear with `filterable: false`
 * and `sortable: false` so the agent learns they exist on objects but knows
 * to fetch them via `get_object` rather than try to filter on them.
 */
readonly class DescribeCollectionTool
{
	public function __construct(
		private CollectionFetcher $collectionFetcher,
		private McpSchemaResolver $schemaResolver,
		private PersonaContext $personaContext,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'describe_collection',
			description: $this->description(),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: $this->inputSchema(),
			annotations: new ToolAnnotations(
				title: 'Describe Collection',
				readOnlyHint: true,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: false,
			),
			outputSchema: $this->outputSchema(),
		));
	}

	/**
	 * @return array<string,mixed>
	 */
	private function outputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['id', 'access', 'total_objects', 'properties'],
			'additionalProperties' => false,
			'properties'           => [
				'id'            => ['type' => 'string'],
				'description'   => ['type' => ['string', 'null'], 'description' => 'Collection-level mcp.description, falling back to the schema description.'],
				'url_pattern'   => ['type' => 'string', 'description' => 'URL template for objects, e.g. "/blog/{id}". Empty string when no pattern is configured.'],
				'access'        => ['type' => 'string', 'enum' => ['admin', 'public', 'authenticated'], 'description' => 'Persona that can query this collection.'],
				'total_objects' => ['type' => 'integer'],
				'properties'    => [
					'type'        => 'array',
					'description' => 'One entry per exposed property (mcp.expose:false fields are stripped). Per-property mcp.description is surfaced here when set.',
					'items'       => [
						'type'                 => 'object',
						'required'             => ['name', 'type', 'indexed', 'filterable', 'sortable'],
						'additionalProperties' => false,
						'properties'           => [
							'name'        => ['type' => 'string'],
							'type'        => ['type' => 'string', 'description' => 'Schema field type (text, number, date, toggle, etc.).'],
							'description' => ['type' => ['string', 'null'], 'description' => 'Resolved property description — mcp.description → help → label.'],
							'indexed'     => ['type' => 'boolean', 'description' => 'True when the property appears in query/search results. False = available via get_object only.'],
							'filterable'  => ['type' => 'boolean'],
							'sortable'    => ['type' => 'boolean'],
						],
					],
				],
			],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handler(string $collection): array
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (!$collectionData instanceof \TotalCMS\Domain\Collection\Data\CollectionData) {
			throw new ToolCallException(sprintf(
				'Collection "%s" not found. Use list_collections to see available collections.',
				$collection,
			));
		}

		$persona = $this->personaContext->current();
		if (!$this->schemaResolver->isAccessibleTo($collectionData, $persona->value)) {
			throw new ToolCallException(sprintf(
				'Collection "%s" is not available to the current caller. Use list_collections to see what you can describe.',
				$collection,
			));
		}

		$cfg = $this->schemaResolver->forCollection($collectionData);

		return [
			'id'            => $collectionData->id,
			'description'   => $cfg['description'],
			'url_pattern'   => $collectionData->url,
			'access'        => $cfg['access'],
			'total_objects' => max(0, $collectionData->totalObjects),
			'properties'    => $this->schemaResolver->describeProperties($collectionData),
		];
	}

	private function description(): string
	{
		return implode(' ', [
			'Describe one collection in detail. Returns the collection metadata plus a properties array — each entry is a property with its type, description, and indexed/filterable/sortable flags.',
			'Call list_collections first to find a collection id, then describe_collection to see what is queryable inside it.',
			'`indexed: true` means the property appears in query_collection / search_collection results (and may be filterable/sortable). `indexed: false` means the property exists on objects but is not returned by query/search — retrieve it through get_object instead.',
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function inputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['collection'],
			'additionalProperties' => false,
			'properties'           => [
				'collection' => [
					'type'        => 'string',
					'description' => 'Collection id (slug-form). Use list_collections to discover available collections.',
					'examples'    => ['blog', 'products', 'team'],
				],
			],
		];
	}
}
