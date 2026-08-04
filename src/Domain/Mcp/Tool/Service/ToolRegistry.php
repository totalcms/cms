<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Service;

use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;

/**
 * In-memory registry of MCP tool definitions.
 *
 * Tools self-register at container build time (Phase 0) or via the extension
 * registrar (Phase 1+). McpServerFactory reads this registry to build the SDK
 * server's tool surface per persona.
 *
 * Tool-name uniqueness is enforced: register() throws on collision. Extension
 * code wanting to override a core tool must explicitly unregister() first.
 *
 * Requirement-bearing tools (McpToolDefinition::$requires, Task 7's call-time
 * guard in McpServerFactory) get an extra shape check here — see
 * assertRequirementWellFormed(). These are developer-facing misconfigurations
 * with no safe runtime interpretation, so they throw at registration time
 * (container build / test boot) rather than degrading silently at request
 * time, where they'd read as mysterious permissions bugs instead of what
 * they are: a wiring mistake in the tool's own definition.
 */
class ToolRegistry
{
	/** @var array<string, McpToolDefinition> */
	private array $tools = [];

	/**
	 * Domains where ToolRequirement::isSatisfiedFor()/isSatisfiedForAny()
	 * actually check a specific target (a collection or schema id) rather
	 * than a blanket capability. Mirrors ToolRequirement's own match arms
	 * for 'objects' | 'collections-meta' | 'schemas' vs 'cache' | 'site'.
	 *
	 * @var list<string>
	 */
	private const TARGET_BEARING_DOMAINS = ['objects', 'collections-meta', 'schemas'];

	public function register(McpToolDefinition $tool): void
	{
		if (isset($this->tools[$tool->name])) {
			throw new \LogicException(\sprintf('MCP tool "%s" is already registered.', $tool->name));
		}

		$this->assertRequirementWellFormed($tool);

		$this->tools[$tool->name] = $tool;
	}

	/**
	 * Closes off three ways a requirement-bearing tool can be silently
	 * miswired — none of these are enforceable at runtime because each one
	 * degrades to something that LOOKS like correct (if narrower or
	 * completely denied) behavior instead of visibly breaking:
	 *
	 *   1. inputSchema === null → the SDK falls back to reflecting on the
	 *      registered *handler Closure's* own signature to derive tools/list's
	 *      schema. Once McpServerFactory::guardHandler() wraps the handler
	 *      (any tool with $requires), that closure's signature is the
	 *      wrapper's `array $arguments` — the SDK would advertise a tool
	 *      that accepts a single `arguments` array parameter, and the
	 *      schema validator would then reject every real call built from
	 *      the tool's actual named parameters.
	 *   2. A target-bearing domain ('objects' | 'collections-meta' |
	 *      'schemas') with collectionArg === null → ToolRequirement::
	 *      isSatisfiedFor() has no target to check, so call-time enforcement
	 *      quietly downgrades to isSatisfiedForAny() semantics ("has this
	 *      operation on ANYTHING") — the same relaxed check tools/list
	 *      visibility uses, not the per-call check the domain calls for.
	 *   3. collectionArg set to a name that isn't a declared inputSchema
	 *      property → every call permanently fails the "no target was
	 *      specified" guard branch (the argument can never be present under
	 *      that name), which reads to an operator as "my groups don't grant
	 *      this" rather than "the tool was wired wrong."
	 */
	private function assertRequirementWellFormed(McpToolDefinition $tool): void
	{
		$requires = $tool->requires;
		if ($requires === null) {
			return;
		}

		if ($tool->inputSchema === null) {
			throw new \LogicException(\sprintf(
				'MCP tool "%s" declares a ToolRequirement but has no inputSchema. Once the call-time '
				. 'guard wraps its handler, the SDK would derive the tool\'s schema from the guard '
				. 'wrapper\'s own signature instead of the tool\'s real parameters — breaking both '
				. 'tools/list and every real call. Declare inputSchema explicitly.',
				$tool->name,
			));
		}

		if (in_array($requires->domain, self::TARGET_BEARING_DOMAINS, true) && $requires->collectionArg === null) {
			throw new \LogicException(\sprintf(
				'MCP tool "%s" has a ToolRequirement for domain "%s" but sets no collectionArg. Call-time '
				. 'enforcement would silently downgrade to isSatisfiedForAny() ("has this operation on '
				. 'anything") instead of checking the actual call target. Set ToolRequirement::$collectionArg.',
				$tool->name,
				$requires->domain,
			));
		}

		if ($requires->collectionArg !== null) {
			$properties = $tool->inputSchema['properties'] ?? null;
			if (!is_array($properties) || !array_key_exists($requires->collectionArg, $properties)) {
				throw new \LogicException(\sprintf(
					'MCP tool "%s" declares ToolRequirement::$collectionArg "%s", which is not a property '
					. 'in its inputSchema. This yields a permanent universal deny at call time (the argument '
					. 'can never be present under that name) — it will read as a permissions bug, not the '
					. 'config typo it actually is. Fix the argument name.',
					$tool->name,
					$requires->collectionArg,
				));
			}
		}
	}

	public function unregister(string $name): void
	{
		unset($this->tools[$name]);
	}

	public function get(string $name): ?McpToolDefinition
	{
		return $this->tools[$name] ?? null;
	}

	/**
	 * @return list<McpToolDefinition>
	 */
	public function all(): array
	{
		return array_values($this->tools);
	}

	/**
	 * Tools visible to the given persona, ordered by name for stable output.
	 *
	 * $authority is threaded through to McpToolDefinition::isVisibleTo() so
	 * requirement-gated tools (McpToolDefinition::$requires) can be shown to
	 * an AUTHENTICATED caller whose access-group grants satisfy the
	 * requirement, even when the tool's static $access wouldn't otherwise
	 * qualify. Null for non-Bearer callers, matching isVisibleTo()'s default.
	 *
	 * @return list<McpToolDefinition>
	 */
	public function forPersona(McpPersona $persona, ?UserAuthority $authority = null): array
	{
		$visible = array_values(array_filter(
			$this->tools,
			static fn (McpToolDefinition $tool): bool => $tool->isVisibleTo($persona, $authority),
		));

		usort($visible, static fn (McpToolDefinition $a, McpToolDefinition $b): int => strcmp($a->name, $b->name));

		return $visible;
	}
}
