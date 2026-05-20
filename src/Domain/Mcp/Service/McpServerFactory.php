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
				name: $this->serverName(),
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

		foreach ($this->toolRegistry->forPersona($persona) as $tool) {
			$builder->addTool(
				handler: $tool->handler,
				name: $tool->name,
				description: $tool->description,
				annotations: $readOnly,
				inputSchema: $tool->inputSchema,
			);
		}

		return $builder->build();
	}

	public function protocolVersion(): string
	{
		return self::PROTOCOL_VERSION;
	}

	private function serverName(): string
	{
		$dashboardTitle = (string)(($this->config->dashboard['title'] ?? '') ?: '');
		if ($dashboardTitle !== '' && $dashboardTitle !== 'Total CMS Admin') {
			return $dashboardTitle;
		}

		return $this->config->domain;
	}
}
