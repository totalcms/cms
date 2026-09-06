<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Repository;

use Psr\Log\NullLogger;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\CorruptPolicy;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/**
 * Persists the migration ledger at tcms-data/.system/migrations.json.
 * Records which migrations have already run so the runner skips them.
 */
final class MigrationStateRepository
{
	private const STATE_FILE = '.system/migrations.json';

	/** @var array<string,array{ranAt:string,result:int}>|null */
	private ?array $cache = null;

	private readonly AtomicJsonStore $store;

	public function __construct(
		StorageFilesystemAdapter $storage,
		?AtomicJsonStore $store = null,
	) {
		$this->store = $store ?? new AtomicJsonStore($storage, '', new NullLogger());
	}

	public function hasRun(string $migrationId): bool
	{
		return isset($this->load()[$migrationId]);
	}

	public function recordRan(string $migrationId, int $result): void
	{
		$state                = $this->load();
		$state[$migrationId]  = [
			'ranAt'  => gmdate('Y-m-d\TH:i:s\Z'),
			'result' => $result,
		];
		$this->cache          = $state;
		$this->persist();
	}

	/**
	 * @return array<string,array{ranAt:string,result:int}>
	 */
	private function load(): array
	{
		if ($this->cache !== null) {
			return $this->cache;
		}

		$state = [];
		// RefuseWrites: a malformed ledger re-runs migrations (they are
		// idempotent) but must never be replaced by an empty ledger.
		/** @var mixed $entry */
		foreach ($this->store->load(self::STATE_FILE, CorruptPolicy::RefuseWrites) as $id => $entry) {
			if (is_array($entry) && isset($entry['ranAt'], $entry['result'])) {
				$state[(string)$id] = [
					'ranAt'  => (string)$entry['ranAt'],
					'result' => (int)$entry['result'],
				];
			}
		}

		$this->cache = $state;

		return $state;
	}

	private function persist(): void
	{
		$this->store->save(self::STATE_FILE, $this->cache ?? []);
	}
}
