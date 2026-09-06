<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Repository;

use Psr\Log\NullLogger;
use TotalCMS\Domain\Storage\AtomicJsonStore;
use TotalCMS\Domain\Storage\CorruptPolicy;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;

/**
 * Reads/writes the live-reload pulse file at
 * `tcms-data/.system/builder-reload-pulse.json`.
 *
 * Each `pulse()` call bumps the file's contents to a new `{ts, path}` payload.
 * The SSE endpoint long-polls the file via `currentTs()` — when the timestamp
 * advances, every connected admin tab receives a reload event.
 *
 * A flat file is the right pick here:
 *   - Zero infra (no Redis, no shared memory required)
 *   - Multi-process safe via atomic rename on write
 *   - Cheap to stat in a poll loop
 */
class ReloadPulseRepository
{
	public const PULSE_FILE = '.system/builder-reload-pulse.json';

	private readonly AtomicJsonStore $store;

	public function __construct(
		StorageFilesystemAdapter $storage,
		?AtomicJsonStore $store = null,
	) {
		$this->store = $store ?? new AtomicJsonStore($storage, '', new NullLogger());
	}

	/**
	 * Record a save event. `path` is informational (template path or page id) —
	 * the SSE clients receive it in the event payload but reload regardless,
	 * since per-page filtering is a v1 simplification.
	 */
	public function pulse(string $path = ''): void
	{
		// Atomic temp+rename via the store — readers never see a half-written file.
		$this->store->save(self::PULSE_FILE, [
			'ts'   => $this->microtimeMs(),
			'path' => $path,
		]);
	}

	/**
	 * Return the current pulse timestamp, or 0 if the file is missing/invalid.
	 * Used by the SSE long-poll to detect a change since the last read.
	 */
	public function currentTs(): int
	{
		$payload = $this->read();

		return (int)($payload['ts'] ?? 0);
	}

	/**
	 * Return the current pulse payload (`{ts, path}`), or `null` if missing.
	 *
	 * @return array{ts:int,path:string}|null
	 */
	public function current(): ?array
	{
		$payload = $this->read();
		if ($payload === null) {
			return null;
		}

		return [
			'ts'   => (int)($payload['ts'] ?? 0),
			'path' => (string)($payload['path'] ?? ''),
		];
	}

	/** @return array<string,mixed>|null */
	private function read(): ?array
	{
		// TreatAsEmpty: the pulse file is disposable — the next save replaces it.
		$data = $this->store->load(self::PULSE_FILE, CorruptPolicy::TreatAsEmpty);

		return $data === [] ? null : $data;
	}

	/**
	 * Millisecond-precision timestamp. Plain unix seconds aren't precise
	 * enough — multiple saves within the same second (rapid-fire admin
	 * editing) would coalesce into a single pulse and miss reloads.
	 */
	private function microtimeMs(): int
	{
		return (int)round(microtime(true) * 1000);
	}
}
