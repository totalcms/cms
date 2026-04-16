<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Repository;

use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/**
 * Reads and writes extension state from tcms-data/.system/extensions.json.
 */
final class ExtensionStateRepository
{
	private const STATE_FILE = '.system/extensions.json';

	/** @var array<string,ExtensionState>|null */
	private ?array $cache = null;

	public function __construct(
		private readonly StorageFilesystemAdapter $storage,
	) {
	}

	/**
	 * @return array<string,ExtensionState>
	 */
	public function loadAll(): array
	{
		if ($this->cache !== null) {
			return $this->cache;
		}

		$states = [];

		if ($this->storage->fileExists(self::STATE_FILE)) {
			$json = $this->storage->read(self::STATE_FILE);
			$data = json_decode($json, true);
			if (is_array($data)) {
				foreach ($data as $id => $stateData) {
					if (is_array($stateData)) {
						$states[(string)$id] = ExtensionState::fromArray($stateData);
					}
				}
			}
		}

		$this->cache = $states;

		return $states;
	}

	public function getState(string $extensionId): ?ExtensionState
	{
		$states = $this->loadAll();

		return $states[$extensionId] ?? null;
	}

	public function isEnabled(string $extensionId): bool
	{
		$state = $this->getState($extensionId);

		return $state instanceof ExtensionState && $state->enabled;
	}

	public function saveState(string $extensionId, ExtensionState $state): void
	{
		$states               = $this->loadAll();
		$states[$extensionId] = $state;
		$this->cache          = $states;
		$this->persist();
	}

	public function removeState(string $extensionId): void
	{
		$states = $this->loadAll();
		unset($states[$extensionId]);
		$this->cache = $states;
		$this->persist();
	}

	public function recordError(string $extensionId, string $message): void
	{
		$state = $this->getState($extensionId);
		if (!$state instanceof ExtensionState) {
			return;
		}

		$state->error = $message;
		$this->saveState($extensionId, $state);
	}

	public function clearError(string $extensionId): void
	{
		$state = $this->getState($extensionId);
		if (!$state instanceof ExtensionState || $state->error === null) {
			return;
		}

		$state->error = null;
		$this->saveState($extensionId, $state);
	}

	private function persist(): void
	{
		$output = [];
		foreach ($this->cache ?? [] as $id => $state) {
			$output[$id] = $state->toArray();
		}

		$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json !== false) {
			$this->storage->write(self::STATE_FILE, $json);
		}
	}
}
