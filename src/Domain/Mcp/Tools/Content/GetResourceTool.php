<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tools\Content;

use Mcp\Exception\ToolCallException;
use TotalCMS\Domain\Mcp\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;

/**
 * `get_resource(uri)` — Phase 1 entry point for T3's `tcms://` resource URI
 * scheme. Parses `tcms://{collection}/{id}` and routes to `GetObjectTool`.
 *
 * Working subset of the Phase 2 resource model: provides a stable, discoverable
 * URI namespace today (no breaking change when subscribe/list-resources land)
 * without committing to the full subscription surface yet. URIs surfaced
 * elsewhere (e.g. as cross-references in tool output, future
 * `resources/list` enumeration) can be fed directly into this tool.
 *
 * Persona enforcement lives in `GetObjectTool` — `get_resource` is a thin
 * routing layer, not a policy point. Same persona rules apply.
 */
readonly class GetResourceTool
{
	public function __construct(
		private GetObjectTool $getObjectTool,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'get_resource',
			description: $this->description(),
			access: 'public',
			handler: $this->handler(...),
			inputSchema: $this->inputSchema(),
		));
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handler(string $uri): array
	{
		[$collection, $id] = $this->parseUri($uri);

		return $this->getObjectTool->handler(collection: $collection, id: $id);
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function parseUri(string $uri): array
	{
		// Hand-rolled split rather than parse_url() because parse_url returns
		// false for the (legal-for-our-purposes) `tcms://` no-host case, which
		// would force us to special-case the same error path. Splitting on the
		// scheme prefix gives clean per-segment error messages.
		$prefix = 'tcms://';
		if (!str_starts_with($uri, $prefix)) {
			throw new ToolCallException(
				'Expected a tcms:// URI (e.g. "tcms://blog/hello-world"). Other schemes are not accepted.',
			);
		}

		$rest  = substr($uri, strlen($prefix));
		$parts = explode('/', $rest, 2);

		$collection = $parts[0];
		if ($collection === '') {
			throw new ToolCallException(
				'URI is missing the collection segment. Format: tcms://{collection}/{id}. Use list_collections to discover available collections.',
			);
		}

		$id = $parts[1] ?? '';
		if ($id === '') {
			throw new ToolCallException(sprintf(
				'URI "%s" is missing the object id segment. Format: tcms://{collection}/{id}. Use query_collection or search_collection to discover available ids.',
				$uri,
			));
		}

		return [$collection, $id];
	}

	private function description(): string
	{
		return implode(' ', [
			'Resolve a tcms:// resource URI to its underlying object.',
			'Format: tcms://{collection}/{id} — e.g. tcms://blog/hello-world. Equivalent to calling get_object with the parsed collection and id; returns the same shape.',
			'Use this when a URI has been surfaced to you (in other tool output, in user input, etc.); use get_object directly when you have a separate collection + id pair.',
		]);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function inputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['uri'],
			'additionalProperties' => false,
			'properties'           => [
				'uri' => [
					'type'        => 'string',
					'description' => 'A tcms:// resource URI identifying one object. Format: tcms://{collection}/{id}.',
					'examples'    => ['tcms://blog/hello-world', 'tcms://products/fall-collection-2025'],
				],
			],
		];
	}
}
