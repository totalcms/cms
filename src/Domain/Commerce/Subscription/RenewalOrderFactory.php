<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;

/**
 * Makes the order for one paid period of a subscription.
 *
 * Every payment has an order, so a renewal gets a receipt, tells the same
 * listeners, and can be refunded like any other. The order is built from
 * what the subscription kept at checkout, not from the catalog as it is
 * today: a subscriber goes on paying, and getting, what they signed up for.
 */
final readonly class RenewalOrderFactory
{
	public function __construct(private OrderRepository $orders)
	{
	}

	/**
	 * The id of the order for a provider's invoice. Worked out from the
	 * invoice, so however often the provider tells of one, it is one order.
	 */
	public static function orderId(string $provider, string $providerInvoiceRef): string
	{
		return substr(hash('sha256', "renewal:{$provider}:{$providerInvoiceRef}"), 0, 32);
	}

	/** The order for this event's invoice: the one already there, or a new one, waiting to be paid. */
	public function build(SubscriptionData $subscription, SubscriptionEvent $event): OrderData
	{
		$id = self::orderId($event->provider, $event->providerInvoiceRef);

		return $this->orders->find($id) ?? $this->orders->createRenewal($id, [
			'subscriptionId'     => $subscription->id(),
			'currency'           => $subscription->currency(),
			'email'              => $subscription->email(),
			'customerCollection' => $subscription->customerCollection(),
			'customerId'         => $subscription->customerId(),
			'lines'              => array_map(static fn (array $line): array => $line + ['fulfillmentStatus' => 'unfulfilled', 'fulfillmentNote' => '', 'downloads' => [], 'refundedQty' => 0], $subscription->lines()),
			'subtotal'           => $subscription->recurringSubtotal(),
			'discountTotal'      => $subscription->recurringDiscount(),
			'taxTotal'           => $subscription->recurringTax(),
			'grandTotal'         => $subscription->recurringTotal(),
			'taxMode'            => $subscription->taxMode(),
			'taxLines'           => $subscription->taxLines(),
			'discountCode'       => $subscription->discountCode(),
			'billingAddress'     => $subscription->billingAddress(),
			'provider'           => $subscription->provider(),
			// The provider's invoice is what this order's payment is known by.
			'providerSessionRef' => $event->providerInvoiceRef,
			'attributes'         => $subscription->attributes(),
			'metadata'           => $subscription->metadata(),
		]);
	}
}
