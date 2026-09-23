<?php

declare(strict_types=1);

namespace TotalCMS\Domain\AccessGroup\Repository;

use TotalCMS\Domain\AccessGroup\Data\AccessGroupData;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\CorruptPolicy;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Domain\Storage\StorageRepository;

/**
 * Repository for managing Access Groups stored in .system/access-groups.json.
 */
class AccessGroupRepository extends StorageRepository
{
	private const FILE_PATH = '.system/access-groups.json';

	/**
	 * Request-level cache for file contents.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $requestCache = null;

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
			'builder'    => true,
			'mailer'     => true,
			'playground' => true,
			'dataviews'  => true,
			'docs'       => true,
			'inlineEdit' => true,
			'utils'      => [
				'all'     => true,
				'allowed' => [],
			],
			'settings' => [
				'all'     => true,
				'allowed' => [],
			],
			'extensions' => [
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
			'builder'    => true,
			'mailer'     => true,
			'playground' => false,
			'dataviews'  => false,
			'docs'       => true,
			'inlineEdit' => true,
			'utils'      => [
				'all'     => false,
				'allowed' => ['jumpstart', 'project-setup', 'image-batcher'],
			],
			'settings' => [
				'all'     => false,
				'allowed' => ['general', 'dashboard'],
			],
			'extensions' => [
				'all'     => true,
				'allowed' => [],
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
			'builder'    => false,
			'mailer'     => false,
			'playground' => false,
			'dataviews'  => false,
			'docs'       => true,
			'inlineEdit' => false,
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
			'builder'    => false,
			'mailer'     => false,
			'playground' => false,
			'dataviews'  => false,
			'docs'       => true,
			'inlineEdit' => false,
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
		],
	];

	public function __construct(
		StorageAdapterInterface $filesystem,
		private readonly AtomicJsonStore $store,
	) {
		parent::__construct($filesystem);
	}

	/**
	 * Get all access groups.
	 * The admin group always reflects the current template to ensure
	 * new permissions are available without re-saving.
	 *
	 * @return array<AccessGroupData>
	 */
	public function getAll(): array
	{
		$data   = $this->readFile();
		$groups = [];

		foreach ($data['groups'] ?? [] as $groupData) {
			if (($groupData['id'] ?? '') === 'admin') {
				$groups[] = new AccessGroupData(self::ADMIN_GROUP_TEMPLATE);
			} else {
				$groups[] = new AccessGroupData($groupData);
			}
		}

		return $groups;
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
	 * The file's contents, read once per request. A file that no longer parses
	 * is treated as empty for this request and never written back — repairing
	 * it is the operator's call.
	 *
	 * @return array<string,mixed>
	 */
	private function readFile(): array
	{
		if ($this->requestCache !== null) {
			return $this->requestCache;
		}

		$data = $this->store->load(self::FILE_PATH, CorruptPolicy::RefuseWrites);

		$this->requestCache = $data === [] ? ['groups' => []] : $data;

		return $this->requestCache;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function writeFile(array $data): void
	{
		$this->store->save(self::FILE_PATH, $data);

		// Invalidate cache after write
		$this->requestCache = null;
	}
}
