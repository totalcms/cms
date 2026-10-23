<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\SessionInterface;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

/**
 * Builds the {@see Actor} for each way a caller can identify itself: a
 * session, an OAuth token, an API key, or nothing. The one place that
 * decides whether a user is an admin or a member, and that resolves an
 * empty auth collection to the default one.
 */
final readonly class ActorResolver
{
	public function __construct(
		private SessionInterface $session,
		private AccessControlService $accessControl,
		private Config $config,
	) {
	}

	/**
	 * The signed-in session user, or anonymous when there is none.
	 */
	public function fromSession(): Actor
	{
		$userId = (string)($this->session->get(SessionKeys::AUTH_USER) ?? '');
		if ($userId === '') {
			return Actor::anonymous();
		}

		return $this->forUser($userId, (string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?? ''));
	}

	/**
	 * The actor behind an OAuth token's subject (`collection:userId`, or a
	 * bare id in the default collection) carrying the token's scopes and the
	 * client it was issued to.
	 *
	 * @param list<string> $scopes
	 */
	public function fromBearer(string $subject, array $scopes, string $clientId = ''): Actor
	{
		$ref = OAuthUserRef::parse($subject, $this->defaultCollection());

		return $this->forUser($ref->userId, $ref->collection, $scopes, $clientId);
	}

	/**
	 * A user in an auth collection: Admin when they are a super admin,
	 * Member otherwise. An empty user id is anonymous.
	 *
	 * @param list<string>|null $scopes   the OAuth token's scopes, or null for a session
	 * @param string            $clientId the OAuth client, or '' for a session
	 */
	public function forUser(string $userId, string $collection, ?array $scopes = null, string $clientId = ''): Actor
	{
		if ($userId === '') {
			return Actor::anonymous();
		}

		$collection = $collection !== '' ? $collection : $this->defaultCollection();
		$authority  = $this->accessControl->authorityOf($userId, $collection);

		return new Actor(
			kind: $authority->isAdmin ? ActorKind::Admin : ActorKind::Member,
			userId: $userId,
			authCollection: $collection,
			authority: $authority,
			scopes: $scopes,
			clientId: $clientId,
		);
	}

	/**
	 * The scope identifiers from an `oauth_scopes` request attribute, which
	 * holds league scope entities or plain strings.
	 *
	 * @return list<string>
	 */
	public static function scopeIds(mixed $scopes): array
	{
		if (!is_array($scopes)) {
			return [];
		}

		return array_values(array_map(
			static fn (mixed $scope): string => is_object($scope) && method_exists($scope, 'getIdentifier')
				? (string)$scope->getIdentifier()
				: (is_scalar($scope) ? (string)$scope : ''),
			$scopes,
		));
	}

	private function defaultCollection(): string
	{
		return (string)($this->config->auth['collection'] ?? '');
	}
}
