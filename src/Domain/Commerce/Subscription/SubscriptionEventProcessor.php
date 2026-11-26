<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Subscription;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Access\AccessGrantService;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderFulfillment;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Order\OrderStateMachine;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEventType;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * Acts on what happens to a subscription. This is the only place one
 * begins, is renewed, or ends, and the only place a purchase gives or takes
 * away an access group.
 *
 * Two kinds of news arrive here. A provider's own word about a
 * subscription ({@see process()}): a period paid, a charge failed, a change,
 * the end. And the store's word about an order ({@see afterOrderEvent()}):
 * the first order paid, which is how a subscription begins, or given up on.
 *
 * A period that is paid has an order of its own, paid through
 * {@see PaymentEventProcessor} like any other, so a renewal's receipt, its
 * listeners and its refunds are the same code as a first purchase's.
 *
 * Like an order's events, a provider's are told more than once and a
 * request can die halfway. What a change calls for is written down with
 * the change, under the same lock, and crossed off as it is done. A second
 * telling changes nothing and finishes what is still written down.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") it sits where orders,
 *                                                  subscriptions, access and
 *                                                  events meet, and owns none
 */
final readonly class SubscriptionEventProcessor
{
	/** Where a subscription keeps the invoices it has counted: flags.renewals[orderId] = true. */
	private const RENEWALS = 'renewals';

	/** Where a subscription keeps the events it has told listeners of: flags.told[key] = true. */
	private const TOLD = 'told';

	/** Where a subscription keeps what a change called for and is not done yet: flags.owed[key] = {effect, ref, orderId}. */
	private const OWED = 'owed';

	private LoggerInterface $logger;

	public function __construct(
		private SubscriptionRepository $subscriptions,
		private SubscriptionStateMachine $machine,
		private RenewalOrderFactory $renewals,
		private OrderRepository $orders,
		private \Closure $payments,
		private AccessGrantService $grants,
		private PaymentFailedNotifier $failed,
		private OrderFulfillment $fulfillment,
		private SubscriptionEvents $events,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	/** What a provider said happened to a subscription. */
	public function process(SubscriptionEvent $event): SubscriptionTransition
	{
		$found = $this->subscriptions->find($event->subscriptionId) ?? $this->subscriptions->findByProviderRef($event->provider, $event->providerSubscriptionRef);
		if (!$found instanceof SubscriptionData) {
			// Answered as handled: sending it again will not make it exist.
			$this->logger->error('A subscription event names a subscription that does not exist', ['event' => $event->eventKey(), 'type' => $event->type->value, 'subscriptionId' => $event->subscriptionId]);

			return SubscriptionTransition::none('subscription_not_found');
		}

		$id      = $found->id();
		$orderId = '';
		if ($event->type === SubscriptionEventType::Renewed) {
			if ($found->status() === SubscriptionData::PENDING || $found->neverBegan()) {
				// The provider is billing a subscription the store has not begun:
				// its first payment is held, or was never told. No order is made
				// for money the store cannot place, and a person is told.
				return $this->beforeStart($found, $event);
			}
			if ($found->status() !== SubscriptionData::CANCELED) {
				$orderId = $this->renewalOrder($found, $event);
			}
		}

		$transition = SubscriptionTransition::none('unprocessed');
		$this->subscriptions->withLock($id, function (SubscriptionData $subscription) use ($event, $orderId, &$transition): SubscriptionData {
			// A period is counted once, however often the provider tells of its invoice.
			if ($orderId !== '' && $this->marked($subscription, self::RENEWALS, $orderId)) {
				$transition = SubscriptionTransition::ignored($subscription, 'duplicate');

				return $subscription;
			}

			$transition = $this->machine->apply($subscription, $event, time());
			if (!$transition->applied) {
				return $subscription;
			}

			// What the change calls for is written down with it: if this
			// request dies before it is done, the next one finishes it.
			$changed = $this->owe($subscription->with($transition->fields()), $transition->effects, $event->providerInvoiceRef, $orderId);

			return $orderId === '' ? $changed : $this->mark($changed->with(['lastOrderId' => $orderId]), self::RENEWALS, $orderId);
		});

		if (!$transition->applied && $transition->reason !== 'duplicate') {
			$this->logger->info('A subscription event changed nothing', ['event' => $event->eventKey(), 'type' => $event->type->value, 'subscriptionId' => $id, 'reason' => $transition->reason]);
		}
		$this->settle($id);

		return $transition;
	}

	/** A period paid for a subscription that has not begun: flagged for a person, once. */
	private function beforeStart(SubscriptionData $subscription, SubscriptionEvent $event): SubscriptionTransition
	{
		$this->logger->error('A renewal was paid at the provider for a subscription whose first payment the store has not accepted', ['subscriptionId' => $subscription->id(), 'invoice' => $event->providerInvoiceRef]);
		$this->subscriptions->withLock($subscription->id(), static fn (SubscriptionData $fresh): SubscriptionData => $fresh->needsAttention() === '' ? $fresh->with(['needsAttention' => 'renewal_before_start']) : $fresh);

		return SubscriptionTransition::ignored($subscription, 'not_begun');
	}

	/**
	 * A payment event for an order has been dealt with. If the order
	 * belongs to a subscription, or gives its buyer a group, this does what
	 * follows: the subscription begins when its first order is paid, never
	 * begins when that order is given up on, and the buyer's groups are
	 * made to match what they now have. Runs with no lock held.
	 */
	public function afterOrderEvent(string $orderId, PaymentEvent $event): void
	{
		$order = $this->orders->find($orderId);
		if (!$order instanceof OrderData) {
			return;
		}

		if (!$order->isRenewal() && $order->subscriptionId() !== '') {
			$this->followOrigin($order, $event);
		}
		$this->syncFor($order);
	}

	/**
	 * Make the order's buyer's groups match what they have paid for, and
	 * say so on the order: a line that sold a group is delivered once the
	 * group is given, and the order is flagged if it could not be.
	 */
	public function syncFor(OrderData $order): void
	{
		if ($order->customerId() === '' || !$this->grants->grants($order)) {
			return;
		}

		try {
			$this->grants->sync($order->customerCollection(), $order->customerId());
			$failed = false;
		} catch (CommerceException) {
			$failed = true;
			$this->logger->error('A buyer could not be given the access they paid for', ['orderId' => $order->id(), 'number' => $order->number()]);
		}

		$this->orders->withLock($order->id(), fn (OrderData $fresh): OrderData => $this->delivered($fresh, $failed));
		$this->fulfillment->announce($order->id());
	}

	/** The subscription a first order was to start: it begins when the order is paid, and never does when the order is given up on. */
	private function followOrigin(OrderData $order, PaymentEvent $event): void
	{
		$id = $order->subscriptionId();
		if (!$this->subscriptions->exists($id)) {
			return;
		}

		$paid       = $order->paymentStatus() === OrderStateMachine::PAID;
		$abandoned  = $order->paymentStatus() === 'expired';
		$transition = SubscriptionTransition::none('unprocessed');
		if ($paid || $abandoned) {
			$this->subscriptions->withLock($id, function (SubscriptionData $subscription) use ($paid, $event, &$transition): SubscriptionData {
				$transition = $paid
					? $this->machine->activate($subscription, $event->subscriptionRef, $event->customerRef, time())
					: $this->machine->originCanceled($subscription);

				// A held payment that is accepted clears what the wait for it flagged.
				return $transition->applied ? $subscription->with(['needsAttention' => $subscription->needsAttention() === 'renewal_before_start' ? '' : $transition->needsAttention] + $transition->fields()) : $subscription;
			});
		}

		$subscription = $this->subscriptions->find($id);
		if ($paid && $subscription instanceof SubscriptionData && $subscription->isLive()) {
			$this->tell($id, SubscriptionEffect::DispatchCreated->value, CoreEvent::SUBSCRIPTION_CREATED, $order->id());
		}
	}

	/**
	 * The order for a paid period: made if it is not there, then paid
	 * through the same steps as any payment. An amount that does not match
	 * sends that order to review by the order's own rule.
	 */
	private function renewalOrder(SubscriptionData $subscription, SubscriptionEvent $event): string
	{
		$order = $this->renewals->build($subscription, $event);
		$money = $event->amount ?? $subscription->recurringTotalMoney();

		($this->payments)()->process(new PaymentEvent(
			$event->provider,
			$event->eventId,
			PaymentEventType::Paid,
			$order->id(),
			$money,
			$event->providerPaymentRef,
			$event->providerPaymentRef,
			[],
			'',
			'',
			$event->tax,
			$event->orderTotal,
		));

		return $order->id();
	}

	/**
	 * Write down what a change calls for, on the subscription the change is
	 * saved with. Making or holding a renewal's order is not written down:
	 * that was done before the subscription changed.
	 *
	 * @param list<SubscriptionEffect> $effects
	 */
	private function owe(SubscriptionData $subscription, array $effects, string $invoiceRef, string $orderId): SubscriptionData
	{
		$flags = $subscription->flags();
		$owed  = is_array($flags[self::OWED] ?? null) ? $flags[self::OWED] : [];
		foreach ($effects as $effect) {
			if ($effect === SubscriptionEffect::RenewalOrder || $effect === SubscriptionEffect::RenewalReview) {
				continue;
			}
			$owed[$effect->value . ':' . ($orderId !== '' ? $orderId : $invoiceRef)] = ['effect' => $effect->value, 'ref' => $invoiceRef, 'orderId' => $orderId];
		}

		return $owed === [] ? $subscription : $subscription->with(['flags' => [self::OWED => $owed] + $flags]);
	}

	/**
	 * Do everything written down for a subscription, crossing each thing
	 * off once it is done. One that fails stays written down, and the
	 * failure goes back to the provider, which tells the event again.
	 */
	private function settle(string $id): void
	{
		$owed = $this->subscriptions->find($id)?->flags()[self::OWED] ?? [];
		foreach (is_array($owed) ? $owed : [] as $key => $entry) {
			if (is_array($entry)) {
				$this->perform($id, SubscriptionEffect::tryFrom((string)($entry['effect'] ?? '')), (string)($entry['ref'] ?? ''), (string)($entry['orderId'] ?? ''));
			}
			$this->subscriptions->withLock($id, function (SubscriptionData $fresh) use ($key): SubscriptionData {
				$flags = $fresh->flags();
				$left  = is_array($flags[self::OWED] ?? null) ? $flags[self::OWED] : [];
				unset($left[$key]);

				return $fresh->with(['flags' => [self::OWED => $left] + $flags]);
			});
		}
	}

	private function perform(string $id, ?SubscriptionEffect $effect, string $invoiceRef, string $orderId): void
	{
		match ($effect) {
			SubscriptionEffect::SyncAccess         => $this->syncAccess($id),
			SubscriptionEffect::PaymentFailedEmail => $this->notifyFailed($id),
			SubscriptionEffect::DispatchPastDue    => $this->tell($id, $effect->value . ':' . $invoiceRef, CoreEvent::SUBSCRIPTION_PAST_DUE),
			SubscriptionEffect::DispatchCanceled   => $this->tell($id, $effect->value, CoreEvent::SUBSCRIPTION_CANCELED),
			SubscriptionEffect::DispatchRenewed    => $this->renewed($id, $orderId),
			default                                => null,
		};
	}

	/** Tell the listeners a period was paid, once its order really is. */
	private function renewed(string $id, string $orderId): void
	{
		if ($this->orders->find($orderId)?->paymentStatus() === OrderStateMachine::PAID) {
			$this->tell($id, SubscriptionEffect::DispatchRenewed->value . ':' . $orderId, CoreEvent::SUBSCRIPTION_RENEWED, $orderId);
		}
	}

	private function notifyFailed(string $id): void
	{
		$subscription = $this->subscriptions->find($id);
		if ($subscription instanceof SubscriptionData) {
			$this->failed->notify($subscription);
		}
	}

	/** Make the subscriber's groups match what they pay for, and flag the subscription if that cannot be done. */
	public function syncAccess(string $id): void
	{
		$subscription = $this->subscriptions->find($id);
		if (!$subscription instanceof SubscriptionData || $subscription->customerId() === '' || $subscription->grantedGroups() === []) {
			return;
		}

		try {
			$this->grants->sync($subscription->customerCollection(), $subscription->customerId());
			$failed = false;
		} catch (CommerceException) {
			$failed = true;
			$this->logger->error('A subscriber\'s access could not be changed', ['subscriptionId' => $id]);
		}

		$this->subscriptions->withLock($id, static function (SubscriptionData $fresh) use ($failed): SubscriptionData {
			if ($failed) {
				return $fresh->needsAttention() === '' ? $fresh->with(['needsAttention' => 'grant_failed']) : $fresh;
			}

			return $fresh->needsAttention() === 'grant_failed' ? $fresh->with(['needsAttention' => '']) : $fresh;
		});
	}

	/**
	 * The order once its buyer has, or could not be given, their group.
	 * A paid line that sold a group is delivered when the group is given.
	 */
	private function delivered(OrderData $order, bool $failed): OrderData
	{
		if ($failed) {
			return $order->needsAttention() === '' ? $order->with(['needsAttention' => 'grant_failed']) : $order;
		}
		if ($order->needsAttention() === 'grant_failed') {
			$order = $order->with(['needsAttention' => '']);
		}
		if (!in_array($order->paymentStatus(), ['paid', 'partially_refunded'], true)) {
			return $order;
		}

		foreach ($order->lines() as $lineId => $line) {
			$sold = is_string($line['accessGroup'] ?? null) && $line['accessGroup'] !== '';
			if ($sold && ($line['fulfillmentStatus'] ?? '') !== OrderStateMachine::FULFILLED) {
				$order = $order->withLine((string)$lineId, ['fulfillmentStatus' => OrderStateMachine::FULFILLED, 'fulfillmentNote' => 'Access given']);
			}
		}

		return $this->fulfillment->settle($order);
	}

	/**
	 * Tell the listeners, then mark it told. Outside any lock, and in that
	 * order: a listener told twice can cope, one never told cannot.
	 */
	private function tell(string $id, string $key, string $event, string $orderId = ''): void
	{
		$subscription = $this->subscriptions->find($id);
		if (!$subscription instanceof SubscriptionData || $this->marked($subscription, self::TOLD, $key)) {
			return;
		}

		$this->events->dispatch($event, $subscription, $orderId);
		$this->subscriptions->withLock($id, fn (SubscriptionData $fresh): SubscriptionData => $this->mark($fresh, self::TOLD, $key));
	}

	private function marked(SubscriptionData $subscription, string $kind, string $key): bool
	{
		$marks = $subscription->flags()[$kind] ?? [];

		return is_array($marks) && ($marks[$key] ?? false) === true;
	}

	private function mark(SubscriptionData $subscription, string $kind, string $key): SubscriptionData
	{
		$flags = $subscription->flags();
		$marks = is_array($flags[$kind] ?? null) ? $flags[$kind] : [];

		return $subscription->with(['flags' => [$kind => [$key => true] + $marks] + $flags]);
	}
}
