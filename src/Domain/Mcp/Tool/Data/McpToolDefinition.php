<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Data;

use Closure;
use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;

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
	 * @param \Closure                   $handler            Invoked with named params from MCP call
	 * @param array<string,mixed>|null  $inputSchema        JSON Schema for input params (null = SDK introspects from Closure signature)
	 * @param (\Closure(McpPersona): string)|null $descriptionBuilder Optional persona-aware description builder.
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
	 * @param array<string,mixed>|null  $outputSchema       JSON Schema describing the tool's return shape. Optional but
	 *                                                      strongly recommended on content + discovery tools so SDK-aware
	 *                                                      hosts can pre-validate before passing results to the LLM.
	 *                                                      When set, McpServerFactory forwards it to addTool's
	 *                                                      outputSchema argument; the SDK exposes it in tools/list.
	 * @param bool                      $exemptFromPrefix   When true, McpServerFactory does NOT prepend mcp.toolPrefix
	 *                                                      to this tool's name. Used by the ChatGPT-compat search/fetch
	 *                                                      tools, which must keep their exact literal names.
	 * @param ToolRequirement|null      $requires           Optional access-group requirement (Phase 4). When set, an
	 *                                                      AUTHENTICATED caller who doesn't otherwise qualify under
	 *                                                      $access can still see this tool in tools/list provided their
	 *                                                      UserAuthority satisfies the requirement for at least one
	 *                                                      target (see isVisibleTo()). Task 7 adds the corresponding
	 *                                                      call-time enforcement; Task 8 sets this on the shipped tools.
	 */
	public function __construct(
		public string $name,
		public string $description,
		public string $access,
		public \Closure $handler,
		public ?array $inputSchema = null,
		public ?\Closure $descriptionBuilder = null,
		public ?ToolAnnotations $annotations = null,
		public ?array $outputSchema = null,
		public bool $exemptFromPrefix = false,
		public ?ToolRequirement $requires = null,
	) {
	}

	/**
	 * Whether this tool should appear in tools/list for the given persona.
	 *
	 * Persona policy:
	 *   - admin: sees everything
	 *   - authenticated: public + authenticated (OAuth Bearer with mcp:* scope),
	 *     OR (when $requires is set and $authority is known) the caller's
	 *     access-group authority satisfies the requirement for at least one
	 *     target — see ToolRequirement::isSatisfiedForAny().
	 *   - public: public only
	 *
	 * $authority is null for non-Bearer requests (API key / no-auth) and for
	 * any caller PersonaContext couldn't resolve one for; the $requires OR
	 * branch simply never fires in that case, leaving existing behavior
	 * unchanged.
	 */
	public function isVisibleTo(McpPersona $persona, ?UserAuthority $authority = null): bool
	{
		return match ($persona) {
			McpPersona::ADMIN         => true,
			McpPersona::AUTHENTICATED => $this->access === 'public' || $this->access === 'authenticated'
				|| ($this->requires !== null && $authority !== null && $this->requires->isSatisfiedForAny($authority)),
			McpPersona::PUBLIC_       => $this->access === 'public',
		};
	}
}
