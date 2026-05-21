<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tools\Content;

use Mcp\Exception\ToolCallException;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;

/**
 * `query_collection(name, …)` — the AI-facing analogue of T3's
 * `GET /api/collections/{name}/query`. Mirrors the REST filter/sort/pagination
 * vocabulary so AI agents and integrators share one mental model.
 *
 * Three design notes worth knowing before editing this file:
 *
 *   1. **Public-persona safety filter is server-merged.** A public caller can
 *      never see drafts, regardless of what `exclude` they send. We append
 *      `draft:true` to whatever the caller supplied — not replace it. Admin
 *      callers can include drafts intentionally by leaving exclude empty.
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
		));
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
		// Forward-compat acknowledgements. format will route through
		// ContentRenderer when Chunk F ships; locale is reserved for 3.6 i18n.
		// Touched here so static analysis doesn't drift if PHPStan tightens
		// unused-parameter rules later.
		unset($format, $locale);

		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if ($collectionData === null) {
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

		if ($persona === McpPersona::PUBLIC_) {
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

		$result        = $this->indexQueryService->query($collection, $params);
		$nonExposed    = $this->schemaResolver->nonExposedFields($collectionData);
		$items         = [];
		foreach ($result->items as $item) {
			$item['url'] = $this->urlBuilder->buildUrl($collectionData, $item);
			foreach ($nonExposed as $field) {
				unset($item[$field]);
			}
			$items[] = $item;
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
					'description' => 'Comma-separated field:value pairs that exclude items (OR). Public callers always get "draft:true" merged in server-side regardless of caller input.',
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
