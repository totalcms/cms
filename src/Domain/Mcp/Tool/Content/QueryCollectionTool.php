<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Content;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * `query_collection(name, …)` — the AI-facing analogue of T3's
 * `GET /api/collections/{name}/query`. Mirrors the REST filter/sort/pagination
 * vocabulary so AI agents and integrators share one mental model.
 *
 * Three design notes worth knowing before editing this file:
 *
 *   1. **Draft-authority safety filter is server-merged.** A caller without
 *      draft read authority for this collection — see
 *      PersonaContext::canReadDrafts() — can never see drafts, regardless of
 *      what `exclude` they send. We append `draft:true` to whatever the
 *      caller supplied — not replace it. ADMIN, and an OAuth caller whose
 *      access groups grant `read` on the collection, can include drafts
 *      intentionally by leaving exclude empty.
 *
 *   2. **Tool description is built per persona.** The dynamic description
 *      builder (B7) renders a field catalog scoped to what the caller can see,
 *      so the AI doesn't get pointed at admin-only collections that would
 *      immediately error. McpServerFactory invokes the builder at server-build
 *      time after persona resolution.
 *
 *   3. **`limit` caps at 50.** REST permits 100, but MCP hosts have tighter
 *      response budgets (Claude.ai ~150k chars, Claude Code ~25k tokens). We
 *      clamp rather than reject — softer UX for the model.
 */
readonly class QueryCollectionTool
{
	public function __construct(
		private IndexQueryService $indexQueryService,
		private CollectionFetcher $collectionFetcher,
		private ObjectUrlBuilder $urlBuilder,
		private PersonaContext $personaContext,
		private McpSchemaResolver $schemaResolver,
		private ContentRenderer $contentRenderer,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'query_collection',
			description: $this->baseDescription(),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: $this->inputSchema(),
			descriptionBuilder: $this->buildDescription(...),
			annotations: new ToolAnnotations(
				title: 'Query Collection',
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
			'required'             => ['items', 'total', 'limit', 'offset', 'has_more'],
			'additionalProperties' => false,
			'properties'           => [
				'items' => [
					'type'        => 'array',
					'description' => 'Matching objects in sort order. Field set varies by collection — call describe_collection for the schema.',
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'required'             => ['id'],
						'properties'           => [
							'id'  => ['type' => 'string'],
							'url' => ['type' => 'string', 'description' => 'Public URL for this object when the collection has a URL pattern configured.'],
						],
					],
				],
				'total'    => ['type' => 'integer', 'description' => 'Total matching items (post-persona-filter).'],
				'limit'    => ['type' => 'integer', 'description' => 'Cap applied to this response — server may have lowered the requested limit.'],
				'offset'   => ['type' => 'integer'],
				'has_more' => ['type' => 'boolean', 'description' => 'True when total > offset + count(items); call again with offset += limit to paginate.'],
			],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handler(
		string $collection,
		string $include = '',
		string $exclude = '',
		string $sort = '',
		int $limit = 10,
		int $offset = 0,
		string $format = 'markdown',
		string $locale = '',
	): array {
		// `locale` is forward-compat for 3.6 i18n; touched so static analysis
		// doesn't drift if PHPStan tightens unused-parameter rules. `format`
		// is consumed below by ContentRenderer on styledtext properties.
		unset($locale);

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
				'Collection "%s" is not available to the current caller. Use list_collections to see what you can query.',
				$collection,
			));
		}

		if (!$this->personaContext->canReadDrafts($collection)) {
			$exclude = $this->mergeExcludeRule($exclude, 'draft:true');
		}

		$cappedLimit = max(1, min(50, $limit));

		$params = [
			'limit'  => (string)$cappedLimit,
			'offset' => (string)max(0, $offset),
		];
		if ($sort !== '') {
			$params['sort'] = $sort;
		}
		if ($include !== '') {
			$params['include'] = $include;
		}
		if ($exclude !== '') {
			$params['exclude'] = $exclude;
		}

		$result      = $this->indexQueryService->query($collection, $params);
		$nonExposed  = $this->schemaResolver->nonExposedProperties($collectionData);
		$renderable  = $this->schemaResolver->renderableProperties($collectionData);
		$items       = [];
		foreach ($result->items as $item) {
			// Strip first so we don't bother rendering content we're about to
			// drop anyway.
			foreach ($nonExposed as $field) {
				unset($item[$field]);
			}
			// Transform styledtext properties per the agent's chosen format.
			foreach ($renderable as $field) {
				if (isset($item[$field])) {
					$item[$field] = $this->contentRenderer->render($item[$field], $format);
				}
			}
			$item['url'] = $this->urlBuilder->buildUrl($collectionData, $item);
			$items[]     = $item;
		}

		return [
			'items'    => $items,
			'total'    => $result->total,
			'limit'    => $result->limit,
			'offset'   => $result->offset,
			'has_more' => $result->hasMore(),
		];
	}

	public function buildDescription(McpPersona $persona): string
	{
		$catalog = $this->schemaResolver->renderCatalog($persona, McpSchemaResolver::DEFAULT_CATALOG_CAP);

		return $catalog === ''
			? $this->baseDescription()
			: $this->baseDescription() . "\n\n" . $catalog;
	}

	private function baseDescription(): string
	{
		// Three-part shape (what / what it returns / what it doesn't do +
		// siblings) from the canonical pattern in mcp-server-dev:build-mcp-server.
		// Never instruct Claude ("always do X") — that's flagged as
		// prompt-injection at Anthropic Directory review.
		return implode(' ', [
			'Query a collection for paginated content.',
			'Returns the matching items plus pagination metadata (total, limit, offset, has_more); each item is decorated with a public `url` field where one is configured.',
			'Use REST-style `include` / `exclude` filter strings (comma-separated field:value pairs; AND-semantics for include, OR-semantics for exclude) and `sort` (e.g. `date:desc`).',
			'Only properties listed in the catalog below are queryable — other schema properties exist on objects but are not filterable here; use get_object if you need the full record or describe_collection to see every property.',
			'This tool does not free-text search content — use search_collection for that. It does not fetch a single object by id — use get_object.',
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
				'include' => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'Comma-separated field:value pairs items must ALL match. Wildcards "*foo*", "foo*", "*foo" supported.',
					'examples'    => ['featured:true', 'category:tech,published:true'],
				],
				'exclude' => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'Comma-separated field:value pairs that exclude items (OR). Callers without draft read authority for this collection always get "draft:true" merged in server-side regardless of caller input.',
					'examples'    => ['draft:true', 'status:archived,featured:false'],
				],
				'sort' => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'Sort rule "field:direction"; multiple rules comma-separated. Only fields marked sortable in the catalog will sort.',
					'examples'    => ['date:desc', 'title:asc'],
				],
				'limit' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => 'Maximum items to return. Capped at 50 (vs. REST\'s 100) to fit MCP response-size budgets.',
				],
				'offset' => [
					'type'        => 'integer',
					'minimum'     => 0,
					'default'     => 0,
					'description' => 'Number of items to skip — combine with limit for pagination.',
				],
				'format' => [
					'type'        => 'string',
					'enum'        => ['markdown', 'html', 'text'],
					'default'     => 'markdown',
					'description' => 'Rendered format for content fields. Accepted now for forward-compat; full conversion ships with the Tiptap→markdown converter in 3.5.x.',
				],
				'locale' => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'BCP 47 locale tag. Accepted for forward-compat with the 3.6 i18n release — has no effect today.',
				],
			],
		];
	}

	private function mergeExcludeRule(string $current, string $required): string
	{
		return $current === '' ? $required : $current . ',' . $required;
	}
}
