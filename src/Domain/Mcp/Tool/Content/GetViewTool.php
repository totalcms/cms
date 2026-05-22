<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Content;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

/**
 * `get_view(id, format?)` — fetch a data view's full cached result, capped at
 * 50 items. For larger views, returns the first 50 and appends a hint pointing
 * the agent at `query_view` for pagination.
 *
 * Parallel to `get_object` for the view surface. View data is freeform — the
 * Twig definition can produce any JSON-encodable shape — so output shape is
 * `additionalProperties: true` per item. Use `describe_view` to learn the
 * field set before composing filters.
 */
readonly class GetViewTool
{
	private const MAX_ITEMS = 50;

	public function __construct(
		private DataViewFetcher $fetcher,
		private ObjectFetcher $objectFetcher,
		private PersonaContext $personaContext,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'get_view',
			description: $this->description(),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: $this->inputSchema(),
			annotations: new ToolAnnotations(
				title: 'Get Data View',
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
	public function handler(string $id, string $format = 'markdown', string $locale = ''): array
	{
		// `format` + `locale` accepted for forward-compat consistency with
		// query_collection / get_object — view data is freeform so there's no
		// styledtext rendering pass to perform. Touched here to keep PHPStan
		// quiet about unused params.
		unset($format, $locale);

		$persona = $this->personaContext->current();
		$view    = $this->fetchView($id);

		$access = $this->normalizeAccess((string)($view['mcp']['access'] ?? 'admin'));
		if (!$this->allowed($persona, $access)) {
			throw new ToolCallException(sprintf(
				'View "%s" is not accessible to the current caller. Use list_views to see what you can fetch.',
				$id,
			));
		}

		$data      = $this->fetcher->getViewData($id);
		$values    = array_values($data);
		$total     = count($values);
		$truncated = $total > self::MAX_ITEMS;
		$items     = array_slice($values, 0, self::MAX_ITEMS);

		$payload = [
			'items'     => $items,
			'total'     => $total,
			'truncated' => $truncated,
		];
		if ($truncated) {
			$payload['hint'] = sprintf(
				'Showing %d of %d items. Call query_view({id: "%s", offset, limit}) to paginate.',
				self::MAX_ITEMS,
				$total,
				$id,
			);
		}

		return $payload;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function fetchView(string $id): array
	{
		try {
			$view = $this->objectFetcher->fetchObject('dataviews', $id)->toArray();
		} catch (\Throwable) {
			throw new ToolCallException(sprintf(
				'View "%s" not found. Call list_views to see available views.',
				$id,
			));
		}

		return $view;
	}

	private function description(): string
	{
		return implode(' ', [
			"Fetch a data view's full cached result by id. Output is freeform — each item's field set depends on the view's Twig definition. Call describe_view first to learn the expected shape.",
			'Capped at 50 items; larger views return the first 50 plus a hint to call query_view for pagination. For per-item paginated access use query_view directly.',
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
				'id'     => [
					'type'        => 'string',
					'description' => 'View id. Use list_views to discover available views.',
					'examples'    => ['recent-posts', 'monthly-sales-summary'],
				],
				'format' => [
					'type'        => 'string',
					'enum'        => ['markdown', 'html', 'text'],
					'default'     => 'markdown',
					'description' => 'Forward-compat with collection tools. View data is freeform so this has no effect today.',
				],
				'locale' => [
					'type'        => 'string',
					'default'     => '',
					'description' => 'BCP 47 locale tag — forward-compat for the 3.6 i18n release. No effect today.',
				],
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
			'required'             => ['items', 'total', 'truncated'],
			'additionalProperties' => true,
			'properties'           => [
				'items' => [
					'type'        => 'array',
					'description' => 'Items produced by the view, capped at 50. Field set varies by view — call describe_view for the expected shape.',
					'items'       => [
						'type'                 => 'object',
						'additionalProperties' => true,
					],
				],
				'total'     => ['type' => 'integer', 'description' => 'Total cached items in the view (pre-cap).'],
				'truncated' => ['type' => 'boolean', 'description' => 'True when total > 50; pair with query_view for full pagination.'],
				'hint'      => ['type' => 'string', 'description' => 'Present only when truncated — points the agent at query_view.'],
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
