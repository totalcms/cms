<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Inventory;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Order\InventoryApplier;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\SideEffect;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;
use TotalCMS\Domain\Event\Payload\StockEventPayload;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * Takes what a paid order sold out of stock, and puts it back when staff
 * said the goods are coming back.
 *
 * Selling more than there is does not undo the sale: the money is already
 * taken. The count stops at nothing and the order is flagged `oversold`,
 * for a person to choose between a refund and a wait.
 */
final readonly class StockInventoryApplier implements InventoryApplier
{
	private const OVERSOLD = 'oversold';

	/** The ledger line staff's request for a refund writes. */
	private const REFUND = 'refund';

	private LoggerInterface $logger;

	public function __construct(
		private InventoryService $inventory,
		private TransactionLedger $ledger,
		private StockEvents $events,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	public function apply(OrderData $order): OrderData
	{
		// A renewal sells nothing new: the seat was taken when the subscription started.
		if ($order->isRenewal()) {
			return $order;
		}

		$short = 0;
		foreach ($order->lines() as $line) {
			$short += $this->move($line, -1, $order->id())->short;
		}
		if ($short < 1) {
			return $order;
		}

		$this->logger->warning('An order sold more than was in stock', ['orderId' => $order->id(), 'number' => $order->number(), 'short' => $short]);

		// Another reason already on the order is the more urgent one.
		return $order->needsAttention() === '' ? $order->with(['needsAttention' => self::OVERSOLD]) : $order;
	}

	/**
	 * A refund was confirmed. When staff asked for it and said the items
	 * are coming back, they go back into stock, once for the order.
	 */
	public function restock(OrderData $order, PaymentEvent $event): OrderData
	{
		$flag = SideEffect::Restock->value;
		if ($order->hasFlag($flag) || !$this->asked($order, $event)) {
			return $order;
		}

		foreach ($order->lines() as $line) {
			$this->move($line, 1, $order->id());
		}

		return $order->withFlag($flag);
	}

	/**
	 * Change the stock of what a line sold, by the line's quantity.
	 *
	 * @param array<string,mixed> $line
	 * @param int                 $direction -1 to take it out, 1 to put it back
	 */
	private function move(array $line, int $direction, string $orderId): StockResult
	{
		$productId = is_string($line['productId'] ?? null) ? $line['productId'] : '';
		$variantId = is_string($line['variantId'] ?? null) ? $line['variantId'] : '';
		$qty       = is_numeric($line['qty'] ?? null) ? (int)$line['qty'] : 0;
		if ($productId === '' || $qty < 1) {
			return StockResult::untracked();
		}

		try {
			$result = $direction < 0 ? $this->inventory->decrement($productId, $variantId, $qty) : $this->inventory->increment($productId, $variantId, $qty);
		} catch (\Throwable $e) {
			// A product that cannot be written must not fail the payment it was sold in.
			$this->logger->error('Stock could not be changed for a paid order line', ['productId' => $productId, 'variantId' => $variantId, 'error' => $e::class]);

			return StockResult::untracked();
		}

		// The count is written and the product's lock let go: now the site is told.
		$this->events->changed($line, $result, $direction < 0 ? StockEventPayload::SALE : StockEventPayload::RESTOCK, $orderId);

		return $result;
	}

	/**
	 * Whether this refund is one staff asked for with "return the items to
	 * stock". The request is found by the provider's own name for the
	 * refund, or, when an event names it another way, by its amount.
	 */
	private function asked(OrderData $order, PaymentEvent $event): bool
	{
		$requests = array_filter(
			$this->ledger->forOrder($order->id()),
			static fn (array $line): bool => ($line['type'] ?? '') === self::REFUND
				&& is_array($line['summary'] ?? null)
				&& ($line['summary']['restock'] ?? false) === true,
		);

		foreach ($requests as $line) {
			if ($event->providerRef !== '' && ($line['providerRef'] ?? '') === $event->providerRef) {
				return true;
			}
		}
		foreach ($requests as $line) {
			if (is_numeric($line['amount'] ?? null) && (int)$line['amount'] === $event->amount->amount()) {
				return true;
			}
		}

		return false;
	}
}
