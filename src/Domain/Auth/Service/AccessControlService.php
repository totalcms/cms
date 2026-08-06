<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\PhpSession;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
use TotalCMS\Domain\Auth\Data\UserAuthority;
use TotalCMS\Domain\OAuth\Data\OAuthUserRef;
use TotalCMS\Domain\Session\SessionKeys;

/**
 * Service for checking user access permissions based on assigned access groups.
 */
readonly class AccessControlService
{
	public function __construct(
		private UserValidationService $userValidation,
		private AccessGroupLister $accessGroupLister,
		private PhpSession $session,
	) {
	}

	/**
	 * Check if user is a super admin.
	 *
	 * $collection is the auth collection the caller actually belongs to
	 * (e.g. an OAuthUserRef's collection, which has no PHP session to fall
	 * back on). When omitted, falls back to the current session's auth
	 * collection so session-based callers in this class keep working
	 * unchanged.
	 */
	public function isAdmin(string $userId, string $collection = ''): bool
	{
		return $this->userValidation->isSuperAdmin($userId, $collection !== '' ? $collection : $this->sessionCollection());
	}

	/**
	 * Check if user can access a specific collection's metadata with the given CRUD operation.
	 */
	public function canAccessCollectionMeta(string $userId, string $collection, string $operation): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true on first match
		foreach ($groups as $group) {
			if ($this->groupCanAccessCollectionMeta($group, $collection, $operation)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can access a specific collection with the given CRUD operation.
	 */
	public function canAccessCollection(string $userId, string $collection, string $operation): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true on first match
		foreach ($groups as $group) {
			if ($this->groupCanAccessCollection($group, $collection, $operation)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can perform a CRUD operation on collections metadata in general (no specific collection).
	 * Useful for routes like GET /collections or POST /collections that don't target a specific collection.
	 */
	public function canAccessCollectionsMetaOperation(string $userId, string $operation): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true if any group allows the operation for collections metadata
		foreach ($groups as $group) {
			$permissions = $group->permissions['collectionsMeta'] ?? [];

			// Check if user has any collection metadata access (all or specific collections)
			$all     = $permissions['all'] ?? false;
			$allowed = $permissions['allowed'] ?? [];

			if (!$all && $allowed === []) {
				continue; // No collection metadata access in this group
			}

			// Check if operation is allowed
			$operations = $permissions['operations'] ?? [];
			if (in_array($operation, $operations)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can perform a CRUD operation on collections in general (no specific collection).
	 * Useful for routes like GET /collections or POST /collections that don't target a specific collection.
	 */
	public function canAccessCollectionsOperation(string $userId, string $operation): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true if any group allows the operation for collections
		foreach ($groups as $group) {
			$permissions = $group->permissions['collections'] ?? [];

			// Check if user has any collection access (all or specific collections)
			$all     = $permissions['all'] ?? false;
			$allowed = $permissions['allowed'] ?? [];

			if (!$all && $allowed === []) {
				continue; // No collection access in this group
			}

			// Check if operation is allowed
			$operations = $permissions['operations'] ?? [];
			if (in_array($operation, $operations)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can access a specific schema with the given CRUD operation.
	 */
	public function canAccessSchema(string $userId, string $schema, string $operation): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true on first match
		foreach ($groups as $group) {
			if ($this->groupCanAccessSchema($group, $schema, $operation)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can perform a CRUD operation on schemas in general (no specific schema).
	 * Useful for routes like GET /schemas or POST /schemas that don't target a specific schema.
	 */
	public function canAccessSchemasOperation(string $userId, string $operation): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true if any group allows the operation for schemas
		foreach ($groups as $group) {
			$permissions = $group->permissions['schemas'] ?? [];

			// Check if user has any schema access (all or specific schemas)
			$all     = $permissions['all'] ?? false;
			$allowed = $permissions['allowed'] ?? [];

			if (!$all && $allowed === []) {
				continue; // No schema access in this group
			}

			// Check if operation is allowed
			$operations = $permissions['operations'] ?? [];
			if (in_array($operation, $operations)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Utils pages that require a super-admin session regardless of access-group
	 * configuration. A full JumpStart export dumps every schema, collection, and
	 * object on the site; an import overwrites collections and can seed factory
	 * data — both are equivalent in risk to direct filesystem access.
	 *
	 * Public so the Permission Matrix can include these ids in its utils
	 * dimension and mark them super-admin-only without maintaining a second copy.
	 *
	 * @var list<string>
	 */
	public const SUPER_ADMIN_ONLY_UTILS = ['jumpstart', 'permission-matrix'];

	/**
	 * Check if user can access a specific util with the given CRUD operation.
	 */
	public function canAccessUtils(string $userId, string $util): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Certain utils require a super-admin session; no access-group bypass.
		if (in_array($util, self::SUPER_ADMIN_ONLY_UTILS, true)) {
			return false;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true on first match
		foreach ($groups as $group) {
			if ($this->groupCanAccessUtils($group, $util)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can access mailer.
	 */
	public function canAccessMailer(string $userId): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true on first match
		foreach ($groups as $group) {
			if ($this->groupCanAccessMailer($group)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can access playground.
	 */
	public function canAccessPlayground(string $userId): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true on first match
		foreach ($groups as $group) {
			if ($this->groupCanAccessPlayground($group)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can access docs.
	 */
	public function canAccessDocs(string $userId): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true on first match
		foreach ($groups as $group) {
			if ($this->groupCanAccessDocs($group)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user has ANY access to utils (boolean check, not operation-based).
	 */
	public function canAccessAnyUtils(string $userId): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true on first match
		foreach ($groups as $group) {
			if ($this->groupCanAccessAnyUtils($group)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can access utils with the given CRUD operation (no specific page).
	 */
	public function canAccessUtilsOperation(string $userId, string $operation): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true on first match
		foreach ($groups as $group) {
			$permissions = $group->permissions['utils'] ?? [];

			// Check if utils permissions exist
			$all     = $permissions['all'] ?? false;
			$allowed = $permissions['allowed'] ?? [];

			// If no access at all, skip this group
			if (!$all && $allowed === []) {
				continue;
			}

			// Check operation permission
			$operations = $permissions['operations'] ?? [];
			if (in_array($operation, $operations)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get all AccessGroupData objects for the user, resolving the auth
	 * collection from the current session.
	 *
	 * @return array<AccessGroupData>
	 */
	private function getUserAccessGroups(string $userId): array
	{
		// Get the collection the user is authenticated from (stored in session)
		// This ensures we fetch the user from the correct auth collection (e.g., 'staff', 'auth')
		return $this->getUserAccessGroupsIn($userId, $this->sessionCollection());
	}

	/**
	 * The auth collection the current session's user is authenticated
	 * against. '' when there is no session user (e.g. OAuth Bearer requests,
	 * which resolve identity via {@see authorityFor()} instead).
	 */
	private function sessionCollection(): string
	{
		return (string)($this->session->get(SessionKeys::AUTH_COLLECTION) ?? '');
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
		if ($this->userValidation->isSuperAdmin($ref->userId, $ref->collection)) {
			return new UserAuthority(isAdmin: true, groups: []);
		}

		try {
			$groups = $this->getUserAccessGroupsIn($ref->userId, $ref->collection);
		} catch (\Throwable) {
			return UserAuthority::denied();
		}

		return new UserAuthority(isAdmin: false, groups: $groups);
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

	/**
	 * Check if a single group can access a collection's metadata.
	 */
	private function groupCanAccessCollectionMeta(AccessGroupData $group, string $collection, string $operation): bool
	{
		return $group->allowsCollectionMeta($operation, $collection);
	}

	/**
	 * Check if a single group can access a collection.
	 */
	private function groupCanAccessCollection(AccessGroupData $group, string $collection, string $operation): bool
	{
		return $group->allowsCollection($operation, $collection);
	}

	/**
	 * Check if a single group can access a schema.
	 */
	private function groupCanAccessSchema(AccessGroupData $group, string $schema, string $operation): bool
	{
		return $group->allowsSchema($operation, $schema);
	}

	/**
	 * Check if a single group can access a util.
	 * Utils use simple page-based access (no operation-specific permissions).
	 */
	private function groupCanAccessUtils(AccessGroupData $group, string $util): bool
	{
		return $group->allowsUtil($util);
	}

	/**
	 * Check if group has ANY access to utils.
	 */
	private function groupCanAccessAnyUtils(AccessGroupData $group): bool
	{
		$permissions = $group->permissions['utils'] ?? [];
		$all         = $permissions['all'] ?? false;
		$allowed     = $permissions['allowed'] ?? [];

		// Has access if "all" is true OR if they have specific utils allowed
		return $all || $allowed !== [];
	}

	/**
	 * Check if a single group can access mailer.
	 */
	private function groupCanAccessMailer(AccessGroupData $group): bool
	{
		return $group->permissions['mailer'] ?? false;
	}

	/**
	 * Check if a single group can access playground.
	 */
	private function groupCanAccessPlayground(AccessGroupData $group): bool
	{
		return $group->permissions['playground'] ?? false;
	}

	/**
	 * Check if user can access data views.
	 */
	public function canAccessDataViews(string $userId): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true on first match
		foreach ($groups as $group) {
			if ($this->groupCanAccessDataViews($group)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a single group can access docs.
	 */
	private function groupCanAccessDocs(AccessGroupData $group): bool
	{
		return $group->permissions['docs'] ?? false;
	}

	/**
	 * Check if a single group can access data views.
	 */
	private function groupCanAccessDataViews(AccessGroupData $group): bool
	{
		return $group->permissions['dataviews'] ?? false;
	}

	/**
	 * Check if user can access the Site Builder.
	 */
	public function canAccessBuilder(string $userId): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true on first match
		foreach ($groups as $group) {
			if ($this->groupCanAccessBuilder($group)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a single group can access the Site Builder.
	 *
	 * Groups saved before the dedicated `builder` permission existed fall
	 * back to their `templates` permission — that is what effectively gated
	 * Builder access until now (the builder routes ran behind
	 * TemplateAccessMiddleware), so upgrades preserve who could already use
	 * it. Once the group is re-saved, the explicit value wins.
	 */
	private function groupCanAccessBuilder(AccessGroupData $group): bool
	{
		return $group->permissions['builder'] ?? ($group->permissions['templates'] ?? false);
	}

	/**
	 * Check if user can access an extension's admin surface (nav items,
	 * dashboard widgets, and admin pages registered with permission 'any').
	 * Admin-only extension pages are unaffected — they require a super
	 * admin regardless of groups.
	 */
	public function canAccessExtension(string $userId, string $extensionId): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId, $this->sessionCollection())) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true on first match
		foreach ($groups as $group) {
			if ($this->groupCanAccessExtension($group, $extensionId)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a single group can access an extension's admin surface.
	 *
	 * Groups saved before the `extensions` block existed fall back to
	 * all=true — extension nav items marked 'any' were visible to every
	 * dashboard user until now, so upgrades preserve that. Restriction is
	 * opt-in via the access-group form.
	 */
	private function groupCanAccessExtension(AccessGroupData $group, string $extensionId): bool
	{
		$permissions = $group->permissions['extensions'] ?? ['all' => true, 'allowed' => []];

		if (($permissions['all'] ?? false) === true) {
			return true;
		}

		return in_array($extensionId, (array)($permissions['allowed'] ?? []), true);
	}
}
