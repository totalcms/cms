<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Event\Payload\SubscriptionEventPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;

/**
 * Tells the rest of the site what happened to a subscription:
 * `subscription.created`, `subscription.renewed`, `subscription.past_due`,
 * `subscription.canceled`.
 *
 * Like an order's, these are the store's own doing, so they are dispatched
 * as the system. Never call this while holding the subscription's lock: a
 * listener may write to the subscription it was told about.
 */
final readonly class SubscriptionEvents
{
	public function __construct(
		private EventDispatcher $events,
		private ActorContext $actors,
		private CommerceSettings $settings,
	) {
	}

	/** @param string $orderId the order that started it (created) or the order for this period (renewed); '' otherwise */
	public function dispatch(string $event, SubscriptionData $subscription, string $orderId = ''): void
	{
		$payload = SubscriptionEventPayload::fromSubscription($subscription, $orderId, $this->settings->collection('subscriptions'));

		$this->actors->runAs(Actor::system(), fn () => $this->events->dispatch($event, $payload));
	}
}
