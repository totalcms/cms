<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Data\ReadScope;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessGuard;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Data\OwnerScope;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\OAuth\Data\OAuthClientData;
use TotalCMS\Domain\OAuth\Data\OAuthGrantData;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\OAuth\Repository\OAuthClientRepository;
use TotalCMS\Domain\OAuth\Repository\OAuthGrantRepository;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Support\Config;

/**
 * OAuth Clients (static/dynamic buckets with active-grant counts, or the
 * new-client form) and OAuth Grants (every grant joined to its client with
 * expiry metadata and effective reach).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") The count includes
 * type-only references (OwnerScope in an instanceof check) that add no
 * dependency; the page's real collaborators are its constructor arguments.
 */
final readonly class OAuthPageData implements UtilsPageData
{
	/**
	 * Maximum number of collection ids shown per read/write list on the
	 * OAuth Grants "Effective reach" row before the remainder collapses
	 * into a "+N more" note.
	 */
	private const EFFECTIVE_REACH_BADGE_CAP = 6;

	public function __construct(
		private OAuthClientRepository $oauthClientRepository,
		private OAuthGrantRepository $oauthGrantRepository,
		private OAuthScopeRegistry $oauthScopeRegistry,
		private CollectionLister $collectionLister,
		private AccessControlService $accessControlService,
		private McpSchemaResolver $mcpSchemaResolver,
		private Config $config,
		private AccessGuard $guard,
	) {
	}

	public function build(ServerRequestInterface $request, string $page, string $action): array
	{
		$clients = null;
		$form    = null;
		$grants  = null;

		if ($page === 'oauth-clients' && $action !== 'new') {
			$clients = $this->prepareClientsForView();
		}
		if ($page === 'oauth-clients' && $action === 'new') {
			$form = ['scopes' => $this->oauthScopeRegistry->all()];
		}
		if ($page === 'oauth-grants') {
			$grants = $this->prepareGrantsForView();
		}

		return [
			'oauthClients'     => $clients,
			'oauthClientsForm' => $form,
			'oauthGrants'      => $grants,
		];
	}

	/**
	 * Build the OAuth clients view data: two bucketed lists (static / dynamic)
	 * with an active-grant count per client.
	 *
	 * @return array<string,mixed>
	 */
	private function prepareClientsForView(): array
	{
		$allClients = $this->oauthClientRepository->all();

		$static  = [];
		$dynamic = [];
		$now     = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

		foreach ($allClients as $client) {
			// The card advertises "active grants" — expired grants (lapsed
			// refresh windows) don't count, they only await oauth:gc.
			$grantCount = count(array_filter(
				$this->oauthGrantRepository->findByClientId($client->id),
				static function (OAuthGrantData $grant) use ($now): bool {
					try {
						return new \DateTimeImmutable($grant->expiresAt, new \DateTimeZone('UTC')) > $now;
					} catch (\Exception) {
						return false;
					}
				},
			));
			$row = ['client' => $client, 'grantCount' => $grantCount];

			if ($client->isDynamic) {
				$dynamic[] = $row;
			} else {
				$static[] = $row;
			}
		}

		return [
			'static'  => $static,
			'dynamic' => $dynamic,
		];
	}

	/**
	 * Build the OAuth grants view data: every grant joined with its client
	 * name, expiry metadata, and effective reach — sorted most-recent first.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function prepareGrantsForView(): array
	{
		$now    = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
		$grants = $this->oauthGrantRepository->all();

		$authCollection = (string)$this->config->auth['collection'];
		$allCollections = $this->collectionLister->listAllCollections();

		/** @var array<string,array{exists: bool, authority: UserAuthority}> $authorityCache */
		$authorityCache = [];

		$rows = [];

		foreach ($grants as $grant) {
			$client     = $this->oauthClientRepository->find($grant->clientId);
			$clientName = $client instanceof OAuthClientData ? $client->name : $grant->clientId;

			$expiresAt       = null;
			$isExpired       = false;
			$daysUntilExpiry = null;

			if ($grant->expiresAt !== '') {
				try {
					$expiry          = new \DateTimeImmutable($grant->expiresAt, new \DateTimeZone('UTC'));
					$expiresAt       = $expiry;
					$isExpired       = $expiry < $now;
					$diff            = $now->diff($expiry);
					$daysUntilExpiry = $isExpired ? -(int)$diff->days : (int)$diff->days;
				} catch (\Exception) {
					// leave null
				}
			}

			$rows[] = [
				'grant'           => $grant,
				'clientName'      => $clientName,
				'clientId'        => $grant->clientId,
				'isExpired'       => $isExpired,
				'expiresAt'       => $expiresAt,
				'daysUntilExpiry' => $daysUntilExpiry,
				'effectiveReach'  => $this->effectiveReachForGrant($grant, $authCollection, $allCollections, $authorityCache),
			];
		}

		// Sort by issuedAt descending (most recent first)
		usort($rows, static function (array $a, array $b): int {
			/** @var OAuthGrantData $ga */
			$ga = $a['grant'];
			/** @var OAuthGrantData $gb */
			$gb = $b['grant'];

			return strcmp($gb->issuedAt, $ga->issuedAt);
		});

		return $rows;
	}

	/**
	 * Compute what a grant can actually touch once its user's access groups
	 * apply — the "Effective reach" row on the OAuth Grants admin page. A
	 * raw scope badge like cms:admin can overstate reach: the real ceiling
	 * is consented scopes ∩ access-group permissions ∩ collection mcp.access
	 * exposure, narrowed to the member's own objects on an owned collection.
	 * Exposure is checked as PersonaContext::exposedCollection() does, and
	 * reads are the AccessGuard's own readScope(), as the read tools apply it.
	 * `own` lists the collections where that reach is the member's own
	 * objects.
	 *
	 * $authorityCache is keyed by the composite OAuthUserRef string
	 * ("collection:id") and shared across every grant in the request — most
	 * sites have far fewer distinct users than grants, so a user's authority
	 * is resolved at most once per page render.
	 *
	 * @param  array<CollectionData>                                      $allCollections
	 * @param  array<string,array{exists: bool, authority: UserAuthority}> $authorityCache
	 *
	 * @return array{fullAdmin: bool, userMissing: bool, noAccess: bool, readable: list<string>, readableOverflow: int, writable: list<string>, writableOverflow: int, own: list<string>}
	 */
	private function effectiveReachForGrant(
		OAuthGrantData $grant,
		string $authCollection,
		array $allCollections,
		array &$authorityCache,
	): array {
		$empty = [
			'fullAdmin'          => false,
			'userMissing'        => false,
			'noAccess'           => false,
			'readable'           => [],
			'readableOverflow'   => 0,
			'writable'           => [],
			'writableOverflow'   => 0,
			'own'                => [],
		];

		$ref    = OAuthUserRef::parse($grant->userId, $authCollection);
		$refKey = (string)$ref;

		if (!isset($authorityCache[$refKey])) {
			$exists                  = $this->accessControlService->userExists($ref);
			$authorityCache[$refKey] = [
				'exists'    => $exists,
				'authority' => $exists ? $this->accessControlService->authorityFor($ref) : UserAuthority::denied(),
			];
		}

		$cached = $authorityCache[$refKey];

		if (!$cached['exists']) {
			return ['userMissing' => true] + $empty;
		}

		$authority      = $cached['authority'];
		$expandedScopes = $this->oauthScopeRegistry->expand($grant->scopes);

		// ADMIN-persona elevation mirrors McpAuth::resolvePersona(): identity
		// (admin-group membership) AND scope (cms:admin) — never either alone.
		if ($authority->isAdmin && in_array('cms:admin', $expandedScopes, true)) {
			return ['fullAdmin' => true] + $empty;
		}

		$hasReadScope  = in_array('cms:read', $expandedScopes, true);
		$hasWriteScope = in_array('cms:write', $expandedScopes, true);

		$readable = [];
		$writable = [];
		$own      = [];
		$actor    = new Actor(ActorKind::Member, $ref->userId, $ref->collection, $authority, $expandedScopes, $grant->clientId);

		foreach ($allCollections as $collection) {
			$reach = $this->collectionReach($actor, $authority, $collection, $hasReadScope, $hasWriteScope);
			if ($reach['read']) {
				$readable[] = $collection->id;
			}
			if ($reach['write']) {
				$writable[] = $collection->id;
			}
			if ($reach['own']) {
				$own[] = $collection->id;
			}
		}

		sort($readable);
		sort($writable);
		sort($own);

		return [
			'fullAdmin'          => false,
			'userMissing'        => false,
			'noAccess'           => $readable === [] && $writable === [],
			'readable'           => array_slice($readable, 0, self::EFFECTIVE_REACH_BADGE_CAP),
			'readableOverflow'   => max(0, count($readable) - self::EFFECTIVE_REACH_BADGE_CAP),
			'writable'           => array_slice($writable, 0, self::EFFECTIVE_REACH_BADGE_CAP),
			'writableOverflow'   => max(0, count($writable) - self::EFFECTIVE_REACH_BADGE_CAP),
			'own'                => $own,
		];
	}

	/**
	 * What one OAuth member reaches in $collection over MCP: whether they can
	 * read and write there, and whether that reach is their own objects.
	 *
	 * @return array{read: bool, write: bool, own: bool}
	 */
	private function collectionReach(Actor $actor, UserAuthority $authority, CollectionData $collection, bool $hasReadScope, bool $hasWriteScope): array
	{
		// Every MCP content tool first needs the collection exposed to a
		// signed-in caller (PersonaContext::exposedCollection()); `admin` and
		// `apikey` collections stay closed to OAuth members whatever their
		// groups grant.
		$public = $this->mcpSchemaResolver->isAccessibleTo($collection, 'public');
		if (!$public && !$this->mcpSchemaResolver->isAccessibleTo($collection, 'authenticated')) {
			return ['read' => false, 'write' => false, 'own' => false];
		}

		// Then what the AccessGuard lets this member read — everything, own
		// objects (an owned collection), published only (public exposure,
		// which needs no scope), or nothing — as the read tools apply it.
		$scope = $hasReadScope
			? $this->guard->readScope($actor, $collection->id, $public)
			: ($public ? ReadScope::publishedOnly() : ReadScope::none());
		$own = $scope->owner instanceof OwnerScope;

		// Writes need cms:write and any one of the create, update or delete
		// grants (create_object, update_object/patch_object, delete_object).
		// On an owned collection a member writes their own objects only, and
		// one from another auth collection none.
		$writeGrant = $authority->canCollection('create', $collection->id)
			|| $authority->canCollection('update', $collection->id)
			|| $authority->canCollection('delete', $collection->id);
		$owned = $this->mcpSchemaResolver->forCollection($collection)['owned'];

		return [
			'read'  => $scope->published || $scope->seesDrafts(),
			'write' => $hasWriteScope && $writeGrant && (!$owned || $own),
			'own'   => $own,
		];
	}
}
