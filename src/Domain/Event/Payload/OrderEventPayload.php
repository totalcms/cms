<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Event\Payload;

use TotalCMS\Domain\Commerce\Order\OrderData;

/**
 * Payload for order.created, order.paid, order.fulfilled, order.refunded
 * and order.disputed.
 *
 * It carries what was bought, by whom and for how much, as plain arrays. It
 * never carries the order's access token or a provider's client secret: a
 * listener is told about an order, not given the keys to it.
 */
final readonly class OrderEventPayload extends EventPayload
{
	/** The fields of a line a listener is given. */
	private const LINE = ['productId', 'variantId', 'sku', 'title', 'type', 'qty', 'unitPrice', 'lineTotal', 'discountTotal', 'billing', 'accessGroup', 'meta', 'metadata', 'fulfillmentStatus'];

	/** @param array<string,mixed> $data */
	private function __construct(private array $data)
	{
	}

	/**
	 * @param int    $amount     what this event moved: the refund for order.refunded, the order's total otherwise
	 * @param string $collection the collection orders are kept in
	 */
	public static function fromOrder(OrderData $order, int $amount, string $transactionId, string $collection = 'orders'): self
	{
		$lines = [];
		foreach ($order->lines() as $lineId => $line) {
			$lines[$lineId] = array_intersect_key($line, array_flip(self::LINE)) + ['billing' => 'once', 'discountTotal' => 0, 'accessGroup' => '', 'meta' => [], 'metadata' => []];
		}

		return new self([
			'collection'         => $collection,
			'orderId'            => $order->id(),
			'orderNumber'        => $order->number(),
			'kind'               => $order->kind(),
			'subscriptionId'     => $order->subscriptionId(),
			'paymentStatus'      => $order->paymentStatus(),
			'fulfillmentStatus'  => $order->fulfillmentStatus(),
			'currency'           => $order->currency(),
			'grandTotal'         => $order->grandTotal(),
			'refundedTotal'      => $order->refundedTotal(),
			'discountCode'       => $order->discountCode(),
			'discountTotal'      => $order->discountTotal(),
			'amount'             => $amount,
			'email'              => $order->email(),
			'customer'           => $order->customerId() === '' ? null : ['collection' => $order->customerCollection(), 'id' => $order->customerId()],
			'provider'           => $order->provider(),
			'providerPaymentRef' => $order->providerPaymentRef(),
			'transactionId'      => $transactionId,
			'lines'              => $lines,
			'attributes'         => $order->attributes(),
			'metadata'           => $order->metadata(),
		]);
	}

	/** @return array<string,mixed> */
	public function toArray(): array
	{
		return $this->data;
	}
}
