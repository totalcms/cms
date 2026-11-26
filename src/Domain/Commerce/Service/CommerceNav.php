<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Service;

use TotalCMS\Domain\Commerce\Order\OrderQuery;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionQuery;

/**
 * What the admin sidebar needs to know about the store: whether there is
 * one, and how many of its orders need someone.
 */
final readonly class CommerceNav
{
	public function __construct(
		private CommerceGate $gate,
		private OrderQuery $orders,
		private SubscriptionQuery $subscriptions,
	) {
	}

	/** Whether this site has Commerce. Without it there is no Orders item at all. */
	public function open(): bool
	{
		return $this->gate->allowed();
	}

	/** How many orders and subscriptions need someone. Never breaks a page: a store that cannot be read counts as none. */
	public function attention(): int
	{
		try {
			return $this->orders->attentionCount() + $this->subscriptions->attentionCount();
		} catch (\Throwable) {
			return 0;
		}
	}
}
