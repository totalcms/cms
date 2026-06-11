<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Content;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Search\Data\SearchQuery;
use TotalCMS\Domain\Search\Service\SearchServiceInterface;

/**
 * `search_collections(query, …)` — cross-collection full-text search. Plural
 * to mirror `list_collections` and contrast with the singular
 * `search_collection` (one collection only). Routes through SearchService
 * per-collection (TextSearchProvider does not support cross-collection queries)
 * so the safety filter is applied inside the provider before ObjectSearcher
 * runs — drafts can never leak to public callers even when crossing collection
 * boundaries.
 *
 * Each result carries a `collection` field so the agent can chain into
 * `get_object` for the full record. The collision risk (a collection with a
 * literal `collection` field would have its decoration overwritten) is
 * accepted in exchange for readable agent-facing output — the framework
 * already does the same with `url` decoration in the content tools.
 *
 * **Not** a full-content search. Iterates collection indexes only — fields
 * not in `list_collections`'s `filterable_fields` aren't searched here.
 */
readonly class SearchCollectionsTool
{
	private const LIMIT_CAP = 50;

	public function __construct(
		private SearchServiceInterface $searchService,
		private CollectionRepository $collections,
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
			name: 'search_collections',
			description: $this->description(),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: $this->inputSchema(),
			annotations: new ToolAnnotations(
				title: 'Search Across Collections',
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
			'required'             => ['items', 'total', 'limit', 'query'],
			'additionalProperties' => false,
			'properties'           => [
				'items' => [
					'type'        => 'array',
					'description' => 'Matched objects across collections, each a flat field map decorated with `collection` (the source collection id) and `url`. Field set varies by collection.',
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'id'         => ['type' => 'string', 'description' => 'Object id (slug).'],
							'collection' => ['type' => 'string', 'description' => 'Collection the hit came from. Pair with id for get_object.'],
							'url'        => ['type' => 'string', 'description' => 'Public URL when the collection has a URL pattern.'],
						],
					],
				],
				'total' => ['type' => 'integer', 'description' => 'Number of items returned in aggregate.'],
				'limit' => ['type' => 'integer', 'description' => 'Applied aggregate result cap.'],
				'query' => ['type' => 'string', 'description' => 'The query that was run.'],
			],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handler(
		string $query,
		int $limit = 10,
		string $format = 'markdown',
		string $locale = '',
	): array {
		unset($locale);

		if (trim($query) === '') {
			throw new ToolCallException(
				'A non-empty `query` is required. Provide one or more keywords to match across collections.',
			);
		}

		$persona = $this->personaContext->current();

		$visible = array_filter(
			$this->collections->listAllCollections(),
			fn (CollectionData $c): bool => $this->schemaResolver->isAccessibleTo($c, $persona->value),
		);

		$cappedLimit = max(1, min(self::LIMIT_CAP, $limit));
		$aggregate   = [];

		foreach ($visible as $collection) {
			// SearchService routes to TextSearchProvider which applies the
			// persona-based safety filter PER COLLECTION before ObjectSearcher
			// runs — same architectural guarantee as before. Drafts never make
			// it into the results for public callers.
			$results = $this->searchService->search(new SearchQuery(
				text: $query,
				collection: $collection->id,
				limit: $cappedLimit,
				persona: $persona->value,
				// Rank by term coverage (best partial match first) rather than
				// the all-or-nothing AND filter, so descriptive multi-word
				// queries from an agent return useful results instead of nothing.
				relevance: true,
			));

			if ($results === []) {
				continue;
			}

			$nonExposed = $this->schemaResolver->nonExposedProperties($collection);
			$renderable = $this->schemaResolver->renderableProperties($collection);

			foreach ($results as $result) {
				if (!$this->objectFetcher->existsObject($collection->id, $result->id)) {
					continue;
				}
				$item = $this->objectFetcher->fetchObject($collection->id, $result->id)->toArray();

				foreach ($nonExposed as $field) {
					unset($item[$field]);
				}
				foreach ($renderable as $field) {
					if (isset($item[$field])) {
						$item[$field] = $this->contentRenderer->render($item[$field], $format);
					}
				}
				$item['collection'] = $collection->id;
				$item['url']        = $this->urlBuilder->buildUrl($collection, $item);
				$aggregate[]        = $item;

				// Early exit once we've collected enough — avoids iterating
				// further collections for results we'd just slice off anyway.
				if (count($aggregate) >= $cappedLimit) {
					break 2;
				}
			}
		}

		return [
			'items' => $aggregate,
			'total' => count($aggregate),
			'limit' => $cappedLimit,
			'query' => $query,
		];
	}

	private function description(): string
	{
		return implode(' ', [
			'Full-text search across every collection you can see, in one call.',
			'Returns matching items each decorated with `collection` (which collection the hit came from) and `url`. Pair `collection` + `id` to fetch the full record via get_object.',
			'Call list_collections for the available collections and describe_collection on any one for its full property list; properties not flagged filterable there exist on objects but are not searched here (use get_object for the full record).',
			'For a single-collection search use search_collection instead — same query syntax, scoped tighter. Drafts are auto-hidden from anonymous callers.',
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function inputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['query'],
			'additionalProperties' => false,
			'properties'           => [
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
					'description' => 'Maximum items returned in aggregate across all collections. Capped at 50 for MCP response-size budgets.',
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
