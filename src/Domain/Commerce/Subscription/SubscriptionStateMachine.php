<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEventType;
use TotalCMS\Domain\Money\Money;

/**
 * Every rule about what a subscription becomes, in one place.
 *
 * Like the order's, it reads nothing and writes nothing. Given a
 * subscription and something that happened it answers with the new status,
 * the fields that change and what must be done. A provider's own words for
 * a status are turned into the store's by the provider, not here.
 */
final readonly class SubscriptionStateMachine
{
	/** The statuses a provider may report. */
	private const REPORTED = [SubscriptionData::TRIALING, SubscriptionData::ACTIVE, SubscriptionData::PAST_DUE, SubscriptionData::PAUSED, SubscriptionData::CANCELED];

	private const DAY = 86400;

	/**
	 * The first order was paid: the subscription begins. On a free trial
	 * when its lines have one, with the day the trial ends.
	 */
	public function activate(SubscriptionData $subscription, string $providerSubscriptionRef, string $providerCustomerRef, int $now): SubscriptionTransition
	{
		// One given up on before it began can begin after all: its buyer
		// paid on a checkout they had left, and staff took the payment.
		$revived = $subscription->neverBegan();
		if ($subscription->status() !== SubscriptionData::PENDING && !$revived) {
			return SubscriptionTransition::ignored($subscription, 'not_pending');
		}

		$trial = $subscription->trialDays();
		// Only what the payment said: an event without the provider's names leaves the ones on record.
		$changes = array_filter(['providerSubscriptionRef' => $providerSubscriptionRef, 'providerCustomerRef' => $providerCustomerRef], static fn (string $ref): bool => $ref !== '');
		if ($revived) {
			$changes += ['canceledAt' => '', 'flags' => $subscription->flagsOnceBegun()];
		}
		if ($trial > 0) {
			$changes['trialEndsAt'] = date(DATE_ATOM, $now + $trial * self::DAY);
		}

		return SubscriptionTransition::to($subscription, $trial > 0 ? SubscriptionData::TRIALING : SubscriptionData::ACTIVE, $changes, [SubscriptionEffect::SyncAccess, SubscriptionEffect::DispatchCreated]);
	}

	/** The order that was to start it was given up on: it never begins, and nobody is told. */
	public function originCanceled(SubscriptionData $subscription): SubscriptionTransition
	{
		if ($subscription->status() !== SubscriptionData::PENDING) {
			return SubscriptionTransition::ignored($subscription, 'not_pending');
		}

		$fields = $subscription->givenUp(date(DATE_ATOM));

		return SubscriptionTransition::to($subscription, SubscriptionData::CANCELED, array_diff_key($fields, ['status' => 1]));
	}

	public function apply(SubscriptionData $subscription, SubscriptionEvent $event, int $now): SubscriptionTransition
	{
		$status = $subscription->status();
		if ($status === SubscriptionData::CANCELED) {
			return SubscriptionTransition::ignored($subscription, 'canceled');
		}
		if ($event->type === SubscriptionEventType::Canceled) {
			return $this->closed($subscription, $now);
		}
		// Nothing but its own first payment, or the end, happens to one that has not begun.
		if ($status === SubscriptionData::PENDING) {
			return SubscriptionTransition::ignored($subscription, 'pending');
		}

		return match ($event->type) {
			SubscriptionEventType::Renewed       => $this->renewed($subscription, $event),
			SubscriptionEventType::PaymentFailed => $this->failed($subscription, $event),
			default                              => $this->updated($subscription, $event, $now),
		};
	}

	/**
	 * A period was paid. For the amount the subscription is billed at, it
	 * carries on. For any other, its order is held for a person and the
	 * subscription is flagged: nothing is delivered on money that does not match.
	 */
	private function renewed(SubscriptionData $subscription, SubscriptionEvent $event): SubscriptionTransition
	{
		$period = array_filter(['currentPeriodEnd' => $event->currentPeriodEnd === null ? '' : date(DATE_ATOM, $event->currentPeriodEnd)]);
		// When the provider worked out tax of its own, the period is checked before that tax.
		$paid = $event->orderTotal ?? $event->amount;
		if (!$paid instanceof Money || $paid->amount() !== $subscription->recurringTotal() || $paid->currency() !== $subscription->currency()) {
			return SubscriptionTransition::to($subscription, $subscription->status(), $period, [SubscriptionEffect::RenewalReview], 'amount_mismatch');
		}

		return SubscriptionTransition::to(
			$subscription,
			SubscriptionData::ACTIVE,
			$period + ['renewalCount' => $subscription->renewalCount() + 1],
			[SubscriptionEffect::RenewalOrder, SubscriptionEffect::SyncAccess, SubscriptionEffect::DispatchRenewed],
			$subscription->needsAttention() === 'amount_mismatch' ? '' : null,
		);
	}

	/**
	 * A renewal charge failed, and the provider will try again. The
	 * subscriber is told once for an invoice, however often it is retried.
	 */
	private function failed(SubscriptionData $subscription, SubscriptionEvent $event): SubscriptionTransition
	{
		$known = $event->providerInvoiceRef !== '' && $event->providerInvoiceRef === $subscription->lastFailedInvoiceRef();
		if ($known && $subscription->status() === SubscriptionData::PAST_DUE) {
			return SubscriptionTransition::ignored($subscription, 'duplicate');
		}

		return SubscriptionTransition::to(
			$subscription,
			SubscriptionData::PAST_DUE,
			['lastFailedInvoiceRef' => $event->providerInvoiceRef],
			$known ? [] : [SubscriptionEffect::PaymentFailedEmail, SubscriptionEffect::DispatchPastDue],
		);
	}

	/** The provider says the status, the period's end or "cancel at the end" changed. */
	private function updated(SubscriptionData $subscription, SubscriptionEvent $event, int $now): SubscriptionTransition
	{
		if (!in_array($event->status, self::REPORTED, true)) {
			return SubscriptionTransition::ignored($subscription, 'status_invalid');
		}
		if ($event->status === SubscriptionData::CANCELED) {
			return $this->closed($subscription, $now);
		}

		$changes = ['cancelAtPeriodEnd' => $event->cancelAtPeriodEnd];
		if ($event->currentPeriodEnd !== null) {
			$changes['currentPeriodEnd'] = date(DATE_ATOM, $event->currentPeriodEnd);
		}

		return SubscriptionTransition::to($subscription, $event->status, $changes, [SubscriptionEffect::SyncAccess]);
	}

	private function closed(SubscriptionData $subscription, int $now): SubscriptionTransition
	{
		// One that never began ends without a word.
		$effects = $subscription->status() === SubscriptionData::PENDING ? [] : [SubscriptionEffect::SyncAccess, SubscriptionEffect::DispatchCanceled];

		return SubscriptionTransition::to($subscription, SubscriptionData::CANCELED, ['canceledAt' => date(DATE_ATOM, $now), 'cancelAtPeriodEnd' => false], $effects);
	}
}
