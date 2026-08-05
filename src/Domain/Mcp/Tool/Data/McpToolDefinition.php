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
	 *   - public: visible whenever $access is 'public', PROVIDED $requires is
	 *     either null OR the specific "objects+read" shape (see below).
	 *     Any OTHER requirement (write/schema/collections-meta/cache/site)
	 *     still hides the tool from PUBLIC_, requirement or not.
	 *
	 * $authority is null for non-Bearer requests (API key / no-auth) and for
	 * any caller PersonaContext couldn't resolve one for; the $requires OR
	 * branch simply never fires in that case, leaving existing behavior
	 * unchanged.
	 *
	 * **Task 10b revision — narrow, not "public bypasses everything".**
	 * Through Task 9 every requirement-bearing tool had $access 'admin'
	 * (write/schema/cache tools) — PUBLIC_ could never reach one anyway, so
	 * this method used to hide ANY requirement-bearing tool from PUBLIC_
	 * outright as defense in depth against a hypothetical misconfiguration
	 * (access:'public' + $requires on the same tool, fully unguarded for
	 * anonymous callers — see McpServerFactory::guardHandler()'s matching old
	 * unconditional PUBLIC_ denial). Task 10b's read tools
	 * (query_collection/get_object/search_collection) are the first
	 * DELIBERATE instance of $access:'public' + $requires together: an
	 * unauthenticated caller must keep reading public collections exactly as
	 * before, and the requirement exists solely to bound AUTHENTICATED
	 * callers beyond what public exposure already grants. For a PUBLIC_
	 * caller of an objects+read tool, $access:'public' IS the entire grant —
	 * there is no authority for the requirement to further restrict, so it's
	 * simply irrelevant to this persona, not a hole.
	 *
	 * This carve-out is deliberately scoped to `domain === 'objects' &&
	 * operation === 'read'` — NOT "any $requires on an access:'public' tool"
	 * — because "public" has only ever meant "readable by everyone"; a
	 * hypothetical future tool that mistakenly combined access:'public' with
	 * a write/schema/cache/site requirement must NOT fail open for
	 * anonymous callers. McpServerFactory::guardHandler() mirrors this exact
	 * condition (see its docblock) — the real handler behind an
	 * objects+read+public tool still applies its own mcp.access exposure
	 * check exactly as it always has.
	 */
	public function isVisibleTo(McpPersona $persona, ?UserAuthority $authority = null): bool
	{
		return match ($persona) {
			McpPersona::ADMIN         => true,
			McpPersona::AUTHENTICATED => $this->access === 'public' || $this->access === 'authenticated'
				|| ($this->requires !== null && $authority !== null && $this->requires->isSatisfiedForAny($authority)),
			McpPersona::PUBLIC_       => $this->access === 'public' && $this->isPublicReadRequirement(),
		};
	}

	/**
	 * True when $requires is null, or is the "objects+read" shape the
	 * PUBLIC_ carve-out above (and McpServerFactory::guardHandler()) is
	 * scoped to. See isVisibleTo()'s docblock.
	 */
	private function isPublicReadRequirement(): bool
	{
		return $this->requires === null
			|| ($this->requires->domain === 'objects' && $this->requires->operation === 'read');
	}
}
