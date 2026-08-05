<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Auth\Service;

use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;

/**
 * Request-scoped store of the resolved MCP persona.
 *
 * The mcp/sdk reflects on each tool handler's signature to map JSON arguments
 * by parameter name, so we can't pass persona via the handler args without
 * either breaking that reflection (variadic params aren't supported) or
 * polluting every tool's input schema with a `_persona` field.
 *
 * Instead McpEndpointAction writes the persona here after auth, and tools
 * inject this service to read it during dispatch. The container treats this
 * as a singleton per request — same lifetime as the Slim app instance.
 *
 * For OAuth Bearer requests McpEndpointAction also stores the resolved scopes
 * via setScopes() so OAuthScopeEvaluator can read them during tool dispatch
 * without needing access to the PSR-7 request directly.
 *
 * For OAuth Bearer requests McpEndpointAction also resolves and stores the
 * caller's UserAuthority via setAuthority() so ToolRegistry::forPersona()
 * (via McpServerFactory) can filter requirement-gated tools by the caller's
 * actual access-group grants, and so Task 7's call-time guard can re-check
 * them per invocation. Null for non-Bearer requests (API key / no-auth).
 */
class PersonaContext
{
	private ?McpPersona $persona = null;

	/** @var list<string> */
	private array $scopes = [];

	private ?UserAuthority $authority = null;

	private string $clientId = '';

	private string $userId = '';

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly McpSchemaResolver $schemaResolver,
	) {
	}

	public function set(McpPersona $persona): void
	{
		$this->persona = $persona;
	}

	public function current(): McpPersona
	{
		if (!$this->persona instanceof McpPersona) {
			throw new \LogicException('Persona has not been resolved for this request.');
		}

		return $this->persona;
	}

	public function isResolved(): bool
	{
		return $this->persona instanceof McpPersona;
	}

	/**
	 * Store the OAuth scopes for this request. Called by McpEndpointAction
	 * after Bearer authentication resolves an AUTHENTICATED persona.
	 *
	 * @param list<string> $scopes
	 */
	public function setScopes(array $scopes): void
	{
		$this->scopes = $scopes;
	}

	/**
	 * Return the OAuth scopes for this request. Empty for non-Bearer requests.
	 *
	 * @return list<string>
	 */
	public function getScopes(): array
	{
		return $this->scopes;
	}

	/**
	 * Store the resolved UserAuthority for this request. Called by
	 * McpEndpointAction after Bearer authentication resolves an
	 * AUTHENTICATED persona; left null for non-Bearer requests.
	 */
	public function setAuthority(?UserAuthority $authority): void
	{
		$this->authority = $authority;
	}

	/**
	 * Return the UserAuthority for this request, or null when none was
	 * resolved (non-Bearer requests, or a Bearer request whose persona
	 * resolution never reached the authority lookup).
	 */
	public function getAuthority(): ?UserAuthority
	{
		return $this->authority;
	}

	/**
	 * Store the OAuth client id for this request. Called by McpEndpointAction
	 * alongside setScopes()/setAuthority() so Task 7's call-time guard can
	 * attribute oauth-activity log denials (scopeRejected/groupRejected) to
	 * the calling client, mirroring BaseAccessMiddleware's REST equivalent.
	 * Empty for non-Bearer requests.
	 */
	public function setClientId(string $clientId): void
	{
		$this->clientId = $clientId;
	}

	/**
	 * Return the OAuth client id for this request, or '' when none was set.
	 */
	public function getClientId(): string
	{
		return $this->clientId;
	}

	/**
	 * Store the resolved OAuth subject (user id) for this request. Called by
	 * McpEndpointAction alongside setAuthority(); used only for oauth-activity
	 * log attribution in Task 7's call-time guard, not for authorization
	 * decisions (UserAuthority already carries the resolved group grants).
	 * Empty for non-Bearer requests.
	 */
	public function setUserId(string $userId): void
	{
		$this->userId = $userId;
	}

	/**
	 * Return the resolved OAuth subject (user id) for this request, or ''
	 * when none was set.
	 */
	public function getUserId(): string
	{
		return $this->userId;
	}

	/**
	 * Whether the current caller may see draft objects in $collection.
	 *
	 * Single home for the draft-visibility rule so every content tool
	 * (query_collection, get_object, search_collection(s), fetch, and
	 * schema-defined saved-query tools) agrees on it:
	 *
	 *   - ADMIN always can — unrestricted, same as every other admin check.
	 *   - An OAuth caller with a resolved UserAuthority can when their access
	 *     groups grant `read` on $collection — draft visibility rides along
	 *     with the same grant that lets them read the collection's published
	 *     content at all, rather than being a separate permission a group
	 *     author has to remember to set.
	 *   - Everyone else — public/anonymous callers, or a Bearer request whose
	 *     authority never resolved — can never see drafts. Unchanged from the
	 *     legacy "persona !== ADMIN hides drafts" behavior for those callers.
	 */
	public function canReadDrafts(string $collection): bool
	{
		if ($this->isResolved() && $this->current() === McpPersona::ADMIN) {
			return true;
		}

		return $this->hasReadGrant($collection);
	}

	/**
	 * Whether the current caller may read $collection's PUBLISHED content at
	 * all — the "objects"+"read" ToolRequirement rule (Task 10b), and the
	 * single home for the public-collection carve-out that closes the
	 * privilege-inversion bug the Task 10 review found: an AUTHENTICATED
	 * caller whose groups don't grant $collection must NOT be denied where an
	 * ANONYMOUS caller would succeed.
	 *
	 *   - ADMIN always can.
	 *   - Anyone (including PUBLIC_/anonymous) can when $collection's
	 *     mcp.access is 'public' — matches what an anonymous caller already
	 *     gets via QueryCollectionTool's/GetObjectTool's exposure check;
	 *     authenticating must never SUBTRACT reach.
	 *   - Otherwise, only an OAuth caller whose resolved UserAuthority grants
	 *     `read` on $collection.
	 *
	 * $collectionData is an optional pre-fetched CollectionData for callers
	 * that already have one in hand (loops over CollectionRepository::
	 * listAllCollections(), CollectionResource::read(), SavedQueryTool) —
	 * avoids a redundant CollectionFetcher round-trip. When omitted, this
	 * fetches it itself (CollectionFetcher request-caches, so a second lookup
	 * for the same id in the same request is cheap).
	 *
	 * Deliberately NOT reused by canReadDrafts(): drafts always require the
	 * real access-group GRANT, never mere public exposure — see canReadDrafts()'s
	 * own docblock. Both methods share the same hasReadGrant() building block
	 * so the "does this caller's authority grant read" sub-check can never
	 * drift between the two rules.
	 */
	public function canReadCollection(string $collection, ?CollectionData $collectionData = null): bool
	{
		if ($this->isResolved() && $this->current() === McpPersona::ADMIN) {
			return true;
		}

		if ($this->isCollectionPublic($collection, $collectionData)) {
			return true;
		}

		return $this->hasReadGrant($collection);
	}

	private function hasReadGrant(string $collection): bool
	{
		return $this->authority instanceof UserAuthority
			&& $this->authority->canCollection('read', $collection);
	}

	private function isCollectionPublic(string $collection, ?CollectionData $collectionData): bool
	{
		$data = $collectionData ?? $this->collectionFetcher->fetchCollection($collection);

		return $data instanceof CollectionData
			&& $this->schemaResolver->forCollection($data)['access'] === 'public';
	}
}
