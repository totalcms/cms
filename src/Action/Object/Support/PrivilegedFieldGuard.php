<?php

declare(strict_types=1);

namespace TotalCMS\Action\Object\Support;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use TotalCMS\Domain\Auth\Service\AuthFieldPolicy;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * Applies {@see AuthFieldPolicy} to object-write actions, resolving the acting
 * session user and honoring the API-key/OAuth trust model (operator-issued
 * credentials bypass field-level filtering, matching BaseAccessMiddleware).
 * Public form submissions are intentionally NOT trusted here.
 */
final readonly class PrivilegedFieldGuard
{
	public function __construct(
		private AuthFieldPolicy $policy,
		private SessionInterface $session,
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

		return $this->policy->enforce($this->actor(), $collection, $objectId, $data);
	}

	public function guardProperty(ServerRequestInterface $request, string $collection, string $property): void
	{
		if ($this->isTrusted($request)) {
			return;
		}
		if (!$this->policy->canWriteProperty($this->actor(), $collection, $property)) {
			throw new HttpForbiddenException($request, 'You do not have permission to modify this field.');
		}
	}

	private function isTrusted(ServerRequestInterface $request): bool
	{
		$method = $request->getAttribute('authMethod');

		return $method === 'apikey' || $method === 'oauth_bearer';
	}

	private function actor(): string
	{
		return (string)($this->session->get(SessionKeys::AUTH_USER) ?? '');
	}
}
