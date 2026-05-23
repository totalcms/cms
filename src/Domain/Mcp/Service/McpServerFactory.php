<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry;
use TotalCMS\Domain\Mcp\Tool\Service\SchemaToolRegistrar;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Support\Config;
use TotalCMS\Support\Version;

/**
 * Builds a configured mcp/sdk Server for a given persona.
 *
 * The factory is the single integration point between T3 and the MCP SDK. It
 * filters the ToolRegistry and ResourceRegistry by persona so the tools/list
 * and resources/list responses never leak admin-only surface to an anonymous
 * caller, and wires the SDK to T3's logger, session storage, and server
 * metadata.
 *
 * A new Server is built per request because the registered tool/resource
 * surface depends on the resolved persona. The construction is cheap —
 * registries are already populated at container build time.
 *
 * Session storage and logger are constructed in the container and injected so
 * the factory stays narrow: given deps, build a configured Server.
 */
readonly class McpServerFactory
{
	private const PROTOCOL_VERSION = '2025-06-18';

	public function __construct(
		private ToolRegistry $toolRegistry,
		private ResourceRegistry $resourceRegistry,
		private SubscriptionManagerInterface $subscriptionManager,
		private Config $config,
		private SessionStoreInterface $sessionStore,
		private LoggerInterface $logger,
		private SchemaToolRegistrar $schemaToolRegistrar,
	) {
	}

	public function build(McpPersona $persona): Server
	{
		$readOnlyDefault = new ToolAnnotations(readOnlyHint: true);

		$builder = Server::builder()
			->setServerInfo(
				name: $this->config->displayName(),
				version: Version::number(),
				description: 'Total CMS site exposed as an MCP server.',
			)
			->setInstructions(
				'Total CMS site exposed via the Model Context Protocol. '
				. 'Discovery: list_collections returns collections with their filterable fields. '
				. 'Tools: query_collection / get_object / search_collection for collection content; '
				. 'admin tools (schema_*, template_*, get_site_info, clear_cache) require an API key. '
				. 'Resources: tcms://{collection}/ for collection summaries, tcms://{collection}/{id} for objects — '
				. 'reachable via resources/read or the get_resource tool. '
				. 'Drafts are hidden from anonymous callers. '
				. 'Tool descriptions describe their inputs and outputs.'
			)
			->setSession($this->sessionStore)
			->setLogger($this->logger);

		// Resource subscriptions: when enabled, swap in T3's manager so subscribe/
		// unsubscribe also writes to the reverse URI→sessionIds index that the
		// McpResourceSubscriptionListener queries on object.* events. When the
		// kill switch is off the SDK falls back to its per-session-only default
		// — clients can still call resources/subscribe but no cross-session
		// push happens.
		if (($this->config->mcp['subscriptionsEnabled'] ?? true) !== false) {
			$builder->setResourceSubscriptionManager($this->subscriptionManager);
		}

		// Auto-register schema-defined tools from all collection meta files.
		// Called once per request lifecycle. The registry is request-scoped so
		// previous-request schema tools don't carry over; if build() is ever
		// called twice on the same factory instance the second pass would
		// see the first pass's tools as collisions and skip them — don't do that.
		$this->schemaToolRegistrar->register($this->toolRegistry);

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

			// Per-tool annotations win over the default. Destructive admin tools
			// (delete_schema, clear_cache) MUST opt out of the read-only default
			// — mandatory before Anthropic Directory submission.
			$annotations = $tool->annotations ?? $readOnlyDefault;

			$builder->addTool(
				handler: $tool->handler,
				name: $prefix . $tool->name,
				description: $description,
				annotations: $annotations,
				inputSchema: $tool->inputSchema,
				outputSchema: $tool->outputSchema,
			);
		}

		foreach ($this->resourceRegistry->forPersona($persona) as $resource) {
			$builder->addResource(
				handler: $resource->handler,
				uri: $resource->uri,
				name: $resource->name,
				description: $resource->description,
				mimeType: $resource->mimeType,
			);
		}

		foreach ($this->resourceRegistry->templatesForPersona($persona) as $template) {
			$builder->addResourceTemplate(
				handler: $template->handler,
				uriTemplate: $template->uriTemplate,
				name: $template->name,
				description: $template->description,
				mimeType: $template->mimeType,
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
