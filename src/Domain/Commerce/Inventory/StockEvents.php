<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Inventory;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\StockEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;

/**
 * Tells the rest of the site about stock the store changed.
 *
 * A sale writes the new count without `object.updated`: that would run
 * every automation on the product, and take a backup of it, for each thing
 * sold. These are what to listen for instead:
 *
 * - `stock.changed`: every change, with the count before and after.
 * - `stock.out`: once, as a count comes down to nothing or below.
 * - `stock.low`: once, as a count comes down to the store's Low Stock At
 *   and something is still left.
 *
 * They are the store's own doing, so they are dispatched as the system.
 * Never call this while holding the product's lock: a listener may write to
 * the product it was told about.
 */
final readonly class StockEvents
{
	public function __construct(
		private EventDispatcher $events,
		private ActorContext $actors,
		private CommerceSettings $settings,
	) {
	}

	/**
	 * @param array<string,mixed> $line   the order line that was sold or returned
	 * @param string              $reason StockEventPayload::SALE or ::RESTOCK
	 */
	public function changed(array $line, StockResult $result, string $reason, string $orderId): void
	{
		if (!$result->tracked || $result->before === $result->stock) {
			return;
		}

		$lowAt   = $this->settings->lowStockAt();
		$payload = new StockEventPayload(
			$this->settings->collection('products'),
			is_string($line['productId'] ?? null) ? $line['productId'] : '',
			is_string($line['variantId'] ?? null) ? $line['variantId'] : '',
			is_string($line['title'] ?? null) ? $line['title'] : '',
			is_string($line['sku'] ?? null) ? $line['sku'] : '',
			$result->before,
			$result->stock,
			$reason,
			$orderId,
			$lowAt,
		);

		$told = [CoreEvent::STOCK_CHANGED];
		if ($result->before > 0 && $result->stock <= 0) {
			$told[] = CoreEvent::STOCK_OUT;
		} elseif ($result->stock > 0 && $result->before > $lowAt && $result->stock <= $lowAt) {
			$told[] = CoreEvent::STOCK_LOW;
		}

		$this->actors->runAs(Actor::system(), function () use ($told, $payload): void {
			foreach ($told as $event) {
				$this->events->dispatch($event, $payload);
			}
		});
	}
}
