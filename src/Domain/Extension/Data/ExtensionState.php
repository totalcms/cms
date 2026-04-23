<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Data;

/**
 * Runtime state for a single extension (stored in extensions.json).
 */
final class ExtensionState
{
	/**
	 * @param array<string,bool> $permissions Capability key => enabled
	 */
	public function __construct(
		public bool $enabled = false,
		public string $installedAt = '',
		public string $version = '',
		public ?string $error = null,
		public array $permissions = [],
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		$permissions = [];
		if (isset($data['permissions']) && is_array($data['permissions'])) {
			foreach ($data['permissions'] as $key => $value) {
				$permissions[(string)$key] = (bool)$value;
			}
		}

		return new self(
			enabled: (bool)($data['enabled'] ?? false),
			installedAt: (string)($data['installed_at'] ?? ''),
			version: (string)($data['version'] ?? ''),
			error: isset($data['error']) ? (string)$data['error'] : null,
			permissions: $permissions,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		return [
			'enabled'      => $this->enabled,
			'installed_at' => $this->installedAt,
			'version'      => $this->version,
			'error'        => $this->error,
			'permissions'  => $this->permissions,
		];
	}

	/**
	 * Check if a specific capability is permitted.
	 *
	 * If no permissions have been set yet (empty array), all capabilities
	 * are allowed — this handles the first-run case before capabilities
	 * are detected.
	 */
	public function isPermitted(string $capability): bool
	{
		if ($this->permissions === []) {
			return true;
		}

		return $this->permissions[$capability] ?? false;
	}
}
