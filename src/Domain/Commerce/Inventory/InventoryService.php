<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Inventory;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Storage\FileLock;

/**
 * Counts a product's stock down and up.
 *
 * Only a product that counts stock (`trackInventory`) is changed. A product
 * with variants keeps a count on each variant (`variants.{id}.stock`); one
 * without keeps it on the product (`stock`).
 *
 * Each change reads the product from disk, changes the one number and
 * writes it back while holding a lock on the product, so two orders paid at
 * the same moment both count. The lock is a file lock: it holds on one
 * server with a local disk, which is the only layout a store supports.
 */
final readonly class InventoryService
{
	public function __construct(
		private CommerceSettings $settings,
		private ObjectFetcher $objects,
		private ObjectUpdater $updater,
		private FileLock $lock,
		private ActorContext $actors,
	) {
	}

	/**
	 * Take $qty out of stock. A product that takes back-orders goes below
	 * nothing; any other stops at nothing, and the result says how many
	 * were not there.
	 */
	public function decrement(string $productId, string $variantId, int $qty): StockResult
	{
		return $this->change($productId, $variantId, -$qty);
	}

	/** Put $qty back into stock. */
	public function increment(string $productId, string $variantId, int $qty): StockResult
	{
		return $this->change($productId, $variantId, $qty);
	}

	private function change(string $productId, string $variantId, int $by): StockResult
	{
		$products = $this->settings->collection('products');
		if ($productId === '' || $by === 0 || !$this->objects->existsObject($products, $productId)) {
			return StockResult::untracked();
		}

		return $this->lock->withLock("{$products}/{$productId}", function () use ($products, $productId, $variantId, $by): StockResult {
			$product = $this->objects->fetchObjectFromDisk($products, $productId)->toArray();
			if (($product['trackInventory'] ?? false) !== true) {
				return StockResult::untracked();
			}

			$variants = is_array($product['variants'] ?? null) ? $product['variants'] : [];
			$onVariant = $variants !== [];
			if ($onVariant && !is_array($variants[$variantId] ?? null)) {
				// The variant that was sold is gone: there is no count to change.
				return StockResult::untracked();
			}

			$had   = $onVariant ? ($variants[$variantId]['stock'] ?? 0) : ($product['stock'] ?? 0);
			$had   = is_numeric($had) ? (int)$had : 0;
			$now   = $had + $by;
			$short = 0;
			if ($by < 0 && $now < 0 && ($product['allowBackorder'] ?? false) !== true) {
				// Sold more than there was. The count stops at nothing (or
				// stays where it was, if it was already below).
				$short = min(-$by, -$now);
				$now   = min($had, 0);
			}

			if ($onVariant) {
				$variants[$variantId]['stock'] = $now;
				$product['variants']           = $variants;
			} else {
				$product['stock'] = $now;
			}

			// Bookkeeping, not an edit: written as the store, and without
			// telling the listeners a product was changed by someone.
			$this->actors->runAs(Actor::system(), fn () => $this->updater->updateObject($products, $productId, $product, silent: true));

			return new StockResult(true, $short, $now);
		});
	}
}
