<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Money\Money;

/**
 * Every rule about what an order becomes, in one place.
 *
 * It reads nothing and writes nothing. Given an order and something that
 * happened (a provider's event, or something staff did) it answers with the
 * order's new statuses and what must be done about them. The caller saves
 * and acts; the rules are the same whoever asks.
 */
final readonly class OrderStateMachine
{
	private const PENDING            = 'pending';
	public const PAID                = 'paid';
	private const FAILED             = 'failed';
	private const EXPIRED            = 'expired';
	private const REVIEW             = 'review';
	private const PARTIALLY_REFUNDED = 'partially_refunded';
	private const REFUNDED           = 'refunded';
	private const DISPUTED           = 'disputed';

	private const OPEN      = 'open';
	private const COMPLETED = 'completed';
	private const CANCELLED = 'cancelled';

	public const UNFULFILLED   = 'unfulfilled';
	public const PARTIAL       = 'partial';
	public const FULFILLED     = 'fulfilled';
	private const NOT_REQUIRED = 'not_required';

	public function apply(OrderData $order, PaymentEvent $event): Transition
	{
		return match ($event->type) {
			PaymentEventType::Paid        => $this->paid($order, $event),
			PaymentEventType::Failed      => $order->paymentStatus() === self::PENDING
				? Transition::to($order, ['paymentStatus' => self::FAILED])
				: Transition::ignored($order, 'not_pending'),
			PaymentEventType::Expired     => $this->awaitsPayment($order)
				? Transition::to($order, ['paymentStatus' => self::EXPIRED, 'status' => self::CANCELLED])
				: Transition::ignored($order, 'not_pending'),
			PaymentEventType::Refunded    => $this->refunded($order, $event),
			PaymentEventType::Disputed    => $this->wasPaid($order)
				? Transition::to($order, ['paymentStatus' => self::DISPUTED, 'needsAttention' => 'disputed'], [SideEffect::DispatchDisputed])
				: Transition::ignored($order, 'not_paid'),
			PaymentEventType::DisputeWon  => $order->paymentStatus() === self::DISPUTED
				? Transition::to($order, ['paymentStatus' => $order->refundedTotal() > 0 ? self::PARTIALLY_REFUNDED : self::PAID, 'needsAttention' => ''])
				: Transition::ignored($order, 'not_disputed'),
			PaymentEventType::DisputeLost => $order->paymentStatus() === self::DISPUTED
				? Transition::to($order, ['paymentStatus' => self::REFUNDED, 'needsAttention' => '', 'refundedTotal' => $order->grandTotal()], [SideEffect::DispatchRefunded])
				: Transition::ignored($order, 'not_disputed'),
		};
	}

	public function applyAdmin(OrderData $order, AdminEvent $event): Transition
	{
		if ($event === AdminEvent::Cancel) {
			// A paid order is not cancelled, it is refunded: the money has to go back.
			return $this->awaitsPayment($order)
				? Transition::to($order, ['paymentStatus' => self::EXPIRED, 'status' => self::CANCELLED])
				: Transition::ignored($order, 'not_pending');
		}
		if ($event === AdminEvent::AcceptPayment) {
			// Staff looked at the money and take it. An order that was given
			// up on is open again: it has been paid for.
			return $order->paymentStatus() === self::REVIEW
				? $this->nowPaid($order, self::OPEN)
				: Transition::ignored($order, 'not_in_review');
		}

		$fulfillment = $this->fulfillmentFor($order);
		if ($fulfillment === $order->fulfillmentStatus()) {
			return Transition::ignored($order, 'unchanged');
		}

		return Transition::to(
			$order,
			['fulfillmentStatus' => $fulfillment, 'status' => $this->statusFor($order->status(), $order->paymentStatus(), $fulfillment)],
			$fulfillment === self::FULFILLED ? [SideEffect::DispatchFulfilled] : [],
		);
	}

	/**
	 * What the order's lines add up to: nothing to do, nothing done yet,
	 * some of it done, or all of it.
	 */
	public function fulfillmentFor(OrderData $order): string
	{
		$todo = 0;
		$done = 0;
		foreach ($order->lines() as $line) {
			if (!$this->lineNeedsWork($line)) {
				continue;
			}
			($line['fulfillmentStatus'] ?? '') === self::FULFILLED ? $done++ : $todo++;
		}

		return match (true) {
			$todo === 0 && $done === 0 => self::NOT_REQUIRED,
			$todo === 0                => self::FULFILLED,
			$done === 0                => self::UNFULFILLED,
			default                    => self::PARTIAL,
		};
	}

	/**
	 * Whether someone has to do something to deliver the line: send a thing
	 * that ships, or hand over something digital (a file link, a license).
	 *
	 * @param array<string,mixed> $line
	 */
	public function lineNeedsWork(array $line): bool
	{
		return ($line['type'] ?? '') === 'digital' || filter_var($line['requiresShipping'] ?? false, FILTER_VALIDATE_BOOLEAN);
	}

	private function paid(OrderData $order, PaymentEvent $event): Transition
	{
		$from = $order->paymentStatus();
		if ($from === self::PAID) {
			return Transition::ignored($order, 'duplicate');
		}
		if ($from === self::REVIEW) {
			return Transition::ignored($order, 'in_review');
		}
		// The buyer paid a checkout the store had given up on. The money is
		// real, so the order must not sit there looking cancelled and unpaid.
		if ($from === self::EXPIRED) {
			return Transition::to($order, ['paymentStatus' => self::REVIEW, 'needsAttention' => 'paid_after_expiry'], [SideEffect::AdminNotice]);
		}
		if (!$this->awaitsPayment($order)) {
			return Transition::ignored($order, 'not_pending');
		}

		// What the buyer was to pay for the order itself. An order whose tax
		// was left to the provider is checked against what the provider was
		// asked for: what it charged may hold its tax on top.
		$forOrder = $order->taxMode() === TaxCalculator::PROVIDER && $event->tax instanceof Money && $event->orderTotal instanceof Money ? $event->orderTotal : $event->amount;
		if ($forOrder->amount() !== $order->grandTotal() || $forOrder->currency() !== $order->currency() || $event->amount->currency() !== $order->currency()) {
			return Transition::to($order, ['paymentStatus' => self::REVIEW, 'needsAttention' => 'amount_mismatch'], [SideEffect::AdminNotice]);
		}

		return $this->nowPaid($order, $order->status());
	}

	/** The order paid, with everything a paid order is owed. $status is what it is until it is complete. */
	private function nowPaid(OrderData $order, string $status): Transition
	{
		$fulfillment = $this->fulfillmentFor($order);

		return Transition::to(
			$order,
			['paymentStatus' => self::PAID, 'fulfillmentStatus' => $fulfillment, 'status' => $this->statusFor($status, self::PAID, $fulfillment), 'needsAttention' => ''],
			[SideEffect::Inventory, SideEffect::Downloads, SideEffect::Receipt, SideEffect::DispatchPaid],
		);
	}

	private function refunded(OrderData $order, PaymentEvent $event): Transition
	{
		if ($order->paymentStatus() === self::REVIEW) {
			return $this->refundedInReview($order, $event);
		}
		if (!$this->wasPaid($order)) {
			return Transition::ignored($order, 'not_paid');
		}
		if ($event->amount->currency() !== $order->currency()) {
			return Transition::ignored($order, 'currency_mismatch');
		}

		// Never more than was paid, whatever the provider says.
		$paid     = $order->paidTotal();
		$refunded = min($paid, $order->refundedTotal() + max(0, $event->amount->amount()));

		return Transition::to(
			$order,
			['paymentStatus' => $refunded >= $paid ? self::REFUNDED : self::PARTIALLY_REFUNDED, 'refundedTotal' => $refunded],
			[SideEffect::Restock, SideEffect::DispatchRefunded],
		);
	}

	/**
	 * Money held for an order in review went back at the provider. When
	 * all of it did, the order is closed. Nothing was sent or counted for
	 * it, so it has no refund of its own and nobody is told of one.
	 */
	private function refundedInReview(OrderData $order, PaymentEvent $event): Transition
	{
		$held = $order->heldPayment();
		if ($held === null) {
			return Transition::ignored($order, 'not_paid');
		}
		if ($event->amount->currency() !== $held['currency']) {
			return Transition::ignored($order, 'currency_mismatch');
		}

		// Part of it: the order waits for staff as before. Whoever saves the
		// order counts the refund off what is held.
		return $event->amount->amount() >= $held['amount']
			? Transition::to($order, ['paymentStatus' => self::REFUNDED, 'status' => self::CANCELLED, 'needsAttention' => ''])
			: Transition::to($order, []);
	}

	/** Paid and fully delivered is complete; nothing else moves the status here. */
	private function statusFor(string $current, string $payment, string $fulfillment): string
	{
		return $payment === self::PAID && in_array($fulfillment, [self::FULFILLED, self::NOT_REQUIRED], true) ? self::COMPLETED : $current;
	}

	private function awaitsPayment(OrderData $order): bool
	{
		return in_array($order->paymentStatus(), [self::PENDING, self::FAILED], true);
	}

	private function wasPaid(OrderData $order): bool
	{
		return in_array($order->paymentStatus(), [self::PAID, self::PARTIALLY_REFUNDED], true);
	}
}
