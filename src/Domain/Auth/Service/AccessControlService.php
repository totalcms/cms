<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\PhpSession;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

/**
 * Access checks for the current session user, and the session-free
 * UserAuthority for OAuth-identified users.
 *
 * Every rule lives in UserAuthority. The `canAccess*` methods here are the
 * session entry point: they resolve the caller's authority from the session's
 * auth collection and ask it. They used to be a second copy of the engine —
 * fourteen twenty-line methods that had to be kept in step with UserAuthority
 * by hand — and the two had drifted.
 */
readonly class AccessControlService
{
	public function __construct(
		private UserValidationService $userValidation,
		private AccessGroupLister $accessGroupLister,
		private PhpSession $session,
		private Config $config,
	) {
	}

	/**
	 * Utils that need a super-admin identity. Kept here for the callers that
	 * reference it by this name; the rule itself is UserAuthority's.
	 */
	public const SUPER_ADMIN_ONLY_UTILS = UserAuthority::SUPER_ADMIN_ONLY_UTILS;

	/**
	 * Check if user is a super admin.
	 *
	 * $collection is the auth collection the caller actually belongs to
	 * (e.g. an OAuthUserRef's collection, which has no PHP session to fall
	 * back on). When omitted, falls back to the current session's auth
	 * collection so session-based callers keep working unchanged.
	 */
	public function isAdmin(string $userId, string $collection = ''): bool
	{
		return $this->userValidation->isSuperAdmin($userId, $collection !== '' ? $collection : $this->sessionCollection());
	}

	public function canAccessCollectionMeta(string $userId, string $collection, string $operation): bool
	{
		return $this->sessionAuthority($userId)->canCollectionMeta($operation, $collection);
	}

	public function canAccessCollection(string $userId, string $collection, string $operation): bool
	{
		return $this->sessionAuthority($userId)->canCollection($operation, $collection);
	}

	public function canAccessCollectionsMetaOperation(string $userId, string $operation): bool
	{
		return $this->sessionAuthority($userId)->canCollectionsMetaOperation($operation);
	}

	public function canAccessCollectionsOperation(string $userId, string $operation): bool
	{
		return $this->sessionAuthority($userId)->canCollectionsOperation($operation);
	}

	public function canAccessSchema(string $userId, string $schema, string $operation): bool
	{
		return $this->sessionAuthority($userId)->canSchema($operation, $schema);
	}

	public function canAccessSchemasOperation(string $userId, string $operation): bool
	{
		return $this->sessionAuthority($userId)->canSchemasOperation($operation);
	}

	public function canAccessUtils(string $userId, string $util): bool
	{
		return $this->sessionAuthority($userId)->canUtil($util);
	}

	public function canAccessMailer(string $userId): bool
	{
		return $this->sessionAuthority($userId)->canMailer();
	}

	public function canAccessPlayground(string $userId): bool
	{
		return $this->sessionAuthority($userId)->canPlayground();
	}

	public function canInlineEdit(string $userId, string $collection): bool
	{
		if (!$this->inlineEditingEnabled()) {
			return false;
		}

		// With auth off there are no users to have groups, and every other
		// access check short-circuits the same way (see BaseAccessMiddleware).
		if (!$this->config->authEnabled()) {
			return true;
		}

		return $this->sessionAuthority($userId)->canInlineEdit($collection);
	}

	public function canAccessDocs(string $userId): bool
	{
		return $this->sessionAuthority($userId)->canDocs();
	}

	public function canAccessAnyUtils(string $userId): bool
	{
		return $this->sessionAuthority($userId)->canAnyUtil();
	}

	public function canAccessUtilsOperation(string $userId, string $operation): bool
	{
		return $this->sessionAuthority($userId)->canUtilsOperation($operation);
	}

	public function canAccessDataViews(string $userId): bool
	{
		return $this->sessionAuthority($userId)->canDataViews();
	}

	public function canAccessBuilder(string $userId): bool
	{
		return $this->sessionAuthority($userId)->canBuilder();
	}

	public function canAccessExtension(string $userId, string $extensionId): bool
	{
		return $this->sessionAuthority($userId)->canExtension($extensionId);
	}

	/**
	 * Whether the OAuth-identified user still exists. Distinguishes a
	 * deleted (or renamed) user from one that simply has no reachable
	 * permissions — {@see authorityFor()} resolves both cases to an
	 * equally "empty" authority, so callers that need to tell a genuinely
	 * missing user apart from an existing-but-groupless one (e.g. the
	 * OAuth Grants admin page, which shows a distinct "this grant is
	 * inert" note for a deleted user) check this first.
	 */
	public function userExists(OAuthUserRef $ref): bool
	{
		if ($this->userValidation->isSuperAdmin($ref->userId, $ref->collection)) {
			return true;
		}

		try {
			$this->userValidation->validateUserById($ref->userId, $ref->collection);

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Resolve a session-free UserAuthority for an OAuth-identified user.
	 * Super admins short-circuit to an unrestricted authority; any lookup
	 * failure (unknown user, missing collection, etc.) resolves to
	 * UserAuthority::denied() rather than throwing.
	 */
	public function authorityFor(OAuthUserRef $ref): UserAuthority
	{
		try {
			return $this->authority($ref->userId, $ref->collection);
		} catch (\Throwable) {
			return UserAuthority::denied();
		}
	}

	/**
	 * The authority of a session user, resolved against the session's auth
	 * collection. Unlike {@see authorityFor()} a lookup failure propagates:
	 * a session naming a user that cannot be validated is an error, not a
	 * quiet denial.
	 */
	private function sessionAuthority(string $userId): UserAuthority
	{
		return $this->authority($userId, $this->sessionCollection());
	}

	private function authority(string $userId, string $collection): UserAuthority
	{
		if ($this->userValidation->isSuperAdmin($userId, $collection)) {
			return new UserAuthority(isAdmin: true, groups: [], inlineEditingEnabled: $this->inlineEditingEnabled());
		}

		return new UserAuthority(
			isAdmin: false,
			groups: $this->getUserAccessGroupsIn($userId, $collection),
			inlineEditingEnabled: $this->inlineEditingEnabled(),
		);
	}

	private function inlineEditingEnabled(): bool
	{
		return ($this->config->dashboard['inlineEditing'] ?? true) === true;
	}

	private function sessionCollection(): string
	{
		return (string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?? '');
	}

	/**
	 * Get all AccessGroupData objects for the user within an explicit auth
	 * collection. Session-free — callers that already know the collection
	 * (e.g. OAuth requests, which have no PHP session) use this directly.
	 *
	 * @return list<AccessGroupData>
	 */
	private function getUserAccessGroupsIn(string $userId, string $collection): array
	{
		// Fetch user data from their actual auth collection
		$user = $this->userValidation->validateUserById($userId, $collection);
		if ($user === []) {
			return [];
		}

		// Get user's group IDs
		$groupIds = $user['groups'] ?? [];

		// If user has no groups assigned, fall back to the 'default' group
		// This also ensures the default group exists for backwards compatibility
		if (empty($groupIds)) {
			$defaultGroup = $this->accessGroupLister->ensureDefaultGroupExists();
			if ($defaultGroup instanceof AccessGroupData) {
				return [$defaultGroup];
			}

			return [];
		}

		// Fetch AccessGroupData for each group
		$groups = [];
		foreach ($groupIds as $groupId) {
			$group = $this->accessGroupLister->findById($groupId);
			if ($group instanceof AccessGroupData) {
				$groups[] = $group;
			}
		}

		return $groups;
	}
}
