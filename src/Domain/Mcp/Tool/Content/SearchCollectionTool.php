<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Content;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Service\SearchServiceInterface;

/**
 * `search_collection(name, query, …)` — free-text search within a single
 * collection. Companion to `query_collection` (structured filters) and
 * `get_object` (single object by id).
 *
 * Routes through SearchService → TextSearchProvider, which applies the same
 * filter+search pipeline as the previous inline implementation:
 *
 *   1. IndexFilter::fetchFilteredIndex with persona-based options (public
 *      callers get `exclude: draft:true` applied before any search).
 *   2. ObjectSearcher::search on the pre-filtered items.
 *
 * Drafts are never visible to public callers — the persona is forwarded to
 * SearchQuery so TextSearchProvider enforces the filter inside the provider.
 */
readonly class SearchCollectionTool
{
	private const LIMIT_CAP = 50;

	public function __construct(
		private SearchServiceInterface $searchService,
		private CollectionFetcher $collectionFetcher,
		private ObjectFetcher $objectFetcher,
		private ObjectUrlBuilder $urlBuilder,
		private PersonaContext $personaContext,
		private McpSchemaResolver $schemaResolver,
		private ContentRenderer $contentRenderer,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'search_collection',
			description: $this->baseDescription(),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: $this->inputSchema(),
			descriptionBuilder: $this->buildDescription(...),
			annotations: new ToolAnnotations(
				title: 'Search Collection',
				readOnlyHint: true,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: false,
			),
		));
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handler(
		string $collection,
		string $query,
		int $limit = 10,
		string $format = 'markdown',
		string $locale = '',
	): array {
		// `locale` is forward-compat for 3.6 i18n; `format` is consumed below
		// by ContentRenderer on styledtext properties.
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

		if (trim($query) === '') {
			throw new ToolCallException(
				'A non-empty `query` is required. Provide one or more keywords to match across content fields.',
			);
		}

		$cappedLimit = max(1, min(self::LIMIT_CAP, $limit));
		$results     = $this->searchService->search(new SearchQuery(
			text:       $query,
			collection: $collection,
			limit:      $cappedLimit,
			persona:    $persona->value,
		));

		// Resolve result IDs back to full object arrays.
		$matches = [];
		foreach ($results as $result) {
			if (!$this->objectFetcher->existsObject($collection, $result->id)) {
				continue;
			}
			$matches[] = $this->objectFetcher->fetchObject($collection, $result->id)->toArray();
		}

		$nonExposed = $this->schemaResolver->nonExposedProperties($collectionData);
		$renderable = $this->schemaResolver->renderableProperties($collectionData);
		$shaped     = [];
		foreach ($matches as $item) {
			foreach ($nonExposed as $field) {
				unset($item[$field]);
			}
			foreach ($renderable as $field) {
				if (isset($item[$field])) {
					$item[$field] = $this->contentRenderer->render($item[$field], $format);
				}
			}
			$item['url'] = $this->urlBuilder->buildUrl($collectionData, $item);
			$shaped[]    = $item;
		}

		return [
			'items' => $shaped,
			'total' => count($shaped),
			'limit' => $cappedLimit,
			'query' => $query,
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
		return implode(' ', [
			'Free-text search within a single collection.',
			'Returns matching items decorated with a public `url`; total reflects what was found.',
			'Supports AND (default), OR, and "quoted phrases". Searches across the properties listed in the catalog below — other schema properties exist on objects but are not searchable here; use get_object for the full record or describe_collection to see every property.',
			'This tool does not filter by field value — use query_collection with `include`/`exclude` for that. Drafts are auto-hidden from anonymous callers.',
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function inputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['collection', 'query'],
			'additionalProperties' => false,
			'properties'           => [
				'collection' => [
					'type'        => 'string',
					'description' => 'Collection id to search within. Use list_collections to discover available collections.',
					'examples'    => ['blog', 'products'],
				],
				'query' => [
					'type'        => 'string',
					'description' => 'Search terms. AND-semantics by default; use `or` between terms for OR; wrap phrases in double quotes.',
					'examples'    => ['rust performance', '"static analysis"', 'php or python'],
				],
				'limit' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::LIMIT_CAP,
					'default'     => 10,
					'description' => 'Maximum items to return (capped at 50 to fit MCP response-size budgets).',
				],
				'format' => [
					'type'        => 'string',
					'enum'        => ['markdown', 'html', 'text'],
					'default'     => 'markdown',
					'description' => 'Output format for styledtext properties on the matched items. markdown is friendliest for AI consumption.',
				],
				'locale' => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'BCP 47 locale tag. Accepted for forward-compat with the 3.6 i18n release — has no effect today.',
				],
			],
		];
	}
}
