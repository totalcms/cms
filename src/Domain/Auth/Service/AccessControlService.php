<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Auth\Service;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\AccessGroup\Service\AccessGroupLister;

/**
 * Service for checking user access permissions based on assigned access groups.
 */
readonly class AccessControlService
{
	public function __construct(
		private UserValidationService $userValidation,
		private AccessGroupLister $accessGroupLister,
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
	 * Check if user can access a specific collection with the given HTTP method.
	 */
	public function canAccessCollection(string $userId, string $collection, string $method): bool
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
			if ($this->groupCanAccessCollection($group, $collection, $method)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can access a specific schema with the given HTTP method.
	 */
	public function canAccessSchema(string $userId, string $schema, string $method): bool
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
			if ($this->groupCanAccessSchema($group, $schema, $method)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can access templates with the given HTTP method.
	 */
	public function canAccessTemplate(string $userId, string $method): bool
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
			if ($this->groupCanAccessTemplate($group, $method)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can access a specific settings section with the given HTTP method.
	 */
	public function canAccessSettings(string $userId, string $section, string $method): bool
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
			if ($this->groupCanAccessSettings($group, $section, $method)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user can access a specific util with the given HTTP method.
	 */
	public function canAccessUtils(string $userId, string $util, string $method): bool
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
			if ($this->groupCanAccessUtils($group, $util, $method)) {
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
	 * Get all AccessGroupData objects for the user.
	 *
	 * @return array<AccessGroupData>
	 */
	private function getUserAccessGroups(string $userId): array
	{
		// Fetch user data
		$user = $this->userValidation->validateUserById($userId);
		if ($user === []) {
			return [];
		}

		// Get user's group IDs
		$groupIds = $user['groups'] ?? [];
		if (empty($groupIds)) {
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
	 * Check if a single group can access a collection.
	 */
	private function groupCanAccessCollection(AccessGroupData $group, string $collection, string $method): bool
	{
		$permissions = $group->permissions['collections'] ?? [];

		// Check if collection access is allowed
		$all     = $permissions['all'] ?? false;
		$allowed = $permissions['allowed'] ?? [];

		if (!$all && !in_array($collection, $allowed)) {
			return false;
		}

		// Check if method is allowed
		$methods = $permissions['methods'] ?? [];

		return in_array($method, $methods);
	}

	/**
	 * Check if a single group can access a schema.
	 */
	private function groupCanAccessSchema(AccessGroupData $group, string $schema, string $method): bool
	{
		$permissions = $group->permissions['schemas'] ?? [];

		// Check if schema access is allowed
		$all     = $permissions['all'] ?? false;
		$allowed = $permissions['allowed'] ?? [];

		if (!$all && !in_array($schema, $allowed)) {
			return false;
		}

		// Check if method is allowed
		$methods = $permissions['methods'] ?? [];

		return in_array($method, $methods);
	}

	/**
	 * Check if a single group can access templates.
	 */
	private function groupCanAccessTemplate(AccessGroupData $group, string $method): bool
	{
		// If templates is false, no access
		$templatesEnabled = $group->permissions['templates'] ?? false;
		if (!$templatesEnabled) {
			return false;
		}

		// Templates is true, check global methods
		return in_array($method, $group->methods);
	}

	/**
	 * Check if a single group can access a settings section.
	 */
	private function groupCanAccessSettings(AccessGroupData $group, string $section, string $method): bool
	{
		$permissions = $group->permissions['settings'] ?? [];

		// Check if settings access is allowed
		$all     = $permissions['all'] ?? false;
		$allowed = $permissions['allowed'] ?? [];

		if (!$all && !in_array($section, $allowed)) {
			return false;
		}

		// Check global methods
		return in_array($method, $group->methods);
	}

	/**
	 * Check if a single group can access a util.
	 */
	private function groupCanAccessUtils(AccessGroupData $group, string $util, string $method): bool
	{
		$permissions = $group->permissions['utils'] ?? [];

		// Check if util access is allowed
		$all     = $permissions['all'] ?? false;
		$allowed = $permissions['allowed'] ?? [];

		if (!$all && !in_array($util, $allowed)) {
			return false;
		}

		// Check global methods
		return in_array($method, $group->methods);
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
	 * Check if a single group can access docs.
	 */
	private function groupCanAccessDocs(AccessGroupData $group): bool
	{
		return $group->permissions['docs'] ?? false;
	}
}
