<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Schema\Data\SchemaData;

/**
 * Says which collections hold the store's own records: orders, transactions,
 * carts and subscriptions. A collection is one by its schema, not its name,
 * so a site's own `orders` collection is not.
 *
 * These records are the store's bookkeeping, not content. A cart is saved
 * every time a signed-in customer changes it, so the listeners that report
 * content changes (the activity log, event automations, the search index)
 * ask here and leave those writes alone. What happens to an order is told
 * through the store's own events.
 */
final readonly class StoreRecords
{
	/** The events an object write fires. */
	private const OBJECT_EVENTS = [CoreEvent::OBJECT_CREATED, CoreEvent::OBJECT_UPDATED, CoreEvent::OBJECT_DELETED];

	public function __construct(
		private CollectionFetcher $collections,
	) {
	}

	public function holds(string $collection): bool
	{
		if ($collection === '') {
			return false;
		}

		try {
			$found = $this->collections->fetchCollection($collection);
		} catch (\Throwable) {
			// A listener asks this on every write: it must never be the reason one fails.
			return false;
		}

		return $found instanceof CollectionData && SchemaData::isSystemOnly($found->schema);
	}

	/**
	 * Whether $event is the store writing one of its own records, which is
	 * not a content change.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function isOwnWrite(string $event, array $payload): bool
	{
		$collection = $payload['collection'] ?? '';

		return in_array($event, self::OBJECT_EVENTS, true) && is_string($collection) && $this->holds($collection);
	}
}
