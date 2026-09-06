<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Repository;

use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\CorruptPolicy;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/**
 * Reads and writes extension state from tcms-data/.system/extensions.json.
 */
final class ExtensionStateRepository
{
	private const STATE_FILE = '.system/extensions.json';

	/** @var array<string,ExtensionState>|null */
	private ?array $cache = null;

	private readonly AtomicJsonStore $store;

	/**
	 * The store is optional so the many direct constructions in tests keep
	 * working; the container always supplies the real one (with a logger).
	 */
	public function __construct(
		StorageFilesystemAdapter $storage,
		?AtomicJsonStore $store = null,
	) {
		$this->store = $store ?? new AtomicJsonStore($storage, '', new NullLogger());
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

		// RefuseWrites: a malformed file means "no extension state this
		// request" (every extension reads as disabled), never "reset it all".
		foreach ($this->store->load(self::STATE_FILE, CorruptPolicy::RefuseWrites) as $id => $stateData) {
			if (is_array($stateData)) {
				$states[(string)$id] = ExtensionState::fromArray($stateData);
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

	/**
	 * A saved state record always wins, in both directions — an operator's
	 * explicit choice overrides any manifest default. With no saved state,
	 * an extension is enabled only when it both ships bundled with the T3
	 * package AND declares `default_enabled` in its manifest. `$manifest`
	 * is optional so existing call sites that only have an id keep working
	 * (and safely default to "off" — the only way to be on is bundled +
	 * default_enabled, which needs the manifest). Bundled status is derived
	 * by ExtensionDiscovery from the discovery path, never from manifest
	 * JSON, so a sideloaded/third-party extension cannot self-declare its
	 * way into being enabled by default.
	 */
	public function isEnabled(string $extensionId, ?ExtensionManifest $manifest = null): bool
	{
		$state = $this->getState($extensionId);
		if ($state instanceof ExtensionState) {
			return $state->enabled;
		}

		return $manifest instanceof ExtensionManifest && $manifest->bundled && $manifest->defaultEnabled;
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

		// Atomic temp+rename via the store. Returns false (and logs) when the
		// file was read as corrupt; the in-memory cache still carries this
		// request's changes, so a broken file cannot take the site down.
		$this->store->save(self::STATE_FILE, $output);
	}
}
