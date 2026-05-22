<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * Adds extension-registered MCP tools to the core `ToolRegistry`, with strict
 * collision detection.
 *
 * **Policy: strict deny on collisions** — both core-vs-extension and
 * extension-vs-extension. The plan considered last-wins for cross-extension
 * collisions (TwigExtensionRegistrar's pattern) but settled on strict deny
 * here because MCP tools are a security surface: a rogue or buggy extension
 * shadowing `query_collection` could silently exfiltrate data through what
 * the agent thinks is a familiar tool. Better to fail loudly and force the
 * operator to resolve the conflict.
 *
 * Companion to TwigExtensionRegistrar — same architectural role, tighter
 * conflict policy.
 */
final readonly class McpExtensionRegistrar
{
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Register extension tools into the registry, applying collision policy.
	 *
	 * @param array<string,list<mixed>> $extensionTools  Map of extensionId =>
	 *                                                   list of McpToolDefinition
	 *                                                   (non-McpToolDefinition
	 *                                                   entries are defensively
	 *                                                   skipped — bad extension
	 *                                                   shouldn't crash boot).
	 *
	 * @return array{registered: int, blocked: int}
	 */
	public function register(ToolRegistry $registry, array $extensionTools): array
	{
		$registered = 0;
		$blocked    = 0;

		foreach ($extensionTools as $extensionId => $tools) {
			foreach ($tools as $tool) {
				if (!$tool instanceof McpToolDefinition) {
					continue;
				}

				if ($registry->get($tool->name) !== null) {
					$this->logger->warning(sprintf(
						"MCP tool '%s' from extension '%s' blocked: name already registered (core or another extension).",
						$tool->name,
						$extensionId,
					));
					$blocked++;
					continue;
				}

				$registry->register($tool);
				$registered++;
			}
		}

		return ['registered' => $registered, 'blocked' => $blocked];
	}
}
