<?php

declare(strict_types=1);

namespace TotalCMS\Action\Object\Support;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\Auth\Data\AccessActor;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Middleware\Access\BaseAccessMiddleware;

/**
 * Applies {@see AuthFieldPolicy} to object-write actions, mirroring
 * BaseAccessMiddleware's trust model: API keys and ADMIN OAuth bearer tokens
 * bypass field-level filtering. Everyone else is the {@see AccessActor} that
 * BaseAccessMiddleware resolved once (token `sub` claim for a bearer, session
 * otherwise) and attached as a request attribute. A route without the access
 * middleware has no actor, and the policy then treats the write as anonymous.
 * Public form submissions are intentionally NOT trusted here.
 *
 * Single-property writes go through {@see self::guardProperty()}, which also
 * returns the forced values (`protected: {value}`) the caller must write in the
 * same save, so editing an unprotected property cannot keep a forced one stale.
 */
final readonly class PrivilegedFieldGuard
{
	public function __construct(
		private AuthFieldPolicy $policy,
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
	 * The acting user id and the auth collection they belong to, as resolved
	 * once by BaseAccessMiddleware; `['', '']` when the request carries none.
	 *
	 * @return array{0: string, 1: string} actor id, actor collection
	 */
	private function actor(ServerRequestInterface $request): array
	{
		$actor = $request->getAttribute(BaseAccessMiddleware::ACTOR_ATTRIBUTE);
		if (!$actor instanceof AccessActor) {
			return ['', ''];
		}

		return [$actor->userId, $actor->collection];
	}
}
