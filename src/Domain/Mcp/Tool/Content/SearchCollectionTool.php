<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Content;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Data\ToolRequirement;
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
 *      callers get `exclude: draft:true` applied before any search — a
 *      cheap pre-filter TextSearchProvider can make with only the persona
 *      string it's handed).
 *   2. ObjectSearcher::search on the pre-filtered items.
 *   3. A post-filter here using PersonaContext::canReadDrafts($collection) —
 *      TextSearchProvider has no notion of per-collection access-group
 *      grants, so an AUTHENTICATED caller's matches still need this
 *      authority check before drafts reach the response.
 *
 * Drafts are never visible to a caller without draft read authority for this
 * collection.
 *
 * **Group-gated (Task 10b).** Declares `requires: objects/read/collection` —
 * same call-time guard as query_collection/get_object. `mcp.access: 'public'`
 * collections stay searchable by every caller regardless of group grants
 * (McpServerFactory::guardHandler()'s carve-out via PersonaContext::
 * canReadCollection()).
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
			outputSchema: $this->outputSchema(),
			requires: new ToolRequirement(domain: 'objects', operation: 'read', collectionArg: 'collection'),
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
					'description' => 'Matched objects, each a flat field map decorated with a `url`. Field set varies by collection.',
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'properties'           => [
							'id'  => ['type' => 'string', 'description' => 'Object id (slug).'],
							'url' => ['type' => 'string', 'description' => 'Public URL when the collection has a URL pattern.'],
						],
					],
				],
				'total' => ['type' => 'integer', 'description' => 'Number of items returned.'],
				'limit' => ['type' => 'integer', 'description' => 'Applied result cap.'],
				'query' => ['type' => 'string', 'description' => 'The query that was run.'],
			],
		];
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

		// TextSearchProvider's pre-filter only excludes drafts for the PUBLIC
		// persona; an AUTHENTICATED caller's per-collection authority still
		// has to be checked — see PersonaContext::canReadDrafts(). Computed
		// BEFORE the search call (not just as a post-filter below) so a
		// caller without read authority never has drafts occupying slots in
		// the pre-filter's limit window in the first place — otherwise
		// drafts could crowd out published matches the caller SHOULD see,
		// and the resulting under-filled/empty response would leak a weak
		// "drafts outrank you here" signal. The post-filter stays as an
		// authoritative backstop: an extension-registered SearchProvider may
		// ignore `persona` entirely.
		$canReadDrafts = $this->personaContext->canReadDrafts($collection);

		$cappedLimit = max(1, min(self::LIMIT_CAP, $limit));
		$results     = $this->searchService->search(new SearchQuery(
			text: $query,
			collection: $collection,
			limit: $cappedLimit,
			persona: $canReadDrafts ? $persona->value : 'public',
			// Rank by term coverage (best partial match first) rather than the
			// all-or-nothing AND filter, so descriptive multi-word queries from
			// an agent return useful results instead of nothing.
			relevance: true,
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
			if (!$canReadDrafts && ($item['draft'] ?? false) === true) {
				continue;
			}
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
		// Task 10b fix round 1 (finding #1): the catalog must not advertise
		// collections the caller's groups don't grant read on — same rule
		// this tool's own handler() enforces via canReadCollection().
		$catalog = $this->schemaResolver->renderCatalog(
			$persona,
			McpSchemaResolver::DEFAULT_CATALOG_CAP,
			fn (\TotalCMS\Domain\Collection\Data\CollectionData $c): bool => $this->personaContext->canReadCollection($c->id, $c),
		);

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
