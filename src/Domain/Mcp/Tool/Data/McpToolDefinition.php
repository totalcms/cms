<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Data;

use Closure;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Mcp\Data\McpPersona;

/**
 * Value object describing a single MCP tool.
 *
 * Tools are collected in ToolRegistry, filtered by persona, and handed to the
 * mcp/sdk Server::builder() as addTool(...) calls inside McpServerFactory.
 */
readonly class McpToolDefinition
{
	/**
	 * @param string                    $name               Tool name (snake_case, no tcms_ prefix)
	 * @param string                    $description        Static fallback description for AI agents — used when descriptionBuilder is null
	 * @param string                    $access             'admin', 'public', or 'authenticated'
	 * @param Closure                   $handler            Invoked with named params from MCP call
	 * @param array<string,mixed>|null  $inputSchema        JSON Schema for input params (null = SDK introspects from Closure signature)
	 * @param (Closure(McpPersona): string)|null $descriptionBuilder Optional persona-aware description builder.
	 *                                                              When set, McpServerFactory invokes it at server-build
	 *                                                              time and uses the returned string as the tool's
	 *                                                              description. Lets Phase 1 content tools append a
	 *                                                              per-persona field catalog so the AI agent learns
	 *                                                              which collections + fields it can query without a
	 *                                                              separate round-trip to list_collections.
	 * @param ToolAnnotations|null      $annotations        Optional per-tool annotation override (title, readOnlyHint,
	 *                                                      destructiveHint, idempotentHint, openWorldHint). When null,
	 *                                                      McpServerFactory falls back to a read-only default. Destructive
	 *                                                      tools (delete_schema, clear_cache, template_delete) MUST set
	 *                                                      destructiveHint:true — Anthropic Directory review treats
	 *                                                      missing annotations as a pass/fail check.
	 */
	public function __construct(
		public string $name,
		public string $description,
		public string $access,
		public Closure $handler,
		public ?array $inputSchema = null,
		public ?Closure $descriptionBuilder = null,
		public ?ToolAnnotations $annotations = null,
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
