<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Backup\Listener;

use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Support\Config;

/**
 * Keeps an object's history as it changes: its pre-save state on every
 * `object.updated`, and its final state on `object.deleted`.
 *
 * Both read `previous` off the event payload rather than the file on disk —
 * by the time either event fires the file is already the new version, or
 * gone. A payload without `previous` (a first write, or a delete of a record
 * the repository could not read) has nothing worth keeping and is skipped.
 *
 * Creates are not snapshotted: the initial state is recoverable from the
 * first update's `previous`, and doubling writes on import-heavy sites buys
 * nothing. Import-time writes never reach here at all — the dispatcher
 * suppresses `object.updated` for a collection mid-import.
 *
 * Off switch is `backups.enable` in settings.
 */
readonly class ObjectBackupListener
{
	public function __construct(
		private BackupStore $store,
		private Config $config,
	) {
	}

	/** @param array<string,mixed> $payload */
	public function onObjectUpdated(array $payload): void
	{
		$this->snapshot($payload);
	}

	/** @param array<string,mixed> $payload */
	public function onObjectDeleted(array $payload): void
	{
		$this->snapshot($payload);
	}

	/** @param array<string,mixed> $payload */
	private function snapshot(array $payload): void
	{
		if (!$this->enabled()) {
			return;
		}

		$previous = $payload['previous'] ?? null;
		if (!$previous instanceof ObjectData) {
			return;
		}

		$this->store->snapshotObject(
			(string)($payload['collection'] ?? ''),
			(string)($payload['id'] ?? ''),
			$previous->toArray(),
		);
	}

	private function enabled(): bool
	{
		return (bool)($this->config->backups['enable'] ?? true);
	}
}
