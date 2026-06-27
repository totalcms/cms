<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Object\Service;

use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\BulkEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;

/**
 * Batch operations over many objects in a collection.
 *
 * The expensive per-object work in a bulk delete is the index rebuild:
 * IndexBuildListener rebuilds the whole collection index on every
 * `object.deleted`. Deleting N objects naively means N full rebuilds. So we
 * suspend per-object rebuilds for the duration via
 * EventDispatcher::suspendIndexRebuild() and fire one `bulk.deleted` at the
 * end, which resumes the dispatcher-owned suspension and does a single rebuild.
 *
 * Per-object `object.deleted` events still fire inside the loop, so search,
 * MCP-subscription, dataview, and metadata listeners stay correct without any
 * special-casing — only the index rebuild is deferred.
 */
readonly class BulkObjectService
{
	public function __construct(
		private ObjectRemover $remover,
		private EventDispatcher $eventDispatcher,
	) {
	}

	/**
	 * Delete many objects from a collection in one batch.
	 *
	 * @param array<int,string> $ids
	 *
	 * @return array{deleted: list<string>, failed: list<string>}
	 */
	public function deleteMany(string $collection, array $ids): array
	{
		$deleted = [];
		$failed  = [];

		$this->eventDispatcher->suspendIndexRebuild($collection);

		try {
			foreach ($ids as $id) {
				$id = (string)$id;
				if ($id === '') {
					continue;
				}

				try {
					if ($this->remover->deleteObject($collection, $id)) {
						$deleted[] = $id;
					} else {
						$failed[] = $id;
					}
				} catch (\Throwable) {
					$failed[] = $id;
				}
			}
		} finally {
			// Fired even on partial failure: onBulkDeleted resumes the suspended
			// rebuilds and does the single index rebuild, so the suspension can
			// never leak past the batch.
			$this->eventDispatcher->dispatch(
				CoreEvent::BULK_DELETED,
				new BulkEventPayload($collection, $deleted),
			);
		}

		return ['deleted' => $deleted, 'failed' => $failed];
	}
}
