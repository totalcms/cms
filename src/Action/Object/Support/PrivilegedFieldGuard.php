<?php

declare(strict_types=1);

namespace TotalCMS\Action\Object\Support;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

/**
 * Applies {@see AuthFieldPolicy} to object-write actions, resolving the acting
 * user and mirroring BaseAccessMiddleware's trust model: API keys and ADMIN
 * OAuth bearer tokens bypass field-level filtering. A bearer token issued to a
 * non-admin user (BaseAccessMiddleware marks it with the `accessAuthority`
 * attribute) is treated as that user, resolved from the token's `sub` claim
 * since bearer requests have no session. Session users are resolved from the
 * session. Public form submissions are intentionally NOT trusted here.
 *
 * Single-property writes go through {@see self::guardProperty()}, which also
 * returns the forced values (`protected: {value}`) the caller must write in the
 * same save, so editing an unprotected property cannot keep a forced one stale.
 */
final readonly class PrivilegedFieldGuard
{
	public function __construct(
		private AuthFieldPolicy $policy,
		private SessionInterface $session,
		private Config $config,
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 *
	 * @return array<string,mixed>
	 */
	public function guard(ServerRequestInterface $request, string $collection, string $objectId, array $data): array
	{
		if ($this->isTrusted($request)) {
			return $data;
		}

		[$actor, $actorCollection] = $this->actor($request);

		return $this->policy->enforce($actor, $collection, $objectId, $data, $actorCollection);
	}

	/**
	 * @return array<string,mixed> the fields that must be written alongside the property: the forced values from AuthFieldPolicy, or [] for a trusted credential or a super admin
	 */
	public function guardProperty(ServerRequestInterface $request, string $collection, string $objectId, string $property): array
	{
		if ($this->isTrusted($request)) {
			return [];
		}
		[$actor, $actorCollection] = $this->actor($request);
		if (!$this->policy->canWriteProperty($actor, $collection, $property, $actorCollection)) {
			throw new HttpForbiddenException($request, 'You do not have permission to modify this field.');
		}

		return $this->policy->enforce($actor, $collection, $objectId, [], $actorCollection);
	}

	/**
	 * API keys are always trusted. An OAuth bearer is trusted only when it
	 * belongs to an admin: BaseAccessMiddleware passes an admin bearer through
	 * with no `accessAuthority` attribute and scopes every other bearer by
	 * setting it.
	 */
	private function isTrusted(ServerRequestInterface $request): bool
	{
		$method = $request->getAttribute('authMethod');
		if ($method === 'apikey') {
			return true;
		}

		return $method === 'oauth_bearer' && $request->getAttribute('accessAuthority') === null;
	}

	/**
	 * The acting user id and the auth collection they belong to: from the
	 * token's `sub` claim for a (non-admin) bearer request, which has no
	 * session, otherwise from the session.
	 *
	 * @return array{0: string, 1: string} actor id, actor collection
	 */
	private function actor(ServerRequestInterface $request): array
	{
		if ($request->getAttribute('authMethod') === 'oauth_bearer') {
			$ref = OAuthUserRef::parse((string)$request->getAttribute('oauth_user_id', ''), (string)$this->config->auth['collection']);

			return [$ref->userId, $ref->collection];
		}

		return [
			(string)($this->session->get(SessionKeys::AUTH_USER) ?? ''),
			(string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?? ''),
		];
	}
}
