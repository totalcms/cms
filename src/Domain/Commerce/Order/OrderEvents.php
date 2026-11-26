<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Order;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Event\Payload\OrderEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;

/**
 * Tells the rest of the site what happened to an order: `order.created`,
 * `order.paid`, `order.fulfilled`, `order.refunded`, `order.disputed`.
 *
 * An order's events are the store's own doing, so they are dispatched as the
 * system, whoever's request it happened in. Never call this while holding
 * the order's lock: a listener may write to the order it was told about.
 */
final readonly class OrderEvents
{
	public function __construct(
		private EventDispatcher $events,
		private ActorContext $actors,
		private CommerceSettings $settings,
	) {
	}

	/**
	 * @param int $amount what this event moved: the refund for a refund, the order's total otherwise
	 */
	public function dispatch(string $event, OrderData $order, int $amount, string $transactionId = ''): void
	{
		$payload = OrderEventPayload::fromOrder($order, $amount, $transactionId, $this->settings->collection('orders'));

		$this->actors->runAs(Actor::system(), fn () => $this->events->dispatch($event, $payload));
	}
}
