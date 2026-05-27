<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Admin;

use Mcp\Schema\ToolAnnotations;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Support\Config;
use TotalCMS\Support\Version;

/**
 * Admin MCP tool: returns runtime info about the T3 instance.
 *
 * Acts as the AI agent's "where am I?" tool — surfacing site identity
 * (name + URL), runtime (version, PHP, edition, environment), localization
 * (timezone, locale), and installed extensions. Useful as a smoke test
 * ("is the agent connected to the right site?") and as the canonical example
 * of an admin-only tool that requires an API key.
 */
readonly class SiteInfoTool
{
	public function __construct(
		private EditionFeatureService $editionFeatures,
		private ExtensionManager $extensionManager,
		private Config $config,
	) {
	}

	public function register(ToolRegistry $registry): void
	{
		$registry->register(new McpToolDefinition(
			name: 'get_site_info',
			description: 'Returns runtime information about this Total CMS instance: site name + URL, version, edition, environment, PHP version, timezone, locale, and installed extensions. Useful for verifying the agent is connected to the expected site.',
			access: 'admin',
			handler: $this->handler(...),
			inputSchema: [
				'type'                 => 'object',
				'properties'           => new \stdClass(),
				'additionalProperties' => false,
			],
			annotations: new ToolAnnotations(
				title: 'Site Info',
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
			'site_name'   => $this->config->displayName(),
			'site_url'    => $this->config->url,
			'environment' => $this->config->env,
			'version'     => Version::number(),
			'edition'     => $this->editionFeatures->getEdition()->value,
			'php'         => PHP_VERSION,
			'timezone'    => $this->config->timezone,
			'locale'      => $this->config->locale,
			'extensions'  => $extensions,
		];
	}
}
