<?php

declare(strict_types=1);

namespace TotalCMS\Domain\AccessGroup\Data;

/**
 * Access Group data object.
 *
 * @property string $id Unique identifier
 * @property string $description Human-readable description
 * @property array<string> $operations Global allowed operations (create, read, update, delete)
 * @property array<string,mixed> $permissions Structured permissions
 */
readonly class AccessGroupData
{
	public string $id;
	public string $description;
	/** @var array<string> */
	public array $operations;
	/** @var array<string,mixed> */
	public array $permissions;

	/**
	 * @param array<string,mixed> $data
	 */
	public function __construct(array $data)
	{
		$this->id          = $data['id'];
		$this->description = $data['description'] ?? '';
		$this->operations  = $data['operations'] ?? ['read'];
		$this->permissions = $data['permissions'] ?? $this->getDefaultPermissions();
	}

	/**
	 * Get default empty permissions structure.
	 *
	 * @return array<string,mixed>
	 */
	private function getDefaultPermissions(): array
	{
		return [
			'collectionsMeta' => [
				'operations' => ['read'],
				'all'        => true,
				'allowed'    => [],
			],
			'collections' => [
				'operations' => ['read'],
				'all'        => true,
				'allowed'    => [],
			],
			'schemas' => [
				'operations' => ['read'],
				'all'        => true,
				'allowed'    => [],
			],
			'builder'    => false,
			'mailer'     => false,
			'playground' => true,
			'dataviews'  => false,
			'docs'       => true,
			'utils'      => [
				'all'     => false,
				'allowed' => [],
			],
			'settings' => [
				'all'     => false,
				'allowed' => [],
			],
			'extensions' => [
				'all'     => true,
				'allowed' => [],
			],
		];
	}

	/**
	 * Check if this group grants a CRUD operation on a specific collection.
	 * Single home for this logic — AccessControlService and UserAuthority
	 * both delegate here rather than re-implementing the check.
	 */
	public function allowsCollection(string $operation, string $collection): bool
	{
		$permissions = $this->permissions['collections'] ?? [];
		$all         = $permissions['all'] ?? false;
		$allowed     = $permissions['allowed'] ?? [];

		if (!$all && !in_array($collection, $allowed)) {
			return false;
		}

		$operations = $permissions['operations'] ?? [];

		return in_array($operation, $operations);
	}

	/**
	 * Check if this group grants a CRUD operation on a specific collection's metadata.
	 */
	public function allowsCollectionMeta(string $operation, string $collection): bool
	{
		$permissions = $this->permissions['collectionsMeta'] ?? [];
		$all         = $permissions['all'] ?? false;
		$allowed     = $permissions['allowed'] ?? [];

		if (!$all && !in_array($collection, $allowed)) {
			return false;
		}

		$operations = $permissions['operations'] ?? [];

		return in_array($operation, $operations);
	}

	/**
	 * Check if this group grants a CRUD operation on a specific schema.
	 */
	public function allowsSchema(string $operation, string $schema): bool
	{
		$permissions = $this->permissions['schemas'] ?? [];
		$all         = $permissions['all'] ?? false;
		$allowed     = $permissions['allowed'] ?? [];

		if (!$all && !in_array($schema, $allowed)) {
			return false;
		}

		$operations = $permissions['operations'] ?? [];

		return in_array($operation, $operations);
	}

	/**
	 * Check if this group grants access to a util. Utils use simple
	 * page-based access (no operation-specific permissions).
	 */
	public function allowsUtil(string $util): bool
	{
		$permissions = $this->permissions['utils'] ?? [];
		$all         = $permissions['all'] ?? false;
		$allowed     = $permissions['allowed'] ?? [];

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

		return $all || in_array($permissionKey, $allowed);
	}

	/**
	 * Convert to array for JSON storage.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		return [
			'id'          => $this->id,
			'description' => $this->description,
			'operations'  => $this->operations,
			'permissions' => $this->permissions,
		];
	}
}
