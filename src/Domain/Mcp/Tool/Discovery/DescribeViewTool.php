<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Discovery;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\Object\Service\ObjectFetcher;

/**
 * `describe_view(id)` — view-side analogue of `describe_collection`.
 *
 * Returns view metadata plus an inferred output shape sampled from the first
 * item of the cached result. View output is freeform (the Twig definition
 * produces arbitrary structures), so we surface what keys the cached items
 * actually carry rather than a schema — agents use this to compose effective
 * query_view / get_view calls.
 *
 * Admin persona also receives the view's Twig `definition` so AI dev
 * workflows can reason about how the data is produced. Public persona never
 * sees the definition — it can leak field names that the view chose not to
 * expose in the output.
 */
readonly class DescribeViewTool
{
	public function __construct(
		private DataViewFetcher $fetcher,
		private ObjectFetcher $objectFetcher,
		private PersonaContext $personaContext,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'describe_view',
			description: $this->description(),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: $this->inputSchema(),
			annotations: new ToolAnnotations(
				title: 'Describe Data View',
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
	public function handler(string $id): array
	{
		$persona = $this->personaContext->current();
		$view    = $this->fetchView($id);

		$access = $this->normalizeAccess((string)($view['mcp']['access'] ?? 'admin'));
		if (!$this->allowed($persona, $access)) {
			throw new ToolCallException(sprintf(
				'View "%s" is not accessible to the current caller. Use list_views to see what you can describe.',
				$id,
			));
		}

		$data        = $this->fetcher->getViewData($id);
		$values      = array_values($data);
		$first       = is_array($values[0] ?? null) ? $values[0] : [];
		$outputKeys  = array_values(array_filter(array_keys($first), is_string(...)));

		$payload = [
			'id'           => (string)$view['id'],
			'name'         => (string)($view['name'] ?? $view['id']),
			'description'  => (string)($view['mcp']['description'] ?? $view['description'] ?? ''),
			'last_built'   => (string)($view['lastBuilt'] ?? ''),
			'access'       => $access,
			'total_items'  => count($values),
			'output_shape' => $outputKeys,
		];

		// Admin persona gets the Twig definition for dev-workflow visibility.
		// Public persona never does — it may reveal fields the view doesn't
		// expose in the output, or describe internal aggregation logic.
		if ($persona === McpPersona::ADMIN) {
			$payload['definition'] = (string)($view['definition'] ?? '');
		}

		return $payload;
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
			'Describe one data view in detail. Returns metadata (id, name, description, last_built, access, total_items) plus output_shape — the list of keys present on the first cached item, sampled so agents can compose effective query_view / get_view calls.',
			'Admin callers also receive the view\'s Twig definition for dev visibility. Use list_views to discover view ids first.',
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
				'id' => [
					'type'        => 'string',
					'description' => 'View id. Use list_views to discover available views.',
					'examples'    => ['recent-posts', 'monthly-sales-summary'],
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
			'required'             => ['id', 'name', 'access', 'total_items', 'output_shape'],
			'additionalProperties' => true,
			'properties'           => [
				'id'           => ['type' => 'string'],
				'name'         => ['type' => 'string'],
				'description'  => ['type' => 'string'],
				'last_built'   => ['type' => 'string'],
				'access'       => ['type' => 'string', 'enum' => ['admin', 'public', 'authenticated']],
				'total_items'  => ['type' => 'integer'],
				'output_shape' => [
					'type'        => 'array',
					'description' => 'Keys present on the first cached item. Empty when the view has never been built or produced no items.',
					'items'       => ['type' => 'string'],
				],
				'definition'   => ['type' => 'string', 'description' => 'Twig source for the view. Admin persona only — absent for public callers.'],
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
