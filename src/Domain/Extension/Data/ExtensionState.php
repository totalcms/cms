<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Data;

/**
 * Runtime state for a single extension (stored in extensions.json).
 */
final class ExtensionState
{
	public function __construct(
		public bool $enabled = false,
		public string $installedAt = '',
		public string $version = '',
		public ?string $error = null,
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function fromArray(array $data): self
	{
		return new self(
			enabled: (bool)($data['enabled'] ?? false),
			installedAt: (string)($data['installed_at'] ?? ''),
			version: (string)($data['version'] ?? ''),
			error: isset($data['error']) ? (string)$data['error'] : null,
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
		];
	}
}
