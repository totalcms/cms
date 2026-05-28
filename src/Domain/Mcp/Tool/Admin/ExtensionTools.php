<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Admin;

use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;

/**
 * Admin tool: `list_extensions` — every installed extension, enabled or not.
 *
 * Thin wrapper around `ExtensionManager::listExtensions()`. Each entry is the
 * same shape the admin extensions page renders — id, name, version, enabled
 * flag, capabilities array. Useful for AI agents doing site setup or
 * troubleshooting ("does this site have the deck-tools extension installed?").
 */
readonly class ExtensionTools
{
	public function __construct(
		private ExtensionManager $extensionManager,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'list_extensions',
			description: 'List every installed extension on this site, enabled and disabled. Each entry includes id, name, version, enabled flag, and capabilities (twigFunction, route, command, etc.). Use to discover what third-party tools / Twig functions are available, or to confirm an extension is enabled before depending on it.',
			access: 'admin',
			handler: $this->handler(...),
			inputSchema: [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			annotations: new ToolAnnotations(
				title: 'List Extensions',
				readOnlyHint: true,
				destructiveHint: false,
				idempotentHint: true,
				openWorldHint: false,
			),
		));
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handler(): array
	{
		$extensions = $this->extensionManager->listExtensions();

		return [
			'extensions' => $extensions,
			'total'      => count($extensions),
		];
	}
}
