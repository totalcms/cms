<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Collection\Data\CollectionData;
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
	 * exposure — the same rule McpAuth::resolvePersona(),
	 * PersonaContext::canReadCollection(), and ObjectTools::requireExposed()
	 * apply at request time, mirrored here rather than reimplemented.
	 *
	 * $authorityCache is keyed by the composite OAuthUserRef string
	 * ("collection:id") and shared across every grant in the request — most
	 * sites have far fewer distinct users than grants, so a user's authority
	 * is resolved at most once per page render.
	 *
	 * @param  array<CollectionData>                                      $allCollections
	 * @param  array<string,array{exists: bool, authority: UserAuthority}> $authorityCache
	 *
	 * @return array{fullAdmin: bool, userMissing: bool, noAccess: bool, readable: list<string>, readableOverflow: int, writable: list<string>, writableOverflow: int, deleteOnly: list<string>, deleteOnlyOverflow: int}
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
			'deleteOnly'         => [],
			'deleteOnlyOverflow' => 0,
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

		$readable   = [];
		$writable   = [];
		$deleteOnly = [];

		foreach ($allCollections as $collection) {
			// Read mirrors PersonaContext::canReadCollection(): public
			// exposure is reachable with NO scope at all (an AUTHENTICATED
			// caller must never read LESS than an anonymous one would), while
			// a group read grant additionally requires the cms:read scope.
			$publiclyReadable = $this->mcpSchemaResolver->isAccessibleTo($collection, 'public');
			$groupReadable    = $hasReadScope && $authority->canCollection('read', $collection->id);

			if ($publiclyReadable || $groupReadable) {
				$readable[] = $collection->id;
			}

			// Write mirrors ObjectTools::requireExposed(): every MCP write
			// tool demands mcp.access expose the collection to at least an
			// AUTHENTICATED persona BEFORE the group grant is even checked.
			// mcp.access defaults to 'admin' (not exposed) when the operator
			// never set it — practically every collection on a fresh site —
			// so a group write grant alone overstates reach without this gate.
			if ($hasWriteScope && $this->mcpSchemaResolver->isAccessibleTo($collection, 'authenticated')) {
				// create_object/update_object need create/update; MCP has no
				// delete tool. A group that only grants delete (no create or
				// update) can still delete over REST but can't be handed
				// anything an AI assistant would call "write" — list it
				// separately rather than folding it into the write badge.
				if ($authority->canCollection('create', $collection->id) || $authority->canCollection('update', $collection->id)) {
					$writable[] = $collection->id;
				} elseif ($authority->canCollection('delete', $collection->id)) {
					$deleteOnly[] = $collection->id;
				}
			}
		}

		sort($readable);
		sort($writable);
		sort($deleteOnly);

		return [
			'fullAdmin'          => false,
			'userMissing'        => false,
			'noAccess'           => $readable === [] && $writable === [] && $deleteOnly === [],
			'readable'           => array_slice($readable, 0, self::EFFECTIVE_REACH_BADGE_CAP),
			'readableOverflow'   => max(0, count($readable) - self::EFFECTIVE_REACH_BADGE_CAP),
			'writable'           => array_slice($writable, 0, self::EFFECTIVE_REACH_BADGE_CAP),
			'writableOverflow'   => max(0, count($writable) - self::EFFECTIVE_REACH_BADGE_CAP),
			'deleteOnly'         => array_slice($deleteOnly, 0, self::EFFECTIVE_REACH_BADGE_CAP),
			'deleteOnlyOverflow' => max(0, count($deleteOnly) - self::EFFECTIVE_REACH_BADGE_CAP),
		];
	}
}
