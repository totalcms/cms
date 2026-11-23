<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;
use TotalCMS\Domain\Event\Data\CoreEvent;

/**
 * Marks what an order's lines have had done for them, and keeps the order's
 * own status in step with its lines.
 *
 * Staff call it when they post a parcel. A listener calls it when it has
 * delivered what its line sold: a license issued on `order.paid`, say.
 */
final readonly class OrderFulfillment
{
	private const FULFILLED = 'fulfilled';

	public function __construct(
		private OrderRepository $orders,
		private OrderStateMachine $machine,
		private OrderEvents $events,
		private TransactionLedger $ledger,
	) {
	}

	/**
	 * @throws CommerceException when the order or the line does not exist
	 */
	public function markLineFulfilled(string $orderId, string $lineId, string $note = ''): OrderData
	{
		$order = $this->orders->withLock($orderId, function (OrderData $order) use ($lineId, $note): OrderData {
			if ($order->line($lineId) === null) {
				throw CommerceException::lineNotFound();
			}

			return $this->settle($order->withLine($lineId, ['fulfillmentStatus' => self::FULFILLED, 'fulfillmentNote' => $note]));
		});
		$this->announce($orderId);

		return $this->orders->find($orderId) ?? $order;
	}

	/**
	 * The order with its own fulfillment and status worked out from its
	 * lines. It writes nothing: the caller holds the lock and saves.
	 */
	public function settle(OrderData $order): OrderData
	{
		$transition = $this->machine->applyAdmin($order, AdminEvent::LinesFulfilled);

		return $transition->applied ? $order->withStatuses($order->paymentStatus(), $transition->fulfillmentStatus, $transition->status) : $order;
	}

	/**
	 * Tell the listeners the order is fulfilled, the first time it is.
	 * Never call it while holding the order's lock.
	 */
	public function announce(string $orderId): void
	{
		$order = $this->orders->find($orderId);
		if (!$order instanceof OrderData || $order->fulfillmentStatus() !== self::FULFILLED || $order->hasFlag(SideEffect::DispatchFulfilled->value)) {
			return;
		}

		$this->events->dispatch(CoreEvent::ORDER_FULFILLED, $order, $order->grandTotal(), $this->paymentTransaction($orderId));
		$this->orders->withLock($orderId, static fn (OrderData $fresh): OrderData => $fresh->withFlag(SideEffect::DispatchFulfilled->value));
	}

	/**
	 * Tell the `order.paid` listeners again, for staff re-running fulfillment
	 * after a listener failed. Listeners must cope with being told twice.
	 *
	 * @throws CommerceException when the order does not exist
	 */
	public function redispatchPaid(string $orderId): void
	{
		$order = $this->orders->find($orderId) ?? throw CommerceException::orderNotFound();

		$this->events->dispatch(CoreEvent::ORDER_PAID, $order, $order->grandTotal(), $this->paymentTransaction($orderId));
	}

	/** The ledger line of the order's payment, or '' when it has none. */
	public function paymentTransaction(string $orderId): string
	{
		foreach ($this->ledger->forOrder($orderId) as $line) {
			if (($line['type'] ?? '') === 'payment' && ($line['status'] ?? '') === 'succeeded') {
				return (string)($line['id'] ?? '');
			}
		}

		return '';
	}
}
