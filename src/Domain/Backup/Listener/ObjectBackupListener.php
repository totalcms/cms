<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Backup\Listener;

use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Schema\Data\SchemaData;
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
 * Off switch is `backups.enable` in settings. A store's carts are never
 * snapshotted ({@see SchemaData::keepsNoHistory()}).
 */
readonly class ObjectBackupListener
{
	public function __construct(
		private BackupStore $store,
		private Config $config,
		private CollectionFetcher $collections,
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

		$collection = (string)($payload['collection'] ?? '');
		if (!$this->keepsHistory($collection)) {
			return;
		}

		$this->store->snapshotObject(
			$collection,
			(string)($payload['id'] ?? ''),
			$previous->toArray(),
		);
	}

	/**
	 * Whether records of this collection are worth keeping copies of. It is
	 * the collection's schema that says, not its name. A collection that is
	 * gone keeps them: its records are being deleted, and their last state
	 * is what a restore needs.
	 */
	private function keepsHistory(string $collection): bool
	{
		$found = $this->collections->fetchCollection($collection);

		return !SchemaData::keepsNoHistory($found instanceof CollectionData ? $found->schema : '');
	}

	private function enabled(): bool
	{
		return (bool)($this->config->backups['enable'] ?? true);
	}
}
