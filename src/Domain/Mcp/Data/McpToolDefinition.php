<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Data;

use Closure;

/**
 * Value object describing a single MCP tool.
 *
 * Tools are collected in ToolRegistry, filtered by persona, and handed to the
 * mcp/sdk Server::builder() as addTool(...) calls inside McpServerFactory.
 */
readonly class McpToolDefinition
{
	/**
	 * @param string                    $name        Tool name (snake_case, no tcms_ prefix)
	 * @param string                    $description Human-readable description for AI agents
	 * @param string                    $access      'admin', 'public', or 'authenticated'
	 * @param Closure                   $handler     Invoked with named params from MCP call
	 * @param array<string,mixed>|null  $inputSchema JSON Schema for input params (null = SDK introspects from Closure signature)
	 */
	public function __construct(
		public string $name,
		public string $description,
		public string $access,
		public Closure $handler,
		public ?array $inputSchema = null,
	) {
	}

	/**
	 * Whether this tool should appear in tools/list for the given persona.
	 *
	 * Persona policy:
	 *   - admin: sees everything
	 *   - authenticated (Phase 4): public + authenticated
	 *   - public: public only
	 */
	public function isVisibleTo(McpPersona $persona): bool
	{
		return match ($persona) {
			McpPersona::ADMIN         => true,
			McpPersona::AUTHENTICATED => $this->access === 'public' || $this->access === 'authenticated',
			McpPersona::PUBLIC_       => $this->access === 'public',
		};
	}
}
