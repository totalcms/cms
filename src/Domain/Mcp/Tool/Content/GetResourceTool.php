<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Content;

use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * `get_resource(uri)` — Imperative entry point for T3's `tcms://` resource URIs.
 *
 * Parses URIs like `tcms://blog/hello-world` and routes to GetObjectTool.
 * Functionally equivalent to calling `resources/read` with the same URI for
 * core `tcms://` URIs; provided as a tool so existing tool-call flows can fetch
 * by URI without switching to the resource transport surface.
 *
 * Extension-registered URI schemes (e.g. `acme://...`) are reachable via
 * `resources/read` on the SDK transport surface; this tool intentionally stays
 * scoped to `tcms://` so it can construct precise error messages.
 *
 * Persona enforcement lives in GetObjectTool; get_resource is a thin routing
 * layer, not a policy point. This includes the Task 10b group-read gate:
 * `get_resource` declares no ToolRequirement of its own — `handler()` calls
 * `GetObjectTool::handler()` directly (bypassing get_object's call-time
 * guard), so it inherits the gate from GetObjectTool's inline
 * PersonaContext::canReadCollection() check.
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
			annotations: new ToolAnnotations(
				title: 'Get Resource',
				readOnlyHint: true,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: false,
			),
			outputSchema: $this->outputSchema(),
		));
	}

	/**
	 * Returns the resolved object as a flat field map — same shape as get_object
	 * (this tool delegates to it). Field set varies by collection, so any
	 * additional property is permitted; id + url are always present.
	 *
	 * @return array<string,mixed>
	 */
	private function outputSchema(): array
	{
		return [
			'type'                 => 'object',
			'required'             => ['id'],
			'additionalProperties' => true,
			'properties'           => [
				'id'  => ['type' => 'string', 'description' => 'Object id (slug).'],
				'url' => ['type' => 'string', 'description' => 'Public URL for this object when the collection has a URL pattern configured.'],
			],
		];
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
				'Expected a tcms:// URI (e.g. "tcms://blog/hello-world"). Extension-registered URIs are reachable via the resources/read transport surface.',
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
			throw new ToolCallException(\sprintf(
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
			'Use this when a URI has been surfaced to you (in other tool output, in user input, etc.); use get_object directly when you have a separate collection + id pair. Extension-registered URIs (other schemes) are reachable via the resources/read transport surface.',
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
