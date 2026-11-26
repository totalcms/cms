<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

/**
 * Payload for stock.changed, stock.low and stock.out: a count the store
 * changed itself, for a sale or for goods coming back.
 */
final readonly class StockEventPayload extends EventPayload
{
	/** A paid order took it out of stock. */
	public const SALE = 'sale';

	/** A refund's goods went back into stock. */
	public const RESTOCK = 'restock';

	/**
	 * @param string $collection the collection products are kept in
	 * @param string $variantId  the variant whose count it was; '' for a product without variants
	 * @param int    $before     the count before the change
	 * @param int    $after      the count after it
	 * @param string $reason     `sale` or `restock`
	 * @param string $orderId    the order that caused it
	 * @param int    $lowStockAt the store's Low Stock At when it happened; 0 when the store has none
	 */
	public function __construct(
		private string $collection,
		private string $productId,
		private string $variantId,
		private string $title,
		private string $sku,
		private int $before,
		private int $after,
		private string $reason,
		private string $orderId,
		private int $lowStockAt,
	) {
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return [
			'collection' => $this->collection,
			'productId'  => $this->productId,
			'variantId'  => $this->variantId,
			'title'      => $this->title,
			'sku'        => $this->sku,
			'before'     => $this->before,
			'after'      => $this->after,
			'change'     => $this->after - $this->before,
			'reason'     => $this->reason,
			'orderId'    => $this->orderId,
			'lowStockAt' => $this->lowStockAt,
		];
	}
}
