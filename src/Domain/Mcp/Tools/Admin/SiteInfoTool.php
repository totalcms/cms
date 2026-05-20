<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tools\Admin;

use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;
use TotalCMS\Support\Version;

/**
 * Admin MCP tool: returns runtime info about the T3 instance.
 *
 * Useful as a smoke test ("is the agent connected to the right site?") and as
 * the canonical example of an admin-only tool that requires an API key.
 */
readonly class SiteInfoTool
{
	public function __construct(
		private EditionFeatureService $editionFeatures,
		private ExtensionManager $extensionManager,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'site_info',
			description: 'Returns runtime information about this Total CMS instance: version, edition, PHP version, and installed extensions. Useful for verifying the agent is connected to the expected site.',
			access: 'admin',
			handler: $this->handler(...),
			inputSchema: [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
		));
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handler(): array
	{
		$extensions = [];
		foreach ($this->extensionManager->listExtensions() as $extension) {
			$extensions[] = [
				'id'      => (string)($extension['id'] ?? ''),
				'name'    => (string)($extension['name'] ?? ''),
				'version' => (string)($extension['version'] ?? ''),
				'enabled' => (bool)($extension['enabled'] ?? false),
			];
		}

		return [
			't3_version' => Version::number(),
			'edition'    => $this->editionFeatures->getEdition()->value,
			'php'        => PHP_VERSION,
			'extensions' => $extensions,
		];
	}
}
