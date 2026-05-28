<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resource\Data;

use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;

/**
 * Value object describing an MCP resource template — a URI pattern like
 * `tcms://blog/{id}` that AI agents fill in to construct concrete URIs for
 * resources/read. Distinct from McpResourceDefinition (one concrete URI per
 * registration) in that one template covers an unbounded set of objects
 * without forcing enumeration into resources/list.
 *
 * Handler receives the substituted segment values as named arguments matching
 * the template's `{name}` placeholders. The SDK's URI router does the
 * extraction; we receive parsed args.
 */
readonly class McpResourceTemplateDefinition
{
	/**
	 * @param string  $uriTemplate URI template with `{name}` placeholders (e.g. 'tcms://blog/{id}')
	 * @param string  $name        Human-readable name
	 * @param string  $description Description for AI agents
	 * @param string  $mimeType    Content type the handler will produce
	 * @param string  $access      'admin', 'public', or 'authenticated' (OAuth Bearer with mcp:* scope)
	 * @param \Closure $handler     Invoked with named args matching template variables
	 */
	public function __construct(
		public string $uriTemplate,
		public string $name,
		public string $description,
		public string $mimeType,
		public string $access,
		public \Closure $handler,
	) {
	}

	public function isVisibleTo(McpPersona $persona): bool
	{
		return match ($persona) {
			McpPersona::ADMIN         => true,
			McpPersona::AUTHENTICATED => $this->access === 'public' || $this->access === 'authenticated',
			McpPersona::PUBLIC_       => $this->access === 'public',
		};
	}
}
