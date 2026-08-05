<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Discovery;

use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * `list_collections()` — the discovery tool. Persona-filtered list of every
 * collection the caller can reach, with enough metadata per item that the
 * agent can compose effective `query_collection` / `search_collection` /
 * `get_object` calls without further introspection.
 *
 * Static description (no `descriptionBuilder`): the dynamic field catalog
 * that content tools embed in their descriptions lives in this tool's
 * response body instead. One tool's body is another's description — same
 * information, the agent reaches it via the natural workflow (list → pick →
 * query).
 *
 * The `filterable_fields` array per item is structurally identical to the
 * one consumed by the content-tool description builders, so the agent gets
 * a consistent answer to "what can I query on?" whether it reads the tool
 * description or calls `list_collections`.
 *
 * **Group-gated, filter not deny (Task 10b).** This is metadata-only (no
 * object content) and enumerates every collection at once — the same
 * "no single target" shape as SearchCollectionsTool — so it declares no
 * ToolRequirement. The persona-exposure filter additionally requires
 * PersonaContext::canReadCollection(), so a collection the caller's groups
 * don't grant read on (and that isn't `mcp.access: 'public'`) is silently
 * omitted rather than causing an error. Advertising a collection here that
 * query_collection/get_object would then deny would be confusing UX for the
 * agent, independent of any security concern (list_collections has never
 * itself returned object content).
 */
readonly class ListCollectionsTool
{
	public function __construct(
		private CollectionRepository $collections,
		private McpSchemaResolver $schemaResolver,
		private PersonaContext $personaContext,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'list_collections',
			description: $this->description(),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: $this->inputSchema(),
			annotations: new ToolAnnotations(
				title: 'List Collections',
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
			'required'             => ['collections', 'total'],
			'additionalProperties' => false,
			'properties'           => [
				'collections' => [
					'type'        => 'array',
					'description' => 'Persona-filtered list, sorted alphabetically by id.',
					'items'       => [
						'type'                 => 'object',
						'required'             => ['id', 'name', 'schema', 'access', 'total_objects'],
						'additionalProperties' => false,
						'properties'           => [
							'id'            => ['type' => 'string'],
							'name'          => ['type' => 'string', 'description' => 'Display label, falls back to id.'],
							'schema'        => ['type' => 'string', 'description' => 'Schema id this collection uses.'],
							'description'   => ['type' => ['string', 'null'], 'description' => 'Resolved mcp.description, falling back to the schema description.'],
							'url_pattern'   => ['type' => 'string', 'description' => 'URL template for objects, e.g. "/blog/{id}". Empty string when no pattern is configured.'],
							'access'        => ['type' => 'string', 'enum' => ['admin', 'public', 'authenticated']],
							'total_objects' => ['type' => 'integer'],
						],
					],
				],
				'total' => ['type' => 'integer', 'description' => 'Number of collections in the response (post-persona-filter).'],
			],
		];
	}

	/**
	 * @return array{collections: list<array<string,mixed>>, total: int}
	 */
	public function handler(): array
	{
		$persona = $this->personaContext->current()->value;

		$visible = array_values(array_filter(
			$this->collections->listAllCollections(),
			fn (CollectionData $c): bool => $this->schemaResolver->isAccessibleTo($c, $persona)
				&& $this->personaContext->canReadCollection($c->id, $c),
		));

		// Stable alphabetical order — identical sites generate identical
		// tools/call responses, which is friendlier to caching layers and
		// helps the agent reason about the namespace shape.
		usort($visible, static fn (CollectionData $a, CollectionData $b): int => strcmp($a->id, $b->id));

		$items = [];
		foreach ($visible as $collection) {
			$cfg = $this->schemaResolver->forCollection($collection);

			$items[] = [
				'id'            => $collection->id,
				'name'          => $collection->name ?? $collection->id,
				'schema'        => $collection->schema,
				'description'   => $cfg['description'],
				'url_pattern'   => $collection->url,
				'access'        => $cfg['access'],
				// total_objects lets the agent plan: paginate large collections
				// (`blog` may have 26k+ posts), enumerate small ones in one
				// query_collection call with limit:50. -1 means "not calculated
				// yet" — CollectionData self-heals on next save.
				'total_objects' => max(0, $collection->totalObjects),
			];
		}

		return [
			'collections' => $items,
			'total'       => count($items),
		];
	}

	private function description(): string
	{
		return implode(' ', [
			'Discover the collections visible to you. Lean overview — one entry per collection.',
			'Returns id, name (display label), schema (the schema id this collection uses), description, url_pattern, access level, and total_objects (so you can decide whether to paginate or enumerate).',
			'For the per-property detail of one collection (which properties exist, which are filterable/sortable), call describe_collection. Then query_collection / search_collection / get_object for the data.',
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function inputSchema(): array
	{
		// `properties` must serialize as a JSON OBJECT, not an array. Empty PHP
		// arrays json_encode to `[]`, which makes the SDK's JSON Schema validator
		// reject the schema with "properties must be an object". Forcing
		// stdClass guarantees `{}` on the wire.
		return [
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		];
	}
}
