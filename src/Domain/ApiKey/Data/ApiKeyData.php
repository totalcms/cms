<?php

declare(strict_types=1);

namespace TotalCMS\Domain\ApiKey\Data;

/**
 * API Key data object.
 *
 * @property string $id Unique identifier (UUID)
 * @property string $name Human-readable name
 * @property string $key The actual API key (tcms_...)
 * @property string $created ISO 8601 datetime
 * @property string|null $lastUsed ISO 8601 datetime
 * @property array<string,mixed> $scopes Permissions (methods, paths)
 */
readonly class ApiKeyData
{
	public string $id;
	public string $name;
	public string $key;
	public string $created;
	public ?string $lastUsed;
	/** @var array<string,mixed> */
	public array $scopes;

	/**
	 * @param array<string,mixed> $data
	 */
	public function __construct(array $data)
	{
		$this->id       = $data['id'];
		$this->name     = $data['name'];
		$this->key      = $data['key'];
		$this->created  = $data['created'];
		$this->lastUsed = $data['lastUsed'] ?? null;
		$this->scopes   = $data['scopes'] ?? ['methods' => [], 'paths' => []];
	}

	/**
	 * Get the masked key for display (shows only prefix and first few chars).
	 */
	public function getMaskedKey(): string
	{
		if (strlen($this->key) < 12) {
			return $this->key;
		}

		// Show tcms_abc1...
		return substr($this->key, 0, 10) . '...';
	}

	/**
	 * Check if this key allows a specific HTTP method.
	 */
	public function allowsMethod(string $method): bool
	{
		return in_array(strtoupper($method), $this->scopes['methods'] ?? [], true);
	}

	/**
	 * Check if this key allows access to a specific path.
	 */
	public function allowsPath(string $path): bool
	{
		$allowedPaths = $this->scopes['paths'] ?? [];

		foreach ($allowedPaths as $allowedPath) {
			if ($allowedPath === '*' || str_starts_with(strtolower($path), strtolower((string)$allowedPath))) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert to array for JSON storage.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		return [
			'id'        => $this->id,
			'name'      => $this->name,
			'key'       => $this->key,
			'created'   => $this->created,
			'lastUsed'  => $this->lastUsed,
			'scopes'    => $this->scopes,
			'maskedKey' => $this->getMaskedKey(),
		];
	}
}
