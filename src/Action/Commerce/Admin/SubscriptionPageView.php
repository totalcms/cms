<?php

declare(strict_types=1);

namespace TotalCMS\Action\Commerce\Admin;

use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderLabels;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionQuery;

/**
 * What the admin shows of a subscription: how its status reads, why it
 * needs someone, the payments made for it, and what can be done to it.
 */
final readonly class SubscriptionPageView
{
	/** Status → [label, badge tone]. */
	private const STATUS = [
		SubscriptionData::PENDING  => ['Starting', 'muted'],
		SubscriptionData::TRIALING => ['Free trial', 'accent'],
		SubscriptionData::ACTIVE   => ['Active', 'success'],
		SubscriptionData::PAST_DUE => ['Payment due', 'warning'],
		SubscriptionData::PAUSED   => ['Paused', 'muted'],
		SubscriptionData::CANCELED => ['Canceled', 'muted'],
	];

	/** Why a subscription needs someone → [a few words for the list, a sentence for its page]. */
	private const ATTENTION = [
		'amount_mismatch' => ['Payment mismatch', 'A renewal payment did not match what this subscription is billed at. Nothing was delivered for it. Check the payment at the provider, then accept or refund it on its order.'],
		'renewal_before_start' => ['Renewal not recorded', 'The provider took a renewal payment before this subscription\'s first order was accepted here, so that payment has no order. Accept or refund the first order, then check the payment at the provider.'],
		'grant_failed'    => ['Access not granted', 'The subscriber could not be given the access they pay for: their account is missing. Once it exists, check this subscription with the provider.'],
	];

	/** How many of a subscription's payments its page lists. */
	private const PAYMENTS_SHOWN = 50;

	public function __construct(
		private OrderRepository $orders,
		private OrderLabels $labels,
		private SubscriptionQuery $query,
		private PaymentProviderRegistry $providers,
		private CommerceSettings $settings,
	) {
	}

	/** @return array{label: string, tone: string} */
	public function status(SubscriptionData $subscription): array
	{
		[$label, $tone] = self::STATUS[$subscription->status()] ?? [ucfirst(str_replace('_', ' ', $subscription->status())), 'muted'];
		// It still runs, and the subscriber keeps what it gives: but it will not be billed again.
		if ($subscription->isLive() && $subscription->cancelAtPeriodEnd()) {
			return ['label' => 'Ends with period', 'tone' => 'warning'];
		}

		return ['label' => $label, 'tone' => $tone];
	}

	/** A few words on why it needs someone, or ''. */
	public function attention(SubscriptionData $subscription): string
	{
		$reason = $subscription->needsAttention();

		return $reason === '' ? '' : (self::ATTENTION[$reason][0] ?? ucfirst(str_replace('_', ' ', $reason)));
	}

	/**
	 * Every status, for a filter's choices.
	 *
	 * @return array<string,string>
	 */
	public function choices(): array
	{
		return array_map(static fn (array $badge): string => $badge[0], self::STATUS);
	}

	/** The plan in a few words: what its lines are called. */
	public function plan(SubscriptionData $subscription): string
	{
		return implode(', ', array_filter(array_map(static fn (array $line): string => is_string($line['title'] ?? null) ? $line['title'] : '', $subscription->lines())));
	}

	/**
	 * Everything the subscription's page shows.
	 *
	 * @return array<string,mixed>
	 */
	public function of(SubscriptionData $subscription): array
	{
		$reason   = $subscription->needsAttention();
		$provider = $this->providers->get($subscription->provider());
		$running  = $subscription->isLive() && $subscription->providerSubscriptionRef() !== '' && $this->providers->subscriptionProvider($subscription->provider()) !== null;

		return [
			'subscription'  => $subscription,
			'status'        => $this->status($subscription),
			'plan'          => $this->plan($subscription),
			'monthly'       => $this->query->monthlyValue($subscription),
			'groups'        => $subscription->grantedGroups(),
			'attention'     => $reason === '' ? ['label' => '', 'help' => ''] : [
				'label' => $this->attention($subscription),
				'help'  => self::ATTENTION[$reason][1] ?? 'This subscription needs a look: ' . str_replace('_', ' ', $reason) . '.',
			],
			'payments'      => $this->payments($subscription),
			'prefix'        => $this->settings->orderNumberPrefix(),
			'providerLabel' => $provider?->label() ?? $subscription->provider(),
			'can'           => [
				'cancel' => $running,
				'sync'   => $subscription->providerSubscriptionRef() !== '' && $this->providers->subscriptionProvider($subscription->provider()) !== null,
			],
		];
	}

	/**
	 * The orders paid, or held, for this subscription, newest first.
	 *
	 * @return list<array{order: OrderData, payment: array{label: string, tone: string}}>
	 */
	private function payments(SubscriptionData $subscription): array
	{
		$rows = array_values(array_filter($this->orders->index(), static fn (array $row): bool => ($row['subscriptionId'] ?? '') === $subscription->id()));
		usort($rows, static fn (array $a, array $b): int => (int)($b['number'] ?? 0) <=> (int)($a['number'] ?? 0));

		$payments = [];
		foreach (array_slice($rows, 0, self::PAYMENTS_SHOWN) as $row) {
			$order = $this->orders->find((string)$row['id']);
			if ($order instanceof OrderData) {
				$payments[] = ['order' => $order, 'payment' => $this->labels->payment($order->paymentStatus())];
			}
		}

		return $payments;
	}
}
