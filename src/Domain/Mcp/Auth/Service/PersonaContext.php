<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Auth\Service;

use Mcp\Exception\ToolCallException;
use TotalCMS\Domain\ApiKey\Data\ApiKeyData;
use TotalCMS\Domain\Auth\Data\Decision;
use TotalCMS\Domain\Auth\Data\Operation;
use TotalCMS\Domain\Auth\Data\ReadScope;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Mcp\Auth\Data\McpCallerKind;
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
 * Instead McpRequestAuthorizer writes the persona here after auth, and tools
 * inject this service to read it during dispatch. The container treats this
 * as a singleton per request — same lifetime as the Slim app instance.
 *
 * For OAuth Bearer requests McpRequestAuthorizer also stores the resolved scopes
 * via setScopes() so OAuthScopeEvaluator can read them during tool dispatch
 * without needing access to the PSR-7 request directly.
 *
 * For OAuth Bearer requests McpRequestAuthorizer also resolves and stores the
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

	private ?ApiKeyData $limitedKey = null;

	private McpCallerKind $callerKind = McpCallerKind::Anonymous;

	public function __construct(
		private readonly CollectionFetcher $collectionFetcher,
		private readonly McpSchemaResolver $schemaResolver,
		private readonly AccessGuard $guard,
		private readonly ActorContext $actors,
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
	 * Store the OAuth scopes for this request. Called by McpRequestAuthorizer
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
	 * McpRequestAuthorizer after Bearer authentication resolves an
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
	 * Store the OAuth client id for this request. Called by McpRequestAuthorizer
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
	 * McpRequestAuthorizer alongside setAuthority(); used only for oauth-activity
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
	 * How this request's caller proved itself. Set by McpRequestAuthorizer
	 * next to the persona; defaults to anonymous so a context nobody resolved
	 * (tests, CLI) behaves exactly as before the kind existed.
	 */
	public function setCallerKind(McpCallerKind $kind): void
	{
		$this->callerKind = $kind;
	}

	public function callerKind(): McpCallerKind
	{
		return $this->callerKind;
	}

	/**
	 * The API key of this request when it is limited to some collections or
	 * views; null for every other caller, an All endpoints key included. Set
	 * on every request so one caller's key never outlives its request.
	 */
	public function setLimitedKey(?ApiKeyData $apiKey): void
	{
		$this->limitedKey = $apiKey;
	}

	public function getLimitedKey(): ?ApiKeyData
	{
		return $this->limitedKey;
	}

	/**
	 * A browser session (WebMCP) is read-only whatever its persona: the server
	 * built for it registers no tool that is not readOnlyHint: true.
	 */
	public function isReadOnly(): bool
	{
		return $this->callerKind === McpCallerKind::Session;
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
	 *     `read` on $collection. On an owned collection that read is scoped to
	 *     their own objects (plus published ones when exposed publicly): every
	 *     read tool filters through {@see readScope()}.
	 *
	 * $collectionData is an optional pre-fetched CollectionData for callers
	 * that already have one in hand (loops over CollectionRepository::
	 * listAllCollections(), CollectionResource::read(), SavedQueryTool) —
	 * avoids a redundant CollectionFetcher round-trip. When omitted, this
	 * fetches it itself (CollectionFetcher request-caches, so a second lookup
	 * for the same id in the same request is cheap).
	 *
	 * Which objects the caller then sees is {@see readScope()}'s answer:
	 * reading a collection does not mean reading every object in it.
	 */
	public function canReadCollection(string $collection, ?CollectionData $collectionData = null): bool
	{
		if ($this->isResolved() && $this->current() === McpPersona::ADMIN) {
			return true;
		}

		if ($this->isCollectionExposedPublic($collection, $collectionData)) {
			return true;
		}

		return $this->hasReadGrant($collection);
	}

	/**
	 * Whether $collection's `mcp.access` is `'public'` — exposure ALONE,
	 * independent of any authority/grant. Public, not just an internal
	 * helper of canReadCollection(): McpServerFactory::guardHandler() (Task
	 * 10b fix round 1, finding #2) needs this exact question answered BEFORE
	 * it decides whether to even run the scope/consent check for an
	 * AUTHENTICATED objects+read call — a token missing `cms:read` must
	 * still be admitted on a public collection (an anonymous caller already
	 * reads it with zero consent), the same "authenticating must never
	 * subtract reach" principle canReadCollection() already applies one
	 * layer down. Deliberately distinct from calling canReadCollection()
	 * itself for that purpose: canReadCollection() ALSO returns true when
	 * the caller's authority grants read, which must NOT bypass the scope
	 * layer — a real group grant without consent (scope) still needs the
	 * consent check; only genuine public exposure skips it.
	 *
	 * $collectionData mirrors canReadCollection()'s optional pre-fetched
	 * argument — same rationale (avoid a redundant CollectionFetcher call
	 * for callers that already have one in hand).
	 */
	public function isCollectionExposedPublic(string $collection, ?CollectionData $collectionData = null): bool
	{
		$data = $collectionData ?? $this->collectionFetcher->fetchCollection($collection);

		return $data instanceof CollectionData
			&& $this->schemaResolver->forCollection($data)['access'] === 'public';
	}

	/**
	 * Which of $collection's objects the current caller may read: everything
	 * for ADMIN, otherwise the guard's {@see ReadScope} for the request's
	 * actor, with others' published objects included when the collection is
	 * exposed publicly. Every read tool filters through this, so a member on
	 * an owned collection sees their own objects, drafts included, and never
	 * another member's draft.
	 */
	public function readScope(string $collection, ?CollectionData $collectionData = null): ReadScope
	{
		if ($this->isResolved() && $this->current() === McpPersona::ADMIN) {
			return ReadScope::all();
		}

		return $this->guard->readScope($this->actors->current(), $collection, $this->isCollectionExposedPublic($collection, $collectionData));
	}

	/**
	 * Whether the current caller may update object $id of $collection: their
	 * own object on an owned collection, any object elsewhere. A caller that
	 * may not is told the object is not found, the way REST answers.
	 */
	public function mayUpdateObject(string $collection, string $id): bool
	{
		return $this->guard->ownerDecision($this->actors->current(), $collection, Operation::Update, $id) === Decision::Allow;
	}

	/**
	 * The fields of $collection the current caller cannot set: the protected,
	 * forced-value and owner fields the site fills in itself. Empty for ADMIN
	 * and trusted actors, who may set them.
	 *
	 * @return list<string>
	 */
	public function fieldsSetBySite(string $collection): array
	{
		if (($this->isResolved() && $this->current() === McpPersona::ADMIN) || $this->actors->current()->isTrusted()) {
			return [];
		}

		return $this->guard->protectedFieldsFor($collection);
	}

	private function hasReadGrant(string $collection): bool
	{
		return $this->authority instanceof UserAuthority
			&& $this->authority->canCollection('read', $collection);
	}

	// ─── Shared by every read tool ─────────────────────────────────────────

	/**
	 * The collection a tool was asked about, when the current caller may
	 * reach it at all: it must exist, and its `mcp.access` must admit the
	 * persona. Either failure is a ToolCallException that points the agent
	 * at list_collections. Group-level read authority is a separate,
	 * later check ({@see canReadCollection()}) because most tools want it to
	 * fail as an opaque "not found".
	 *
	 * @param string $hint what the caller can list with list_collections: "query", "describe", "access"
	 *
	 * @throws ToolCallException
	 */
	public function exposedCollection(string $collection, string $hint = 'query'): CollectionData
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		if (!$collectionData instanceof CollectionData) {
			throw new ToolCallException(sprintf(
				'Collection "%s" not found. Use list_collections to see available collections.',
				$collection,
			));
		}

		if (!$this->schemaResolver->isAccessibleTo($collectionData, $this->current()->value, $this->limitedKey instanceof ApiKeyData)) {
			throw new ToolCallException(sprintf(
				'Collection "%s" is not available to the current caller. Use list_collections to see what you can %s.',
				$collection,
				$hint,
			));
		}

		return $collectionData;
	}

	/**
	 * The collections the current caller may both reach and read: exposed
	 * at their `mcp.access` and granted by the caller's groups. What the
	 * cross-collection tools iterate.
	 *
	 * @param iterable<CollectionData> $collections
	 *
	 * @return list<CollectionData>
	 */
	public function visibleCollections(iterable $collections): array
	{
		$persona = $this->current()->value;
		$visible = [];

		foreach ($collections as $collection) {
			if ($this->schemaResolver->isAccessibleTo($collection, $persona, $this->limitedKey instanceof ApiKeyData) && $this->canReadCollection($collection->id, $collection)) {
				$visible[] = $collection;
			}
		}

		return $visible;
	}

	/**
	 * A tool description with the collection catalog appended — only the
	 * collections $persona's groups grant read on, the same rule the tool's
	 * handler enforces, so the catalog never advertises what a call would
	 * then refuse.
	 */
	public function catalogDescription(McpPersona $persona, string $baseDescription): string
	{
		$catalog = $this->schemaResolver->renderCatalog(
			$persona,
			McpSchemaResolver::DEFAULT_CATALOG_CAP,
			fn (CollectionData $c): bool => $this->canReadCollection($c->id, $c),
			$this->limitedKey instanceof ApiKeyData,
		);

		return $catalog === '' ? $baseDescription : $baseDescription . "\n\n" . $catalog;
	}
}
