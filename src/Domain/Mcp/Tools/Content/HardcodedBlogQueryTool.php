<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tools\Content;

use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;

/**
 * Phase 0 placeholder — hardcoded query against the `blog` collection.
 *
 * Mirrors the REST query interface so AI agents and REST integrators share one
 * mental model: `include` / `exclude` / `sort` / `limit` / `offset` all behave
 * identically to `GET /api/collections/blog/query`. Public-callers' results are
 * server-filtered to hide drafts regardless of the supplied `exclude` — content
 * moderation, not caller preference.
 *
 * Phase 1's ToolGenerator replaces this with auto-generated query_<collection>
 * tools per schema. The persona-aware safety-filter pattern (merge into exclude
 * before dispatch) is the model the generator will reuse.
 *
 * `search` and `filter` (contains-mode) are deliberately not exposed yet: in
 * QueryPipeline both are mutually exclusive with include/exclude, which would
 * let a search query bypass our draft safety filter. Phase 1 will redesign
 * search to apply safety filters at a lower layer.
 */
readonly class HardcodedBlogQueryTool
{
	private const COLLECTION = 'blog';

	public function __construct(
		private IndexQueryService $indexQueryService,
		private CollectionFetcher $collectionFetcher,
		private ObjectUrlBuilder $urlBuilder,
		private PersonaContext $personaContext,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'query_blog',
			description: implode(' ', [
				'Query the blog collection. Returns paginated blog posts with id, title, standard metadata, and a public URL.',
				'Use `include` / `exclude` filter strings (comma-separated field:value pairs) — same syntax as the REST API.',
				'Examples: include="featured:true,category:tech", exclude="draft:true". Wildcards supported: "*foo*", "foo*", "*foo".',
				'Drafts are always hidden from anonymous callers (the exclude is merged server-side); admin callers can include them by leaving `exclude` empty.',
				'Phase 0 placeholder — Phase 1 generates this dynamically per collection.',
			]),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: [
				'type'       => 'object',
				'properties' => [
					'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10, 'description' => 'Maximum items to return.'],
					'offset'  => ['type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => 'Number of items to skip (for pagination).'],
					'sort'    => ['type' => 'string', 'default' => '', 'description' => 'Sort rule, e.g. "date:desc" or "title:asc". Multiple rules comma-separated.'],
					'include' => ['type' => 'string', 'default' => '', 'description' => 'Comma-separated field:value pairs items must match (AND). e.g. "featured:true,category:tech".'],
					'exclude' => ['type' => 'string', 'default' => '', 'description' => 'Comma-separated field:value pairs that exclude items (OR). e.g. "draft:true". Anonymous callers always get "draft:true" merged in regardless.'],
				],
				'additionalProperties' => false,
			],
		));
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handler(int $limit = 10, int $offset = 0, string $sort = '', string $include = '', string $exclude = ''): array
	{
		// Public persona: enforce draft:true exclude server-side. Merge rather
		// than overwrite so caller-supplied excludes still apply.
		if ($this->personaContext->current() === McpPersona::PUBLIC_) {
			$exclude = $this->mergeExcludeRule($exclude, 'draft:true');
		}

		// Only include non-empty filter params — matches REST URL semantics
		// where `?include=` empty values aren't sent. (ObjectFilter now defends
		// against empty values too, but keeping the params clean is tidier.)
		$params = [
			'limit'  => (string)$limit,
			'offset' => (string)$offset,
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

		$result = $this->indexQueryService->query(self::COLLECTION, $params);

		// Decorate each item with a public URL so AI agents can link back to
		// the post on the site. CollectionData carries the URL pattern;
		// ObjectUrlBuilder handles templated vs prefix vs pretty-URL forms.
		$items      = $result->items;
		$collection = $this->collectionFetcher->fetchCollection(self::COLLECTION);
		if ($collection !== null) {
			foreach ($items as &$item) {
				$item['url'] = $this->urlBuilder->buildUrl($collection, $item);
			}
			unset($item);
		}

		return [
			'items'    => $items,
			'total'    => $result->total,
			'limit'    => $result->limit,
			'offset'   => $result->offset,
			'has_more' => $result->hasMore(),
		];
	}

	private function mergeExcludeRule(string $current, string $required): string
	{
		return $current === '' ? $required : $current . ',' . $required;
	}
}
