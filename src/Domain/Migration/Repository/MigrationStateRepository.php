<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Migration\Repository;

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

	public function __construct(
		private readonly StorageFilesystemAdapter $storage,
	) {
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
		if ($this->storage->fileExists(self::STATE_FILE)) {
			$json    = $this->storage->read(self::STATE_FILE);
			$decoded = json_decode($json, true);
			if (is_array($decoded)) {
				/** @var mixed $entry */
				foreach ($decoded as $id => $entry) {
					if (is_array($entry) && isset($entry['ranAt'], $entry['result'])) {
						$state[(string)$id] = [
							'ranAt'  => (string)$entry['ranAt'],
							'result' => (int)$entry['result'],
						];
					}
				}
			}
		}

		$this->cache = $state;

		return $state;
	}

	private function persist(): void
	{
		$json = json_encode($this->cache ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			return;
		}

		// Atomic write — concurrent readers shouldn't see a partial file.
		$tmp = self::STATE_FILE . '.tmp.' . bin2hex(random_bytes(4));
		$this->storage->write($tmp, $json);
		$this->storage->move($tmp, self::STATE_FILE);
	}
}
