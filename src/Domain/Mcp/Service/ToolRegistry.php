<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Data\McpToolDefinition;

/**
 * In-memory registry of MCP tool definitions.
 *
 * Tools self-register at container build time (Phase 0) or via the extension
 * registrar (Phase 1+). McpServerFactory reads this registry to build the SDK
 * server's tool surface per persona.
 *
 * Tool-name uniqueness is enforced: register() throws on collision. Extension
 * code wanting to override a core tool must explicitly unregister() first.
 */
class ToolRegistry
{
	/** @var array<string, McpToolDefinition> */
	private array $tools = [];

	public function register(McpToolDefinition $tool): void
	{
		if (isset($this->tools[$tool->name])) {
			throw new \LogicException(\sprintf('MCP tool "%s" is already registered.', $tool->name));
		}

		$this->tools[$tool->name] = $tool;
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
	 * @return list<McpToolDefinition>
	 */
	public function forPersona(McpPersona $persona): array
	{
		$visible = array_values(array_filter(
			$this->tools,
			static fn (McpToolDefinition $tool): bool => $tool->isVisibleTo($persona),
		));

		usort($visible, static fn (McpToolDefinition $a, McpToolDefinition $b): int => strcmp($a->name, $b->name));

		return $visible;
	}
}
