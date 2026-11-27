<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Commerce\Data\AttentionDismissal;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Payment\ProviderException;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;
use TotalCMS\Domain\Commerce\Payment\SubscriptionProvider;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * What a person can do to a subscription: ask for it to end, ask the
 * provider where it stands, and mark one that needed someone as handled.
 *
 * None of them writes the subscription's status. Canceling asks the provider,
 * and the subscription changes when the provider's own word comes back.
 * Checking takes the provider's word now, through the same steps as its
 * webhook: for a store whose webhook was missed.
 */
final readonly class SubscriptionAdmin
{
	private LoggerInterface $logger;

	public function __construct(
		private SubscriptionRepository $subscriptions,
		private PaymentProviderRegistry $providers,
		private SubscriptionEventProcessor $processor,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	/**
	 * Mark a subscription that needs someone as handled: it leaves the
	 * count, and keeps why it was there and who dealt with it. Nothing is
	 * asked of the provider and its status does not change. One that needs
	 * nobody is left exactly as it is.
	 *
	 * @throws CommerceException `subscription_not_found`
	 */
	public function dismiss(string $subscriptionId, Actor $by): void
	{
		$subscription = $this->subscriptions->find($subscriptionId) ?? throw CommerceException::subscriptionNotFound();
		if ($subscription->needsAttention() === '') {
			return;
		}

		$this->subscriptions->withLock($subscriptionId, static fn (SubscriptionData $fresh): SubscriptionData => $fresh->needsAttention() === ''
			? $fresh
			: $fresh->withAttentionDismissed($fresh->needsAttention(), AttentionDismissal::actor($by)));
	}

	/**
	 * Ask the provider to cancel: at the end of the period paid for, or at once.
	 *
	 * @throws CommerceException `subscription_not_found`, `subscription_not_live`, or `provider_error` when the provider will not
	 */
	public function cancel(string $subscriptionId, bool $atPeriodEnd): void
	{
		$subscription = $this->subscriptions->find($subscriptionId) ?? throw CommerceException::subscriptionNotFound();
		if (!$subscription->isLive() || $subscription->providerSubscriptionRef() === '') {
			throw CommerceException::subscriptionNotLive();
		}

		try {
			$this->provider($subscription, CommerceException::subscriptionCancelFailed())->cancelSubscription($subscription->providerSubscriptionRef(), $atPeriodEnd);
		} catch (ProviderException $e) {
			$this->logger->error('A provider would not cancel a subscription', ['subscriptionId' => $subscriptionId, 'provider' => $subscription->provider(), 'code' => $e->providerCode]);

			throw CommerceException::subscriptionCancelFailed();
		}
	}

	/**
	 * Ask the provider where the subscription stands, and make the store's
	 * record and the subscriber's access match.
	 *
	 * @throws CommerceException `subscription_not_found`, or `provider_error` when the provider cannot say
	 */
	public function sync(string $subscriptionId): void
	{
		$subscription = $this->subscriptions->find($subscriptionId) ?? throw CommerceException::subscriptionNotFound();
		$event        = $subscription->providerSubscriptionRef() === ''
			? null
			: $this->provider($subscription, CommerceException::subscriptionSyncFailed())->fetchSubscription($subscription->providerSubscriptionRef());
		if (!$event instanceof SubscriptionEvent) {
			throw CommerceException::subscriptionSyncFailed();
		}

		$this->processor->process($event);
		// Whatever changed, and even if nothing did: access is put right.
		$this->processor->syncAccess($subscriptionId);
	}

	/** @throws CommerceException $otherwise, when the subscription's provider is not there to ask */
	private function provider(SubscriptionData $subscription, CommerceException $otherwise): SubscriptionProvider
	{
		return $this->providers->subscriptionProvider($subscription->provider()) ?? throw $otherwise;
	}
}
