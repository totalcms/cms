<?php

declare(strict_types=1);

namespace TotalCMS\Domain\AccessGroup\Data;

/**
 * Access Group data object.
 *
 * @property string $id Unique identifier
 * @property string $description Human-readable description
 * @property array<string> $methods Global allowed HTTP methods
 * @property array<string,mixed> $permissions Structured permissions
 */
readonly class AccessGroupData
{
	public string $id;
	public string $description;
	/** @var array<string> */
	public array $methods;
	/** @var array<string,mixed> */
	public array $permissions;

	/**
	 * @param array<string,mixed> $data
	 */
	public function __construct(array $data)
	{
		$this->id          = $data['id'];
		$this->description = $data['description'] ?? '';
		$this->methods     = $data['methods'] ?? ['GET'];
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
			'playground' => true,
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
			'methods'     => $this->methods,
			'permissions' => $this->permissions,
		];
	}
}
