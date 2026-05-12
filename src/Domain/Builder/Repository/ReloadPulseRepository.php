<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Builder\Repository;

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

	public function __construct(
		private readonly StorageFilesystemAdapter $storage,
	) {
	}

	/**
	 * Record a save event. `path` is informational (template path or page id) —
	 * the SSE clients receive it in the event payload but reload regardless,
	 * since per-page filtering is a v1 simplification.
	 */
	public function pulse(string $path = ''): void
	{
		$payload = [
			'ts'   => $this->microtimeMs(),
			'path' => $path,
		];

		$json = (string)json_encode($payload, JSON_UNESCAPED_SLASHES);

		// Atomic write — readers must never see a half-written file.
		$tmp = self::PULSE_FILE . '.tmp.' . bin2hex(random_bytes(4));
		$this->storage->write($tmp, $json);
		$this->storage->move($tmp, self::PULSE_FILE);
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
		if (!$this->storage->fileExists(self::PULSE_FILE)) {
			return null;
		}

		$json = $this->storage->read(self::PULSE_FILE);
		$data = json_decode($json, true);

		return is_array($data) ? $data : null;
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
