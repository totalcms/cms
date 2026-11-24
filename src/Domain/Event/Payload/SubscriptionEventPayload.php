<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;

/**
 * Payload for subscription.created, subscription.renewed,
 * subscription.past_due and subscription.canceled.
 *
 * Like an order's, it never carries the subscription's access token: a
 * listener is told about a subscription, not given the keys to it.
 */
final readonly class SubscriptionEventPayload extends EventPayload
{
	/** The fields of a line a listener is given. */
	private const LINE = ['productId', 'variantId', 'sku', 'title', 'qty', 'unitPrice', 'lineTotal', 'discountTotal', 'meta', 'metadata', 'accessGroup'];

	/** @param array<string,mixed> $data */
	private function __construct(private array $data)
	{
	}

	/**
	 * @param string $orderId    the order that started it (created) or the order for this period (renewed); '' otherwise
	 * @param string $collection the collection subscriptions are kept in
	 */
	public static function fromSubscription(SubscriptionData $subscription, string $orderId = '', string $collection = 'subscriptions'): self
	{
		$lines = [];
		foreach ($subscription->lines() as $lineId => $line) {
			$lines[$lineId] = array_intersect_key($line, array_flip(self::LINE)) + ['discountTotal' => 0, 'meta' => [], 'metadata' => [], 'accessGroup' => ''];
		}

		return new self([
			'collection'        => $collection,
			'subscriptionId'    => $subscription->id(),
			'status'            => $subscription->status(),
			'email'             => $subscription->email(),
			'customer'          => $subscription->customerId() === '' ? null : ['collection' => $subscription->customerCollection(), 'id' => $subscription->customerId()],
			'currency'          => $subscription->currency(),
			'recurringTotal'    => $subscription->recurringTotal(),
			'interval'          => $subscription->interval(),
			'intervalCount'     => $subscription->intervalCount(),
			'currentPeriodEnd'  => $subscription->currentPeriodEnd() !== '' ? $subscription->currentPeriodEnd() : null,
			'cancelAtPeriodEnd' => $subscription->cancelAtPeriodEnd(),
			'renewalCount'      => $subscription->renewalCount(),
			'orderId'           => $orderId,
			'provider'          => $subscription->provider(),
			'lines'             => $lines,
		]);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return $this->data;
	}
}
