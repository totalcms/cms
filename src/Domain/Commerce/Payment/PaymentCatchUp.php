<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Order\Transition;

/**
 * Handles a provider's payment event, and first makes sure the store knows
 * about the payment the event is about.
 *
 * A provider sends each event on its own, and nothing promises their order.
 * With a card that is disputed the moment it is charged, the dispute has
 * reached the store a fraction of a second before the payment. The store
 * then still has the order as unpaid, the dispute means nothing to it, and
 * an event that is answered is never sent again: the order would be paid
 * with a dispute nobody was told about.
 *
 * So for a refund or a dispute of an order still waiting to be paid, the
 * provider is asked whether the checkout was paid. If it was, that payment
 * is handled first, exactly as its own event would be, and the event that
 * came with it follows. If the provider cannot say, the event is refused,
 * so that it is sent again: such an event exists only for a payment that
 * was made, and the order will be paid by then.
 */
final readonly class PaymentCatchUp
{
	/** The events that only ever follow a payment. */
	private const AFTER_PAYMENT = [PaymentEventType::Refunded, PaymentEventType::Disputed, PaymentEventType::DisputeWon, PaymentEventType::DisputeLost];

	private const PENDING = 'pending';

	public function __construct(
		private OrderRepository $orders,
		private PaymentProviderRegistry $providers,
		private PaymentEventProcessor $processor,
	) {
	}

	/**
	 * @throws \RuntimeException when the event is about a payment the store cannot yet confirm; the provider sends it again
	 */
	public function process(PaymentEvent $event): Transition
	{
		// An event that names no order is found by its payment, which only a paid order has.
		$order = $event->orderId !== '' && in_array($event->type, self::AFTER_PAYMENT, true) ? $this->orders->find($event->orderId) : null;

		if ($order instanceof OrderData && $order->paymentStatus() === self::PENDING) {
			$paid = $this->providers->get($order->provider())?->fetchPayment($order->providerSessionRef());
			if ($paid instanceof PaymentEvent && $paid->type === PaymentEventType::Paid) {
				$this->processor->process($paid);
			}

			if ($this->orders->find($order->id())?->paymentStatus() === self::PENDING) {
				throw new \RuntimeException("A {$event->type->value} event for order {$order->id()} arrived before its payment, and the provider could not confirm the payment yet.");
			}
		}

		return $this->processor->process($event);
	}
}
