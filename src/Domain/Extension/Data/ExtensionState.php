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
	 * @param array{reason:string,failureCount:int,lastError:string,quarantinedAt:string}|null $quarantine
	 * @param array{reason:string,findings:int,updatedAt:string}|null $updateDisabled
	 * @param bool $autoEnrolledBundled Provenance marker: true when `enabled` was
	 *             written automatically by `ExtensionManager::discoverAndRegister()`
	 *             for a bundled `default_enabled` manifest, never by an operator
	 *             action. Consulted only to stop that auto-written consent from
	 *             silently transferring if a later discovery resolves this id to a
	 *             NON-bundled manifest (e.g. a shadow copy dropped into
	 *             `tcms-data/extensions/`) — see the shadow-consent-gap handling in
	 *             `discoverAndRegister()`. Cleared the moment a human calls
	 *             `ExtensionManager::enable()`, since that is real consent.
	 */
	public function __construct(
		public bool $enabled = false,
		public string $installedAt = '',
		public string $version = '',
		public ?string $error = null,
		public array $permissions = [],
		/** @var array{reason:string,failureCount:int,lastError:string,quarantinedAt:string}|null */
		public ?array $quarantine = null,
		/** @var array{reason:string,findings:int,updatedAt:string}|null */
		public ?array $updateDisabled = null,
		public bool $autoEnrolledBundled = false,
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

		$quarantine = null;
		if (isset($data['quarantine']) && is_array($data['quarantine'])) {
			$q          = $data['quarantine'];
			$quarantine = [
				'reason'        => (string)($q['reason'] ?? ''),
				'failureCount'  => (int)($q['failureCount'] ?? 0),
				'lastError'     => (string)($q['lastError'] ?? ''),
				'quarantinedAt' => (string)($q['quarantinedAt'] ?? ''),
			];
		}

		$updateDisabled = null;
		if (isset($data['updateDisabled']) && is_array($data['updateDisabled'])) {
			$u              = $data['updateDisabled'];
			$updateDisabled = [
				'reason'    => (string)($u['reason'] ?? ''),
				'findings'  => (int)($u['findings'] ?? 0),
				'updatedAt' => (string)($u['updatedAt'] ?? ''),
			];
		}

		return new self(
			enabled: (bool)($data['enabled'] ?? false),
			installedAt: (string)($data['installed_at'] ?? ''),
			version: (string)($data['version'] ?? ''),
			error: isset($data['error']) ? (string)$data['error'] : null,
			permissions: $permissions,
			quarantine: $quarantine,
			updateDisabled: $updateDisabled,
			autoEnrolledBundled: (bool)($data['autoEnrolledBundled'] ?? false),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function toArray(): array
	{
		return [
			'enabled'             => $this->enabled,
			'installed_at'        => $this->installedAt,
			'version'             => $this->version,
			'error'               => $this->error,
			'permissions'         => $this->permissions,
			'quarantine'          => $this->quarantine,
			'updateDisabled'      => $this->updateDisabled,
			'autoEnrolledBundled' => $this->autoEnrolledBundled,
		];
	}

	public function isQuarantined(): bool
	{
		return $this->quarantine !== null;
	}

	public function clearQuarantine(): void
	{
		$this->quarantine = null;
	}

	public function isUpdateDisabled(): bool
	{
		return $this->updateDisabled !== null;
	}

	public function clearUpdateDisabled(): void
	{
		$this->updateDisabled = null;
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
