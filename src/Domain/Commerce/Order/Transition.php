<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

/**
 * What an order is after something happened to it, and what must be done
 * about it. When nothing applies, it is the order as it was, with the reason.
 */
final readonly class Transition
{
	/**
	 * @param bool             $applied false = the event changed nothing
	 * @param string           $reason  why it changed nothing; '' when it applied
	 * @param list<SideEffect> $effects in the order they must run
	 */
	public function __construct(
		public bool $applied,
		public string $reason,
		public string $paymentStatus,
		public string $fulfillmentStatus,
		public string $status,
		public string $needsAttention,
		public int $refundedTotal,
		public array $effects = [],
	) {
	}

	/** Nothing to change: there is no order for the event to apply to. */
	public static function none(string $reason): self
	{
		return new self(false, $reason, '', '', '', '', 0);
	}

	/** The order as it is: this event does not change it. */
	public static function ignored(OrderData $order, string $reason): self
	{
		return new self(false, $reason, $order->paymentStatus(), $order->fulfillmentStatus(), $order->status(), $order->needsAttention(), $order->refundedTotal());
	}

	/**
	 * The order with these fields changed.
	 *
	 * @param array{paymentStatus?: string, fulfillmentStatus?: string, status?: string, needsAttention?: string, refundedTotal?: int} $changes
	 * @param list<SideEffect>                                                                                                        $effects
	 */
	public static function to(OrderData $order, array $changes, array $effects = []): self
	{
		return new self(
			true,
			'',
			$changes['paymentStatus'] ?? $order->paymentStatus(),
			$changes['fulfillmentStatus'] ?? $order->fulfillmentStatus(),
			$changes['status'] ?? $order->status(),
			$changes['needsAttention'] ?? $order->needsAttention(),
			$changes['refundedTotal'] ?? $order->refundedTotal(),
			$effects,
		);
	}
}
