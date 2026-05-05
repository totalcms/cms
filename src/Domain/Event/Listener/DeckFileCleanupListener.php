<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Listener;

use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Property\Service\DeckFileCleaner;

/**
 * Wires {@see DeckFileCleaner} to the `object.updated` event so deck-item
 * file orphans are removed from the filesystem after each save.
 *
 * Only runs when the payload carries both the previous and current state — the
 * fetcher in ObjectUpdater drops `previous` on first writes and missing reads.
 */
readonly class DeckFileCleanupListener
{
	public function __construct(
		private DeckFileCleaner $cleaner,
	) {
	}

	/** @param array<string,mixed> $payload */
	public function onObjectUpdated(array $payload): void
	{
		$previous = $payload['previous'] ?? null;
		$current  = $payload['object']   ?? null;

		if (!$previous instanceof ObjectData || !$current instanceof ObjectData) {
			return;
		}

		$this->cleaner->cleanup(
			(string)$payload['collection'],
			(string)$payload['id'],
			$previous,
			$current,
		);
	}
}
