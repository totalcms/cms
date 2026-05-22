<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Data;

use Closure;

/**
 * Value object describing a single MCP resource (concrete `tcms://...` URI).
 *
 * Mirrors McpToolDefinition's role for tools: registered in ResourceRegistry,
 * persona-filtered, and handed to the SDK Server::builder() as addResource(...)
 * calls inside McpServerFactory. The handler is invoked when the SDK receives
 * resources/read for this URI; it must return an array shaped as the SDK's
 * ResourceContent (`{contents: [{uri, mimeType, text}]}`).
 */
readonly class McpResourceDefinition
{
	/**
	 * @param string  $uri         Concrete URI (e.g., 'tcms://blog/')
	 * @param string  $name        Human-readable name for resources/list output
	 * @param string  $description Description for AI agents
	 * @param string  $mimeType    Content type the handler will produce
	 * @param string  $access      'admin', 'public', or 'authenticated' ('authenticated' reserved for Phase 4 OAuth)
	 * @param Closure $handler     Invoked with no args; returns ResourceContent array
	 */
	public function __construct(
		public string $uri,
		public string $name,
		public string $description,
		public string $mimeType,
		public string $access,
		public Closure $handler,
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
