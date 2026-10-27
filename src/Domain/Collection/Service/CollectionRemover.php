<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\CollectionEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Support\Config;

/**
 * Deletes a collection's settings. Its objects and files stay on disk, so
 * creating a collection with the same id brings them back.
 *
 * Every entry point (admin, REST, MCP, CLI) comes through here, so the
 * refusals live here too: the collections the site signs people in with
 * are never deleted, since that locks everyone out. The settings are
 * snapshotted to the backup history first.
 */
readonly class CollectionRemover
{
	public function __construct(
		private CollectionRepository $storage,
		private EventDispatcher $eventDispatcher,
		private BackupStore $backups,
		private Config $config,
	) {
	}

	/**
	 * @throws \DomainException When the site signs people in with $collection
	 */
	public function deleteCollection(string $collection): bool
	{
		if (in_array($collection, $this->loginCollections(), true)) {
			throw new \DomainException("Unable to delete collection ({$collection}). The site signs people in with it.", 1);
		}

		$this->backups->backupCollectionMeta($collection);

		$result = $this->storage->deleteCollection($collection);

		if ($result) {
			$this->eventDispatcher->dispatch(CoreEvent::COLLECTION_DELETED, new CollectionEventPayload($collection));
		}

		return $result;
	}

	/**
	 * The admin login collection and the OAuth login collection.
	 *
	 * @return list<string>
	 */
	public function loginCollections(): array
	{
		return array_values(array_filter([
			(string)($this->config->auth['collection'] ?? ''),
			(string)($this->config->oauth['loginCollection'] ?? ''),
		]));
	}
}
