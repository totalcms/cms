<?php

declare(strict_types=1);

namespace TotalCMS\Middleware\Access;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * Collection Access Middleware.
 *
 * Enforces access group permissions for collection operations.
 */
readonly class CollectionAccessMiddleware extends BaseAccessMiddleware
{
	protected const RESOURCE_NAME = 'collection';

	/**
	 * Check if the user has permission to access the requested collection.
	 */
	protected function checkPermission(string $userId, string $operation, ServerRequestInterface $request): bool
	{
		// Get collection name from route
		$routeContext = RouteContext::fromRequest($request);
		$route        = $routeContext->getRoute();
		if (!$route instanceof \Slim\Interfaces\RouteInterface) {
			// No route found, allow through (shouldn't happen)
			return true;
		}

		$collection = $route->getArgument('collection');
		$objectId   = $route->getArgument('id');

		// OAuth Bearer callers: there is no PHP session to derive groups from,
		// so use the UserAuthority resolved from the token by BaseAccessMiddleware.
		// This MUST run before the self-profile carve-out below: the carve-out
		// gates on session AUTH_COLLECTION, not on auth method, so a Bearer
		// request that ALSO carries an active session cookie (browser-originated
		// token use) could otherwise hit the carve-out and update the auth
		// record — bypassing the group check on exactly the record that holds
		// `groups`. Returning here unconditionally for any accessAuthority-
		// bearing (i.e. Bearer) request closes that off.
		$authority = $request->getAttribute('accessAuthority');
		if ($authority instanceof UserAuthority) {
			return $collection
				? $authority->canCollection($operation, $collection)
				: $authority->canCollectionsOperation($operation);
		}

		// Allow users to update their own profile (self-profile update).
		// Users should always be able to update their own record in their auth
		// collection. Only reachable for session callers — Bearer requests
		// always return above via the accessAuthority branch.
		//
		// AUTH_COLLECTION is empty for anyone who logged in at the plain
		// `/admin/login` route, which has no `{collection}` argument — i.e.
		// almost everyone (AuthLoginSubmitAction: `$args['collection'] ?? ''`).
		// An empty value means "the default auth collection" everywhere else in
		// the codebase (see UserValidationService::validateUserById), so it must
		// be resolved before comparing. Comparing the raw session value made
		// this carve-out dead for every default-login user: their own profile
		// save fell through to the group check and 403'd. Super-admins never saw
		// it — they bypass in BaseAccessMiddleware before reaching here.
		if ($operation === 'update' && $collection && $objectId) {
			if ($collection === $this->actorAuthCollection() && $objectId === $userId) {
				return true;
			}
		}

		// Check access permissions
		if ($collection) {
			// Specific collection - check access to that collection
			return $this->accessControl->canAccessCollection($userId, $collection, $operation);
		}

		// No specific collection (e.g., GET /collections, POST /collections) - check general collection operation permission
		return $this->accessControl->canAccessCollectionsOperation($userId, $operation);
	}

	/**
	 * The auth collection the session user belongs to, with an empty session
	 * value resolved to the configured default.
	 */
	private function actorAuthCollection(): string
	{
		$collection = (string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?? '');

		return $collection !== '' ? $collection : (string)$this->config->auth['collection'];
	}
}
