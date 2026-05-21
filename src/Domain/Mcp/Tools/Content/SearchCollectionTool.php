<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tools\Content;

use Mcp\Exception\ToolCallException;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;
use TotalCMS\Domain\Query\Service\ObjectSearcher;

/**
 * `search_collection(name, query, …)` — free-text search within a single
 * collection. Companion to `query_collection` (structured filters) and
 * `get_object` (single object by id).
 *
 * **Why this tool exists instead of routing through IndexQueryService:**
 * QueryPipeline treats `search` and `include`/`exclude` as mutually exclusive
 * — passing both clears the safety filter. Public callers must not be able to
 * see drafts EVER, so for the search path we bypass the pipeline and:
 *
 *   1. Load the index with the safety filter pre-applied (IndexFilter::fetchFilteredIndex
 *      with `exclude: draft:true` for public callers).
 *   2. Hand the already-filtered items to ObjectSearcher::search().
 *
 * The ordering is the load-bearing detail. A regression that wires search back
 * through QueryPipeline, or applies the search before the safety filter, would
 * leak drafts to public callers and is the kind of bug only a test can catch.
 */
readonly class SearchCollectionTool
{
	private const LIMIT_CAP = 50;

	public function __construct(
		private IndexFilter $indexFilter,
		private ObjectSearcher $searcher,
		private CollectionFetcher $collectionFetcher,
		private ObjectUrlBuilder $urlBuilder,
		private PersonaContext $personaContext,
		private McpSchemaResolver $schemaResolver,
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
		));
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handler(
		string $collection,
		string $query,
		int $limit = 10,
		string $locale = '',
	): array {
		unset($locale);

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

		if (trim($query) === '') {
			throw new ToolCallException(
				'A non-empty `query` is required. Provide one or more keywords to match across content fields.',
			);
		}

		// Load index with the safety filter applied FIRST. Order is essential —
		// see the class docblock.
		$filterOptions = $persona === McpPersona::PUBLIC_ ? ['exclude' => 'draft:true'] : [];
		$items         = $this->indexFilter->fetchFilteredIndex($collection, $filterOptions);

		$matches    = $this->searcher->search($items, $query);
		$cappedLimit = max(1, min(self::LIMIT_CAP, $limit));
		$matches     = array_slice($matches, 0, $cappedLimit);

		$nonExposed = $this->schemaResolver->nonExposedFields($collectionData);
		$shaped     = [];
		foreach ($matches as $item) {
			$item['url'] = $this->urlBuilder->buildUrl($collectionData, $item);
			foreach ($nonExposed as $field) {
				unset($item[$field]);
			}
			$shaped[] = $item;
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
			'Supports AND (default), OR, and "quoted phrases". Searches across the fields listed in the catalog below — other schema fields exist on objects but are not searchable here; use get_object if you need the full record.',
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
				'locale' => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'BCP 47 locale tag. Accepted for forward-compat with the 3.6 i18n release — has no effect today.',
				],
			],
		];
	}
}
