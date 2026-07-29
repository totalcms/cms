<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Automation\Service;

use TotalCMS\Domain\Storage\StorageAdapterInterface;

/**
 * Reads persisted automation run records from `.system/automations/{id}/runs`.
 * Companion to AutomationRunner (which writes them) and AutomationStateStore
 * (which owns the sibling schedule/failure state) — keeps the run-history file
 * I/O out of the admin action.
 */
final readonly class AutomationRunReader
{
	private const HISTORY_LIMIT = 50;

	public function __construct(private StorageAdapterInterface $filesystem)
	{
	}

	/**
	 * Newest run record per automation that has any, keyed by automation id —
	 * drives the admin sidebar status badges.
	 *
	 * Ids are rebuilt from the path relative to the base directory, not from
	 * basename(). Extension automations are keyed `{extensionId}:{automationId}`
	 * and an extension id contains a slash, so `bsh/ops:vultr-sync` is two
	 * directories deep on disk — basename() yielded `bsh` and then found no runs
	 * under it, silently dropping every extension automation from the badges.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function latestPerAutomation(): array
	{
		$base = '.system/automations';
		if (!$this->filesystem->directoryExists($base)) {
			return [];
		}

		$latest = [];
		foreach ($this->runDirectories($base) as $id) {
			$newest = $this->newest($id);
			if ($newest !== null) {
				$latest[$id] = $newest;
			}
		}

		return $latest;
	}

	/**
	 * Automation ids that have a run directory, found by walking down from the
	 * base rather than assuming one level.
	 *
	 * Discovery stays on the filesystem deliberately: an automation that has
	 * since been deleted or had its extension removed still has history, and
	 * showing it is better than pretending it never ran.
	 *
	 * @return list<string>
	 */
	private function runDirectories(string $base, string $prefix = '', int $depth = 0): array
	{
		// Extension ids are `{vendor}/{name}`, so `{vendor}/{name}:{automation}`
		// bottoms out at two levels. The cap keeps a malformed tree from becoming
		// an unbounded walk.
		if ($depth > 2) {
			return [];
		}

		$ids = [];
		foreach ($this->filesystem->listDirectories($base) as $dir) {
			$segment = basename($dir);
			$id      = $prefix === '' ? $segment : $prefix . '/' . $segment;

			if ($this->filesystem->directoryExists($base . '/' . $segment . '/runs')) {
				$ids[] = $id;
				continue;
			}

			$ids = array_merge($ids, $this->runDirectories($base . '/' . $segment, $id, $depth + 1));
		}

		return $ids;
	}

	/**
	 * Full run history (newest first, capped) for one automation.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function history(string $id): array
	{
		$runs = [];
		foreach (array_slice($this->files($id), 0, self::HISTORY_LIMIT) as $file) {
			$record = $this->read($file);
			if ($record !== null) {
				$runs[] = $record;
			}
		}

		return $runs;
	}

	/**
	 * The most recent run record for an automation, or null if it has none.
	 *
	 * @return array<string,mixed>|null
	 */
	public function newest(string $id): ?array
	{
		$files = $this->files($id);

		return $files === [] ? null : $this->read($files[0]);
	}

	/**
	 * Run-record file paths for an automation, newest first. Run ids are
	 * time-prefixed, so a reverse lexical sort is reverse-chronological.
	 *
	 * @return list<string>
	 */
	private function files(string $id): array
	{
		$dir = '.system/automations/' . $id . '/runs';
		if (!$this->filesystem->directoryExists($dir)) {
			return [];
		}

		$files = $this->filesystem->listFiles($dir);
		rsort($files);

		return $files;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function read(string $file): ?array
	{
		try {
			$decoded = json_decode($this->filesystem->read($file), true);

			return is_array($decoded) ? $decoded : null;
		} catch (\Throwable) {
			return null;
		}
	}
}
