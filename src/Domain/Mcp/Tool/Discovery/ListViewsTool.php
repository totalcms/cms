<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Discovery;

use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\DataView\Service\DataViewLister;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * `list_views()` — the discovery tool for data views (pre-computed Twig
 * queries that aggregate across collections).
 *
 * Parallel to `list_collections` for the view surface. Returns the
 * persona-filtered catalog with enough metadata per item that the agent
 * can choose between `query_view`, `get_view`, and `describe_view` without
 * a follow-up call.
 *
 * Each entry: id, name, description, last_built, access, total_items.
 * `total_items` lets the agent decide whether to enumerate via `get_view`
 * (small data) or paginate via `query_view` (large).
 */
readonly class ListViewsTool
{
	public function __construct(
		private DataViewLister $lister,
		private PersonaContext $personaContext,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'list_views',
			description: $this->description(),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: $this->inputSchema(),
			annotations: new ToolAnnotations(
				title: 'List Data Views',
				readOnlyHint: true,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: false,
			),
			outputSchema: $this->outputSchema(),
		));
	}

	/**
	 * @return array{views: list<array<string,mixed>>, total: int}
	 */
	public function handler(): array
	{
		$persona = $this->personaContext->current();

		$views = [];
		foreach ($this->lister->listViews() as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$access = $this->normalizeAccess((string)($entry['mcp']['access'] ?? 'admin'));
			if (!$this->allowed($persona, $access)) {
				continue;
			}

			$id = (string)($entry['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$views[] = [
				'id'          => $id,
				'name'        => (string)($entry['name'] ?? $id),
				'description' => (string)($entry['mcp']['description'] ?? $entry['description'] ?? ''),
				'last_built'  => (string)($entry['lastBuilt'] ?? ''),
				'access'      => $access,
			];
		}

		usort($views, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

		return [
			'views' => $views,
			'total' => count($views),
		];
	}

	private function description(): string
	{
		return implode(' ', [
			'Discover the pre-computed data views visible to you. Lean overview — one entry per view.',
			'Data views are Twig-defined queries that aggregate across collections; their result is cached and accessible by id.',
			'Returns id, name, description, last_built, and access. Use the id with query_view (paginated query), get_view (fetch the whole cached result), or describe_view (output shape + metadata). For raw collection content use list_collections instead.',
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function inputSchema(): array
	{
		// `properties` must serialize as a JSON object (`{}`), not an array (`[]`).
		// Empty PHP arrays encode to `[]`, which the SDK's schema validator rejects.
		return [
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function outputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['views', 'total'],
			'additionalProperties' => false,
			'properties'           => [
				'views' => [
					'type'        => 'array',
					'description' => 'Persona-filtered list, sorted alphabetically by id.',
					'items'       => [
						'type'                 => 'object',
						'required'             => ['id', 'name', 'access'],
						'additionalProperties' => false,
						'properties'           => [
							'id'          => ['type' => 'string'],
							'name'        => ['type' => 'string'],
							'description' => ['type' => 'string'],
							'last_built'  => ['type' => 'string', 'description' => 'ISO 8601 timestamp of the last successful build, empty string when never built.'],
							'access'      => ['type' => 'string', 'enum' => ['admin', 'public', 'authenticated']],
						],
					],
				],
				'total' => ['type' => 'integer'],
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
