<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use Odan\Session\PhpSession;
use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;
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
	 */
	public function isAdmin(string $userId): bool
	{
		return $this->userValidation->isSuperAdmin($userId);
	}

	/**
	 * Check if user can access a specific collection's metadata with the given CRUD operation.
	 */
	public function canAccessCollectionMeta(string $userId, string $collection, string $operation): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId)) {
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
		if ($this->userValidation->isSuperAdmin($userId)) {
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
		if ($this->userValidation->isSuperAdmin($userId)) {
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
		if ($this->userValidation->isSuperAdmin($userId)) {
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
		if ($this->userValidation->isSuperAdmin($userId)) {
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
		if ($this->userValidation->isSuperAdmin($userId)) {
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
	 * Check if user can access templates with the given CRUD operation.
	 */
	public function canAccessTemplates(string $userId): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId)) {
			return true;
		}

		// Get user's access groups
		$groups = $this->getUserAccessGroups($userId);
		if ($groups === []) {
			return false;
		}

		// Check each group - return true on first match
		foreach ($groups as $group) {
			if ($this->groupCanAccessTemplate($group)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can access a specific util with the given CRUD operation.
	 */
	public function canAccessUtils(string $userId, string $util): bool
	{
		// Admin users have full access
		if ($this->userValidation->isSuperAdmin($userId)) {
			return true;
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
		if ($this->userValidation->isSuperAdmin($userId)) {
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
		if ($this->userValidation->isSuperAdmin($userId)) {
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
		if ($this->userValidation->isSuperAdmin($userId)) {
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
		if ($this->userValidation->isSuperAdmin($userId)) {
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
		if ($this->userValidation->isSuperAdmin($userId)) {
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
	 * Get all AccessGroupData objects for the user.
	 *
	 * @return array<AccessGroupData>
	 */
	private function getUserAccessGroups(string $userId): array
	{
		// Get the collection the user is authenticated from (stored in session)
		// This ensures we fetch the user from the correct auth collection (e.g., 'staff', 'auth')
		$collection = $this->session->get(SessionKeys::AUTH_COLLECTION) ?: '';

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
		$permissions = $group->permissions['collectionsMeta'] ?? [];

		// Check if collection metadata access is allowed
		$all     = $permissions['all'] ?? false;
		$allowed = $permissions['allowed'] ?? [];

		if (!$all && !in_array($collection, $allowed)) {
			return false;
		}

		// Check if operation is allowed
		$operations = $permissions['operations'] ?? [];

		return in_array($operation, $operations);
	}

	/**
	 * Check if a single group can access a collection.
	 */
	private function groupCanAccessCollection(AccessGroupData $group, string $collection, string $operation): bool
	{
		$permissions = $group->permissions['collections'] ?? [];

		// Check if collection access is allowed
		$all     = $permissions['all'] ?? false;
		$allowed = $permissions['allowed'] ?? [];

		if (!$all && !in_array($collection, $allowed)) {
			return false;
		}

		// Check if operation is allowed
		$operations = $permissions['operations'] ?? [];

		return in_array($operation, $operations);
	}

	/**
	 * Check if a single group can access a schema.
	 */
	private function groupCanAccessSchema(AccessGroupData $group, string $schema, string $operation): bool
	{
		$permissions = $group->permissions['schemas'] ?? [];

		// Check if schema access is allowed
		$all     = $permissions['all'] ?? false;
		$allowed = $permissions['allowed'] ?? [];

		if (!$all && !in_array($schema, $allowed)) {
			return false;
		}

		// Check if operation is allowed
		$operations = $permissions['operations'] ?? [];

		return in_array($operation, $operations);
	}

	/**
	 * Check if a single group can access templates.
	 * Templates use simple boolean access (no operation-specific permissions).
	 */
	private function groupCanAccessTemplate(AccessGroupData $group): bool
	{
		// If templates is enabled, grant access
		return $group->permissions['templates'] ?? false;
	}

	/**
	 * Check if a single group can access a util.
	 * Utils use simple page-based access (no operation-specific permissions).
	 */
	private function groupCanAccessUtils(AccessGroupData $group, string $util): bool
	{
		$permissions = $group->permissions['utils'] ?? [];

		// Check if util access is allowed (all or specific util)
		$all     = $permissions['all'] ?? false;
		$allowed = $permissions['allowed'] ?? [];

		// Map route paths to their access group permission keys.
		// This allows multiple routes to share a single permission toggle
		// and fixes mismatches between route paths and stored permission values.
		$routeToPermission = [
			'cache-manager'       => 'cache',
			'cache-sizing'        => 'cache',
			'image-cache'         => 'cache',
			'license-manager'     => 'license',
			'logs'                => 'log-analyzer',
			'pretty-url-builder'  => 'pretty-url',
			'import-alloy'        => 'import',
			'import-rss'          => 'import',
			'import-totalcms-one' => 'import',
			'import-wordpress'    => 'import',
		];

		$permissionKey = $routeToPermission[$util] ?? $util;

		// If they have access to this util (all or specific), grant access
		return $all || in_array($permissionKey, $allowed);
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
		if ($this->userValidation->isSuperAdmin($userId)) {
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
}
