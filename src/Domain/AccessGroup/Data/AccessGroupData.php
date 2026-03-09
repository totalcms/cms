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
			'templates'  => false,
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
		];
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
