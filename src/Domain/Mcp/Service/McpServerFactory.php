<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Support\Config;
use TotalCMS\Support\Version;

/**
 * Builds a configured mcp/sdk Server for a given persona.
 *
 * The factory is the single integration point between T3 and the MCP SDK. It
 * filters the ToolRegistry by persona so the tools/list response never leaks
 * admin-only tools to an anonymous caller, and wires the SDK to T3's logger,
 * session storage, and server metadata.
 *
 * A new Server is built per request because the registered tool surface
 * depends on the resolved persona. The construction is cheap — registry is
 * already populated at container build time.
 *
 * Session storage and logger are constructed in the container and injected so
 * the factory stays narrow: given deps, build a configured Server.
 */
readonly class McpServerFactory
{
	private const PROTOCOL_VERSION = '2025-06-18';

	public function __construct(
		private ToolRegistry $toolRegistry,
		private Config $config,
		private SessionStoreInterface $sessionStore,
		private LoggerInterface $logger,
	) {
	}

	public function build(McpPersona $persona): Server
	{
		$readOnly = new ToolAnnotations(readOnlyHint: true);

		$builder = Server::builder()
			->setServerInfo(
				name: $this->config->displayName(),
				version: Version::number(),
				description: 'Total CMS site exposed as an MCP server.',
			)
			->setInstructions(
				'This is a Total CMS site exposed via the Model Context Protocol. '
				. 'Use the available tools to discover and query collection content. '
				. 'Admin tools (schema_*, template_*, site_info, cache_clear) require an API key; '
				. 'public tools require no authentication. Tool descriptions describe their inputs and outputs.'
			)
			->setSession($this->sessionStore)
			->setLogger($this->logger);

		$prefix = $this->toolNamePrefix();
		foreach ($this->toolRegistry->forPersona($persona) as $tool) {
			// Persona-aware tools (Phase 1 content tools) expose a builder that
			// renders a per-persona description — e.g., the field catalog must
			// only list collections the caller can actually see. Static-string
			// tools (Phase 0 SiteInfoTool, admin tools) leave the builder unset
			// and use $tool->description verbatim.
			$description = $tool->descriptionBuilder !== null
				? ($tool->descriptionBuilder)($persona)
				: $tool->description;

			$builder->addTool(
				handler: $tool->handler,
				name: $prefix . $tool->name,
				description: $description,
				annotations: $readOnly,
				inputSchema: $tool->inputSchema,
			);
		}

		return $builder->build();
	}

	/**
	 * Resolves the optional tool-name prefix from config. Operators running
	 * multiple T3 sites in one AI agent can set `mcp.toolPrefix` to namespace
	 * each site's tools (e.g. `bistro` → `bistro_list_collections`). Returns
	 * the prefix with a trailing underscore, or empty string if unset.
	 *
	 * Validates against the same snake_case regex as the settings schema —
	 * invalid values silently fall back to empty so a misconfigured setting
	 * can't break the endpoint.
	 */
	private function toolNamePrefix(): string
	{
		$prefix = trim((string)($this->config->mcp['toolPrefix'] ?? ''));
		if ($prefix === '') {
			return '';
		}

		if (!preg_match('/^[a-z][a-z0-9_]{0,23}$/', $prefix)) {
			return '';
		}

		return $prefix . '_';
	}

	public function protocolVersion(): string
	{
		return self::PROTOCOL_VERSION;
	}
}
