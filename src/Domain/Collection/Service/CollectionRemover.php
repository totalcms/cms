<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Collection\Service;

use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Event\Payload\CollectionEventPayload;

/**
 * Service.
 */
readonly class CollectionRemover
{
	public function __construct(
		private CollectionRepository $storage,
		private EventDispatcher $eventDispatcher,
	) {
	}

	public function deleteCollection(string $collection): bool
	{
		$result = $this->storage->deleteCollection($collection);

		if ($result) {
			$this->eventDispatcher->dispatch(CoreEvent::COLLECTION_DELETED, new CollectionEventPayload($collection));
		}

		return $result;
	}
}
