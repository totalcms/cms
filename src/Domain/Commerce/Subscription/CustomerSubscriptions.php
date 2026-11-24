<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

use TotalCMS\Domain\Commerce\Order\OrderAccess;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Service\CurrentCustomer;
use TotalCMS\Support\Config;

/**
 * A subscriber's own subscriptions, as a page may show them.
 *
 * A subscription opens for the customer it belongs to, when they are
 * signed in, and for anyone holding its signed link. What comes out is
 * what a subscriber may see: never the subscription's secret, or the
 * provider's names for it.
 */
final readonly class CustomerSubscriptions
{
	/** How many of a subscription's payments its page lists. */
	private const PAYMENTS_SHOWN = 24;

	public function __construct(
		private SubscriptionRepository $subscriptions,
		private SubscriptionAccess $access,
		private CurrentCustomer $customer,
		private OrderRepository $orders,
		private OrderAccess $orderAccess,
		private Config $config,
	) {
	}

	/**
	 * The subscription, when whoever asks may open it: its signed link
	 * ($token), or the signed-in customer it belongs to.
	 */
	public function opened(string $subscriptionId, string $token = ''): ?SubscriptionData
	{
		$byLink = $this->access->verify($subscriptionId, $token);
		if ($byLink instanceof SubscriptionData) {
			return $byLink;
		}

		$customer = $this->customer->get();
		if ($customer === null || $subscriptionId === '') {
			return null;
		}

		$subscription = $this->subscriptions->find($subscriptionId);
		if (!$subscription instanceof SubscriptionData) {
			return null;
		}

		return $subscription->customerId() === $customer['id'] && $subscription->customerCollection() === $customer['collection'] ? $subscription : null;
	}

	/**
	 * The subscription with this id, for whoever holds its secret: the
	 * store's own mailer, which is given the whole record. Anyone else gets nothing.
	 */
	public function bySecret(string $subscriptionId, string $accessToken): ?SubscriptionData
	{
		$subscription = $subscriptionId !== '' && $accessToken !== '' ? $this->subscriptions->find($subscriptionId) : null;

		return $subscription instanceof SubscriptionData && $subscription->accessToken() !== '' && hash_equals($subscription->accessToken(), $accessToken) ? $subscription : null;
	}

	/**
	 * The signed-in customer's subscriptions, newest first. None for a guest.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function mine(): array
	{
		$customer = $this->customer->get();
		if ($customer === null) {
			return [];
		}

		$mine = $this->subscriptions->forCustomer($customer['collection'], $customer['id']);
		usort($mine, static fn (SubscriptionData $a, SubscriptionData $b): int => strcmp($b->created(), $a->created()));

		return array_map($this->summary(...), $mine);
	}

	/** @return array<string,mixed>|null */
	public function byLink(string $subscriptionId, string $token = ''): ?array
	{
		$subscription = $this->opened($subscriptionId, $token);

		return $subscription instanceof SubscriptionData ? $this->view($subscription) : null;
	}

	/**
	 * One subscription with the payments made for it, each
	 * `{number, created, grandTotal, currency, paymentStatus, kind, url}`, newest first.
	 *
	 * @return array<string,mixed>
	 */
	public function view(SubscriptionData $subscription): array
	{
		$payments = array_values(array_filter($this->orders->index(), static fn (array $row): bool => ($row['subscriptionId'] ?? '') === $subscription->id()));
		usort($payments, static fn (array $a, array $b): int => (int)($b['number'] ?? 0) <=> (int)($a['number'] ?? 0));

		$orders = [];
		foreach (array_slice($payments, 0, self::PAYMENTS_SHOWN) as $row) {
			$order = $this->orders->find((string)$row['id']);
			if (!$order instanceof OrderData) {
				continue;
			}
			$orders[] = [
				'number'        => $order->number(),
				'created'       => $order->created(),
				'grandTotal'    => $order->grandTotal(),
				'currency'      => $order->currency(),
				'paymentStatus' => $order->paymentStatus(),
				'kind'          => $order->kind(),
				'url'           => $this->orderUrl($order),
			];
		}

		return ['orders' => $orders] + $this->summary($subscription);
	}

	/** The signed address of a subscription's own page. */
	public function url(SubscriptionData $subscription): string
	{
		return $this->access->url($subscription);
	}

	/** The signed address of the page of the subscription an order belongs to; '' when it belongs to none. */
	public function urlForOrder(OrderData $order): string
	{
		$subscription = $order->subscriptionId() === '' ? null : $this->subscriptions->find($order->subscriptionId());

		return $subscription instanceof SubscriptionData ? $this->access->url($subscription) : '';
	}

	/** @return array<string,mixed> */
	private function summary(SubscriptionData $subscription): array
	{
		return ['url' => $this->access->url($subscription)] + $subscription->forSubscriber();
	}

	private function orderUrl(OrderData $order): string
	{
		return rtrim($this->config->url, '/') . rtrim($this->config->api, '/') . "/commerce/return/{$order->id()}?t=" . rawurlencode($this->orderAccess->token($order));
	}
}
