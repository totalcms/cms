<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Content;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\DataView\Service\DataViewQueryService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

/**
 * `query_view(id, …)` — REST-style paginated query against a data view's
 * cached result. Filter/sort/limit/offset vocabulary identical to
 * `query_collection`, dispatched through DataViewQueryService::query()
 * which reuses the same QueryPipeline.
 *
 * Persona check enforces the view's `mcp.access` per call (default 'admin').
 * Public callers see only views marked `mcp.access: 'public'`. `limit` capped
 * at 50 — same response-size guardrail as query_collection.
 *
 * View data is freeform — the Twig definition can produce any shape — so we
 * don't merge in safety filters (e.g. draft hiding) the way query_collection
 * does. The view's author is responsible for not including sensitive fields
 * in a view marked public.
 */
readonly class QueryViewTool
{
	private const MAX_LIMIT = 50;

	public function __construct(
		private DataViewQueryService $queryService,
		private ObjectFetcher $objectFetcher,
		private PersonaContext $personaContext,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'query_view',
			description: $this->description(),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: $this->inputSchema(),
			annotations: new ToolAnnotations(
				title: 'Query Data View',
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
	public function handler(
		string $id,
		string $include = '',
		string $exclude = '',
		string $sort = '',
		int $limit = 10,
		int $offset = 0,
		string $format = 'markdown',
		string $locale = '',
	): array {
		unset($format, $locale); // forward-compat only

		$persona = $this->personaContext->current();
		$view    = $this->fetchView($id);

		$access = $this->normalizeAccess((string)($view['mcp']['access'] ?? 'admin'));
		if (!$this->allowed($persona, $access)) {
			throw new ToolCallException(sprintf(
				'View "%s" is not accessible to the current caller. Use list_views to see what you can query.',
				$id,
			));
		}

		$cappedLimit = max(1, min(self::MAX_LIMIT, $limit));

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

		$result = $this->queryService->query($id, $params);

		return [
			'items'    => $result->items,
			'total'    => $result->total,
			'limit'    => $result->limit,
			'offset'   => $result->offset,
			'has_more' => $result->hasMore(),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function fetchView(string $id): array
	{
		try {
			return $this->objectFetcher->fetchObject('dataviews', $id)->toArray();
		} catch (\Throwable) {
			throw new ToolCallException(sprintf(
				'View "%s" not found. Call list_views to see available views.',
				$id,
			));
		}
	}

	private function description(): string
	{
		return implode(' ', [
			"Paginated query against a data view's cached result. REST-syntax include/exclude/sort, same vocabulary as query_collection.",
			'View data is freeform — call describe_view first to learn the field set before composing filters. `limit` is capped at 50; use offset to paginate larger views.',
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function inputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['id'],
			'additionalProperties' => false,
			'properties'           => [
				'id'      => ['type' => 'string', 'description' => 'View id from list_views.', 'examples' => ['recent-posts', 'monthly-sales-summary']],
				'include' => ['type' => 'string', 'description' => 'REST-style filter, e.g. "category:tech,published:true". Field names depend on the view\'s output shape.', 'examples' => ['featured:true', 'year:2025']],
				'exclude' => ['type' => 'string', 'description' => 'REST-style negative filter.', 'examples' => ['draft:true']],
				'sort'    => ['type' => 'string', 'description' => 'Sort spec, e.g. "date:desc".'],
				'limit'   => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => 10],
				'offset'  => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
				'format'  => ['type' => 'string', 'enum' => ['markdown', 'html', 'text'], 'default' => 'markdown', 'description' => 'Forward-compat with query_collection; no effect today since view data is freeform.'],
				'locale'  => ['type' => 'string', 'default' => '', 'description' => 'Forward-compat for 3.6 i18n.'],
			],
		];
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
					'description' => 'Items in the requested page. Field set varies by view; call describe_view for the shape.',
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => true,
					],
				],
				'total'    => ['type' => 'integer'],
				'limit'    => ['type' => 'integer', 'description' => 'Effective cap applied to this response.'],
				'offset'   => ['type' => 'integer'],
				'has_more' => ['type' => 'boolean'],
			],
		];
	}

	private function allowed(McpPersona $persona, string $access): bool
	{
		return match ($persona) {
			McpPersona::ADMIN         => true,
			McpPersona::AUTHENTICATED => $access === 'public' || $access === 'authenticated',
			McpPersona::PUBLIC_       => $access === 'public',
		};
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
