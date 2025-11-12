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
		// Prevent deletion of admin group
		if ($id === 'admin') {
			throw new \RuntimeException('Cannot delete the admin group');
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
	 * Create default access groups if they don't exist.
	 */
	public function createDefaultGroups(): void
	{
		if ($this->filesystem->fileExists(self::FILE_PATH)) {
			return;
		}

		// Admin group - full access (immutable)
		if (!$this->exists('admin')) {
			$adminGroup = new AccessGroupData([
				'id'          => 'admin',
				'description' => 'Full administrative access to all features',
				'methods'     => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
				'permissions' => [
					'collections' => [
						'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
						'all'     => true,
						'allowed' => [],
					],
					'schemas' => [
						'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
						'all'     => true,
						'allowed' => [],
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
			]);
			$this->save($adminGroup);
		}

		// Editor group - content creation and editing
		if (!$this->exists('editor')) {
			$editorGroup = new AccessGroupData([
				'id'          => 'editor',
				'description' => 'Content editors - can create and edit content',
				'methods'     => ['GET', 'POST', 'PUT', 'DELETE'],
				'permissions' => [
					'collections' => [
						'methods' => ['GET', 'POST', 'PUT', 'DELETE'],
						'all'     => true,
						'allowed' => [],
					],
					'schemas' => [
						'methods' => ['GET'],
						'all'     => true,
						'allowed' => [],
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
			]);
			$this->save($editorGroup);
		}

		// Viewer group - read-only access
		if (!$this->exists('viewer')) {
			$viewerGroup = new AccessGroupData([
				'id'          => 'viewer',
				'description' => 'Read-only access to view content',
				'methods'     => ['GET'],
				'permissions' => [
					'collections' => [
						'methods' => ['GET'],
						'all'     => true,
						'allowed' => [],
					],
					'schemas' => [
						'methods' => ['GET'],
						'all'     => true,
						'allowed' => [],
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
			]);
			$this->save($viewerGroup);
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
