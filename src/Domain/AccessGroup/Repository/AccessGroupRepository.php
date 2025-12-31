<?php

declare(strict_types=1);

namespace TotalCMS\Domain\AccessGroup\Repository;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageRepository;

/**
 * Repository for managing Access Groups stored in .system/access-groups.json.
 */
class AccessGroupRepository extends StorageRepository
{
	private const FILE_PATH = '.system/access-groups.json';

	/** @var array<string,mixed> */
	private const ADMIN_GROUP_TEMPLATE = [
		'id'          => 'admin',
		'description' => 'Full administrative access to all features',
		'operations'  => ['create', 'read', 'update', 'delete'],
		'permissions' => [
			'collectionsMeta' => [
				'operations' => ['create', 'read', 'update', 'delete'],
				'all'        => true,
				'allowed'    => [],
			],
			'collections' => [
				'operations' => ['create', 'read', 'update', 'delete'],
				'all'        => true,
				'allowed'    => [],
			],
			'schemas' => [
				'operations' => ['create', 'read', 'update', 'delete'],
				'all'        => true,
				'allowed'    => [],
			],
			'templates'  => true,
			'mailer'     => true,
			'playground' => true,
			'docs'       => true,
			'utils'      => [
				'all'     => true,
				'allowed' => [],
			],
			'settings' => [
				'all'     => true,
				'allowed' => [],
			],
		],
	];

	/** @var array<string,mixed> */
	private const EDITOR_GROUP_TEMPLATE = [
		'id'          => 'editor',
		'description' => 'Content editors - can create and edit content',
		'operations'  => ['create', 'read', 'update', 'delete'],
		'permissions' => [
			'collectionsMeta' => [
				'operations' => ['read'],
				'all'        => true,
				'allowed'    => [],
			],
			'collections' => [
				'operations' => ['create', 'read', 'update', 'delete'],
				'all'        => true,
				'allowed'    => [],
			],
			'schemas' => [
				'operations' => ['read'],
				'all'        => true,
				'allowed'    => [],
			],
			'templates'  => true,
			'mailer'     => true,
			'playground' => false,
			'docs'       => true,
			'utils'      => [
				'all'     => false,
				'allowed' => ['jumpstart', 'project-setup', 'image-batcher'],
			],
			'settings' => [
				'all'     => false,
				'allowed' => ['general', 'dashboard'],
			],
		],
	];

	/** @var array<string,mixed> */
	private const VIEWER_GROUP_TEMPLATE = [
		'id'          => 'viewer',
		'description' => 'Read-only access to view content',
		'operations'  => ['read'],
		'permissions' => [
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
			'templates'  => false,
			'mailer'     => false,
			'playground' => false,
			'docs'       => true,
			'utils'      => [
				'all'     => false,
				'allowed' => [],
			],
			'settings' => [
				'all'     => false,
				'allowed' => [],
			],
		],
	];

	/** @var array<string,mixed> */
	private const DEFAULT_GROUP_TEMPLATE = [
		'id'          => 'default',
		'description' => 'Default access for users without group assignments',
		'operations'  => ['read'],
		'permissions' => [
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
			'templates'  => false,
			'mailer'     => false,
			'playground' => false,
			'docs'       => true,
			'utils'      => [
				'all'     => false,
				'allowed' => [],
			],
			'settings' => [
				'all'     => false,
				'allowed' => [],
			],
		],
	];

	public function __construct(
		StorageAdapterInterface $filesystem,
	) {
		parent::__construct($filesystem);
	}

	/**
	 * Get all access groups.
	 *
	 * @return array<AccessGroupData>
	 */
	public function getAll(): array
	{
		$data = $this->readFile();

		return array_map(
			fn (array $groupData): AccessGroupData => new AccessGroupData($groupData),
			$data['groups'] ?? []
		);
	}

	/**
	 * Find an access group by its ID.
	 */
	public function findById(string $id): ?AccessGroupData
	{
		$groups = $this->getAll();

		foreach ($groups as $group) {
			if ($group->id === $id) {
				return $group;
			}
		}

		return null;
	}

	/**
	 * Check if an access group exists.
	 */
	public function exists(string $id): bool
	{
		return $this->findById($id) instanceof AccessGroupData;
	}

	/**
	 * Save or update an access group.
	 */
	public function save(AccessGroupData $group): void
	{
		$data          = $this->readFile();
		$data['groups'] ??= [];

		// Check if group exists and update, otherwise add
		$found = false;
		foreach ($data['groups'] as $index => $groupData) {
			if ($groupData['id'] === $group->id) {
				$data['groups'][$index] = $group->toArray();
				$found                  = true;
				break;
			}
		}

		if (!$found) {
			$data['groups'][] = $group->toArray();
		}

		// Sort groups by ID before writing
		usort($data['groups'], fn (array $a, array $b): int => strcasecmp((string)($a['id'] ?? ''), (string)($b['id'] ?? '')));

		$this->writeFile($data);
	}

	/**
	 * Delete an access group by ID.
	 */
	public function delete(string $id): bool
	{
		// Prevent deletion of protected groups
		if ($id === 'admin') {
			throw new \RuntimeException('Cannot delete the admin group');
		}
		if ($id === 'default') {
			throw new \RuntimeException('Cannot delete the default group');
		}

		$data   = $this->readFile();
		$groups = $data['groups'] ?? [];

		$originalCount = count($groups);
		$groups        = array_filter($groups, fn (array $groupData): bool => $groupData['id'] !== $id);

		if (count($groups) === $originalCount) {
			return false; // Group not found
		}

		$data['groups'] = array_values($groups); // Re-index array

		// Sort groups by ID before writing
		usort($data['groups'], fn (array $a, array $b): int => strcasecmp((string)($a['id'] ?? ''), (string)($b['id'] ?? '')));

		$this->writeFile($data);

		return true;
	}

	/**
	 * Get all access group IDs.
	 *
	 * @return array<string>
	 */
	public function getAllIds(): array
	{
		$groups = $this->getAll();

		return array_map(fn (AccessGroupData $group): string => $group->id, $groups);
	}

	/**
	 * Ensure the 'default' group exists, creating it if necessary.
	 * Used for backwards compatibility with existing installations.
	 */
	public function ensureDefaultGroupExists(): ?AccessGroupData
	{
		$existing = $this->findById('default');
		if ($existing instanceof AccessGroupData) {
			return $existing;
		}

		$defaultGroup = new AccessGroupData(self::DEFAULT_GROUP_TEMPLATE);
		$this->save($defaultGroup);

		return $defaultGroup;
	}

	/**
	 * Create default access groups if they don't exist.
	 */
	public function createDefaultGroups(): void
	{
		if ($this->filesystem->fileExists(self::FILE_PATH)) {
			return;
		}

		if (!$this->exists('admin')) {
			$this->save(new AccessGroupData(self::ADMIN_GROUP_TEMPLATE));
		}

		if (!$this->exists('editor')) {
			$this->save(new AccessGroupData(self::EDITOR_GROUP_TEMPLATE));
		}

		if (!$this->exists('viewer')) {
			$this->save(new AccessGroupData(self::VIEWER_GROUP_TEMPLATE));
		}

		if (!$this->exists('default')) {
			$this->save(new AccessGroupData(self::DEFAULT_GROUP_TEMPLATE));
		}
	}

	/**
	 * Read the JSON file.
	 *
	 * @return array<string,mixed>
	 */
	private function readFile(): array
	{
		if (!$this->filesystem->fileExists(self::FILE_PATH)) {
			return ['groups' => []];
		}

		$content = $this->filesystem->read(self::FILE_PATH);

		if ($content === '') {
			return ['groups' => []];
		}

		$data = json_decode($content, true);

		return is_array($data) ? $data : ['groups' => []];
	}

	/**
	 * Write to the JSON file.
	 *
	 * @param array<string,mixed> $data
	 */
	private function writeFile(array $data): void
	{
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		if ($json === false) {
			throw new \RuntimeException('Failed to encode access groups to JSON: ' . json_last_error_msg());
		}

		$this->filesystem->write(self::FILE_PATH, $json);
	}
}
