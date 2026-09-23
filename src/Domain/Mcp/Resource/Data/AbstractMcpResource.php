<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Resource\Data;

use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Mcp\Auth\Data\McpAccessLevel;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;

/**
 * What a concrete resource and a resource template have in common: the
 * descriptive fields, the handler, and the visibility rule that decides
 * whether a persona sees it in resources/list (or templates/list).
 */
abstract readonly class AbstractMcpResource
{
	/**
	 * @param string      $name         Human-readable name
	 * @param string      $description  Description for AI agents
	 * @param string      $mimeType     Content type the handler will produce
	 * @param string      $access       'admin', 'public', or 'authenticated' (OAuth Bearer with mcp:* scope)
	 * @param \Closure    $handler      Invoked by the SDK on resources/read
	 * @param string|null $collectionId Collection this resource enumerates, when it is collection-scoped.
	 *                                  Set by CollectionResourceRegistrar; null for resources whose
	 *                                  visibility is governed by $access alone (data views). When set,
	 *                                  an AUTHENTICATED caller must also hold `read` on the collection
	 *                                  per their UserAuthority — unless $access is 'public', which stays
	 *                                  visible to every AUTHENTICATED caller regardless of group grants.
	 *                                  Mirrors the call-time gate CollectionResource::read() enforces.
	 */
	public function __construct(
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
		if (!McpAccessLevel::fromString($this->access)->allows($persona)) {
			return false;
		}

		return $persona !== McpPersona::AUTHENTICATED || $this->authorizedFor($authority);
	}

	/**
	 * Enumeration counterpart to CollectionResource::read()'s call-time
	 * group-authority gate — the same rule (PersonaContext::canReadCollection()),
	 * expressed against $access rather than a fresh collection lookup because
	 * $access is already the collection's resolved mcp.access. The public
	 * carve-out matters: without it an AUTHENTICATED caller without a group
	 * grant was hidden from a public collection's resource that an ANONYMOUS
	 * caller could read freely (a privilege inversion).
	 */
	private function authorizedFor(?UserAuthority $authority): bool
	{
		if ($this->collectionId === null || $this->access === 'public') {
			return true;
		}

		return $authority instanceof UserAuthority && $authority->canCollection('read', $this->collectionId);
	}
}
