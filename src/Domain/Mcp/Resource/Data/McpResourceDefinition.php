<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resource\Data;

use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;

/**
 * Value object describing a single MCP resource (concrete `tcms://...` URI).
 *
 * Mirrors McpToolDefinition's role for tools: registered in ResourceRegistry,
 * persona-filtered, and handed to the SDK Server::builder() as addResource(...)
 * calls inside McpServerFactory. The handler is invoked when the SDK receives
 * resources/read for this URI; it must return an array shaped as the SDK's
 * ResourceContent (`{contents: [{uri, mimeType, text}]}`).
 */
readonly class McpResourceDefinition
{
	/**
	 * @param string      $uri         Concrete URI (e.g., 'tcms://blog/')
	 * @param string      $name        Human-readable name for resources/list output
	 * @param string      $description Description for AI agents
	 * @param string      $mimeType    Content type the handler will produce
	 * @param string      $access      'admin', 'public', or 'authenticated' (OAuth Bearer with mcp:* scope)
	 * @param \Closure     $handler     Invoked with no args; returns ResourceContent array
	 * @param string|null $collectionId Collection id this resource enumerates, when it's a
	 *                                  collection-scoped resource (Task 10). Set by
	 *                                  CollectionResourceRegistrar; left null for resources
	 *                                  that aren't collection-scoped (e.g. data views), whose
	 *                                  visibility is governed by $access alone. When set, an
	 *                                  AUTHENTICATED caller must additionally hold `read` on
	 *                                  $collectionId per their resolved UserAuthority to see
	 *                                  this resource in resources/list — mirrors the call-time
	 *                                  gate CollectionResource::read() enforces.
	 */
	public function __construct(
		public string $uri,
		public string $name,
		public string $description,
		public string $mimeType,
		public string $access,
		public \Closure $handler,
		public ?string $collectionId = null,
	) {
	}

	public function isVisibleTo(McpPersona $persona, ?UserAuthority $authority = null): bool
	{
		return match ($persona) {
			McpPersona::ADMIN         => true,
			McpPersona::AUTHENTICATED => ($this->access === 'public' || $this->access === 'authenticated')
				&& $this->authorizedFor($authority),
			McpPersona::PUBLIC_       => $this->access === 'public',
		};
	}

	/**
	 * Enumeration counterpart to CollectionResource::read()'s call-time
	 * group-authority gate. Resources with no $collectionId (data views) are
	 * unaffected — their visibility stays purely $access-driven.
	 */
	private function authorizedFor(?UserAuthority $authority): bool
	{
		if ($this->collectionId === null) {
			return true;
		}

		return $authority instanceof UserAuthority && $authority->canCollection('read', $this->collectionId);
	}
}
