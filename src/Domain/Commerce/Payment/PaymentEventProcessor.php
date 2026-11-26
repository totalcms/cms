<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Commerce\Payment;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\AdminEvent;
use TotalCMS\Domain\Commerce\Order\DownloadIssuer;
use TotalCMS\Domain\Commerce\Order\InventoryApplier;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderEvents;
use TotalCMS\Domain\Commerce\Order\OrderFulfillment;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Order\OrderStateMachine;
use TotalCMS\Domain\Commerce\Order\ReceiptQueuer;
use TotalCMS\Domain\Commerce\Order\SideEffect;
use TotalCMS\Domain\Commerce\Order\SideEffectRunner;
use TotalCMS\Domain\Commerce\Order\Transition;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Factory\LogChannel;
use TotalCMS\Factory\LoggerFactory;

/**
 * Acts on what a provider says happened to a payment. This is the only
 * place an order is paid, refunded or disputed.
 *
 * A provider reports an event more than once, and a request can die
 * halfway. So the work is split into steps that each leave a mark on the
 * order, and every call does only the steps that have no mark yet:
 *
 *  1. Under the order's lock: ask the state machine what the order becomes,
 *     write the ledger line, and save the order with the event's key on it.
 *     That one save is what makes the event "applied".
 *  2. Then each thing the change calls for, one at a time, each marked on
 *     the order when it is done: stock, downloads, whatever other parts of
 *     the store added, the receipt, and telling the listeners.
 *
 * An effect that changes the order runs with the order locked. Listeners
 * are told outside the lock, because one may write to the order it was told
 * about; they are told at least once, so they must cope with twice.
 *
 * If a step throws, so does this, and the caller answers the provider with
 * an error so it sends the event again.
 */
final readonly class PaymentEventProcessor
{
	/** Where an order keeps the events applied to it: flags.events[eventKey] = {effects, transactionId}. */
	private const EVENTS = 'events';

	/** Why a transition changed nothing when its event, or its acceptance, was applied before. */
	public const ALREADY_APPLIED = 'already_applied';

	private const REVIEW = 'review';

	/** The ledger line an event writes: type and status. An event that moves no money writes none. */
	private const LEDGER = [
		'paid'         => ['payment', 'succeeded'],
		'failed'       => ['payment_failed', 'failed'],
		'refunded'     => ['refund', 'succeeded'],
		'disputed'     => ['dispute', 'pending'],
		'dispute_won'  => ['dispute_closed', 'succeeded'],
		'dispute_lost' => ['dispute_closed', 'failed'],
	];

	private LoggerInterface $logger;

	public function __construct(
		private OrderRepository $orders,
		private OrderStateMachine $machine,
		private TransactionLedger $ledger,
		private SideEffectRunner $extras,
		private InventoryApplier $inventory,
		private DownloadIssuer $downloads,
		private ReceiptQueuer $receipts,
		private OrderFulfillment $fulfillment,
		private OrderEvents $events,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->channelLogger(LogChannel::Commerce);
	}

	/**
	 * @param string $actor who reported it, for the ledger; the provider's webhook unless said otherwise
	 */
	public function process(PaymentEvent $event, string $actor = ''): Transition
	{
		$found = $event->orderId !== '' ? $this->orders->find($event->orderId) : $this->orders->findByPaymentRef($event->provider, $event->providerPaymentRef);
		if (!$found instanceof OrderData) {
			// Answered as handled: sending it again will not make the order exist.
			$this->logger->error('A payment event names an order that does not exist', ['event' => $event->eventKey(), 'type' => $event->type->value, 'orderId' => $event->orderId]);

			return Transition::none('order_not_found');
		}

		$orderId    = $found->id();
		$key        = $event->eventKey();
		$transition = Transition::none('unprocessed');

		$this->orders->withLock($orderId, function (OrderData $order) use ($event, $key, $actor, &$transition): OrderData {
			if (isset($this->applied($order)[$key])) {
				$transition = Transition::ignored($order, self::ALREADY_APPLIED);

				return $order;
			}

			$transition = $this->transition($order, $event);
			if (!$transition->applied) {
				return $order;
			}

			return $this->apply($order, $event, $transition, $this->record($order, $event, $actor !== '' ? $actor : 'webhook:' . $event->provider));
		});

		return $this->finish($orderId, $event, $transition);
	}

	/**
	 * Take the payment an order was held for review with. Staff have looked
	 * at the money and decided to keep it, so the order is paid now, with
	 * everything a paid order gets: stock, downloads, the receipt, the
	 * listeners. No money moves here; the provider already has it.
	 *
	 * Like an event, it is applied once and its work is finished on a
	 * second call if the first was cut short.
	 *
	 * @param string $actor who accepted it, for the ledger
	 *
	 * @throws CommerceException when there is no such order
	 *
	 * @return Transition not applied, with `not_in_review`, for an order that is not held for review
	 */
	public function accept(string $orderId, string $actor): Transition
	{
		$event      = null;
		$transition = Transition::none('unprocessed');

		$this->orders->withLock($orderId, function (OrderData $order) use ($actor, &$event, &$transition): OrderData {
			$event = $this->heldEvent($order);
			if (isset($this->applied($order)[$event->eventKey()])) {
				$transition = Transition::ignored($order, self::ALREADY_APPLIED);

				return $order;
			}

			$transition = $this->machine->applyAdmin($order, AdminEvent::AcceptPayment);
			if (!$transition->applied) {
				return $order;
			}

			$line = $this->ledger->record($order, 'payment_accepted', 'succeeded', $event->amount, $event->providerRef, $event->eventId, $actor, []);

			// The order now belongs to the provider that has its money, so a
			// refund is asked of that one, and its webhooks find the order.
			return $this->apply($order, $event, $transition, $line)->with(['provider' => $event->provider]);
		});

		return $event instanceof PaymentEvent ? $this->finish($orderId, $event, $transition) : $transition;
	}

	/** Run what the event's transition called for, or what an earlier attempt left undone. */
	private function finish(string $orderId, PaymentEvent $event, Transition $transition): Transition
	{
		$effects = $this->effectsFor($orderId, $event, $transition);
		if (!$transition->applied && $effects === []) {
			$this->logger->info('A payment event changed nothing', ['event' => $event->eventKey(), 'type' => $event->type->value, 'orderId' => $orderId, 'reason' => $transition->reason]);
		}
		foreach ($effects as $effect) {
			$this->run($orderId, $event, $effect);
		}
		// What other parts of the store do about the order now: a
		// subscription that begins with it, a group it gives its buyer.
		$this->extras->finish($orderId, $event);
		$this->fulfillment->announce($orderId);

		return $transition;
	}

	/**
	 * The paid event an order in review was held with, to be applied now
	 * that staff accept it. It has a key of its own: the provider's event
	 * was applied when it put the order in review.
	 */
	private function heldEvent(OrderData $order): PaymentEvent
	{
		$held = $order->heldPayment();

		return new PaymentEvent(
			$held['provider'] ?? $order->provider(),
			'accept-' . $order->id(),
			PaymentEventType::Paid,
			$order->id(),
			$order->heldMoney() ?? $order->grandTotalMoney(),
			$held['paymentRef'] ?? '',
			$held['paymentRef'] ?? '',
			[],
			$held['subscriptionRef'] ?? '',
			$held['customerRef'] ?? '',
			$order->heldAmount('tax'),
			$order->heldAmount('orderTotal'),
		);
	}

	private function transition(OrderData $order, PaymentEvent $event): Transition
	{
		// An order in review answers to the provider that has its money,
		// which need not be the one it was started with.
		$inReview = $order->paymentStatus() === self::REVIEW;
		if ($event->provider === ($inReview ? $order->heldPayment()['provider'] ?? $order->provider() : $order->provider())) {
			return $this->machine->apply($order, $event);
		}

		// Another provider says it took money for this order. Nothing is
		// fulfilled on that, and a person is told.
		return $event->type === PaymentEventType::Paid && in_array($order->paymentStatus(), ['pending', 'failed'], true)
			? Transition::to($order, ['paymentStatus' => self::REVIEW, 'needsAttention' => 'provider_mismatch'], [SideEffect::AdminNotice])
			: Transition::ignored($order, 'provider_mismatch');
	}

	/** The ledger line for this event, or '' when it moved no money. */
	private function record(OrderData $order, PaymentEvent $event, string $actor): string
	{
		$line = self::LEDGER[$event->type->value] ?? null;

		return $line === null ? '' : $this->ledger->record($order, $line[0], $line[1], $event->amount, $event->providerRef, $event->eventId, $actor, $event->summary);
	}

	/** The order as the transition leaves it, with the event marked as applied. */
	private function apply(OrderData $order, PaymentEvent $event, Transition $transition, string $transactionId): OrderData
	{
		$changes = [
			'paymentStatus'     => $transition->paymentStatus,
			'fulfillmentStatus' => $transition->fulfillmentStatus,
			'status'            => $transition->status,
			'needsAttention'    => $transition->needsAttention,
			'refundedTotal'     => $transition->refundedTotal,
		];
		// The on-page checkout's secret is of no use once the order is no longer waiting to be paid.
		if ($transition->paymentStatus !== 'pending') {
			$changes['providerClientSecret'] = '';
		}
		if ($transition->paymentStatus === 'paid' && $order->paidAt() === '') {
			$changes['paidAt']             = date(DATE_ATOM);
			$changes['providerPaymentRef'] = $event->providerPaymentRef !== '' ? $event->providerPaymentRef : $order->providerPaymentRef();
			$changes += $order->taxFromPayment($event->amount->amount(), $event->taxForOrder());
		}

		$flags                       = $order->flags();
		$applied                     = $this->applied($order);
		$applied[$event->eventKey()] = ['effects' => $this->flagsFor($transition->effects), 'transactionId' => $transactionId];
		$flags[self::EVENTS]         = $applied;

		$held = $this->held($order, $event, $transition);
		if ($held !== null) {
			$flags[OrderData::HELD_PAYMENT] = $held;
		}

		return $order->with($changes + ['flags' => $flags]);
	}

	/**
	 * What the order is held for after this event, or null when the event
	 * changes nothing about it. A payment that sends the order to review is
	 * kept as the provider reported it, so staff can accept it later. A
	 * refund while the order waits is counted off what is held.
	 *
	 * @return array<string,int|string|null>|null
	 */
	private function held(OrderData $order, PaymentEvent $event, Transition $transition): ?array
	{
		$was = $order->paymentStatus() === self::REVIEW;
		if (!$was && $transition->paymentStatus === self::REVIEW) {
			return [
				'provider'        => $event->provider,
				'paymentRef'      => $event->providerPaymentRef,
				'amount'          => $event->amount->amount(),
				'currency'        => $event->amount->currency(),
				'subscriptionRef' => $event->subscriptionRef,
				'customerRef'     => $event->customerRef,
				// Kept so that accepting the payment later still gives the order its tax.
				'tax'             => $event->tax?->amount(),
				'orderTotal'      => $event->orderTotal?->amount(),
			];
		}

		$held = $order->heldPayment();
		if ($was && $held !== null && $event->type === PaymentEventType::Refunded) {
			return ['amount' => max(0, $held['amount'] - $event->amount->amount())] + $held;
		}

		return null;
	}

	/**
	 * The effects still to run for this event: the ones its transition
	 * called for, or, when it was applied in an earlier attempt, the ones
	 * that attempt left undone.
	 *
	 * @return list<string> flags, in the order their effects run
	 */
	private function effectsFor(string $orderId, PaymentEvent $event, Transition $transition): array
	{
		if ($transition->applied) {
			return $this->flagsFor($transition->effects);
		}
		if ($transition->reason === self::ALREADY_APPLIED) {
			$order   = $this->orders->find($orderId);
			$effects = $order instanceof OrderData ? ($this->applied($order)[$event->eventKey()]['effects'] ?? []) : [];

			return array_values(array_filter(is_array($effects) ? $effects : [], is_string(...)));
		}

		// A second event for a payment already counted. If the first one's
		// work was cut short and it is never sent again, this finishes it.
		return $transition->reason === 'duplicate' ? $this->flagsFor([SideEffect::Inventory, SideEffect::Downloads, SideEffect::Receipt, SideEffect::DispatchPaid]) : [];
	}

	/**
	 * The flags for a list of effects. What other parts of the store added
	 * runs after downloads and before the receipt.
	 *
	 * @param list<SideEffect> $effects
	 *
	 * @return list<string>
	 */
	private function flagsFor(array $effects): array
	{
		$flags = [];
		foreach ($effects as $effect) {
			$flags[] = $effect->value;
			if ($effect === SideEffect::Downloads) {
				array_push($flags, ...$this->extras->flags());
			}
		}

		return $flags;
	}

	private function run(string $orderId, PaymentEvent $event, string $effect): void
	{
		match ($effect) {
			SideEffect::DispatchPaid->value     => $this->tell($orderId, $effect, CoreEvent::ORDER_PAID, null, $this->paymentTransaction($orderId)),
			SideEffect::Restock->value          => $this->restock($orderId, $event),
			SideEffect::DispatchRefunded->value => $this->tell($orderId, $effect . ':' . $event->eventKey(), CoreEvent::ORDER_REFUNDED, $event->amount->amount(), $this->transactionOf($orderId, $event)),
			SideEffect::DispatchDisputed->value => $this->tell($orderId, $effect . ':' . $event->eventKey(), CoreEvent::ORDER_DISPUTED, null, $this->transactionOf($orderId, $event)),
			SideEffect::AdminNotice->value      => $this->notify($orderId, $effect . ':' . $event->eventKey()),
			default                             => $this->change($orderId, $event, $effect),
		};
	}

	/**
	 * Put a refunded order's items back into stock, when staff asked for
	 * that with the refund. Once for the order: the applier marks it.
	 */
	private function restock(string $orderId, PaymentEvent $event): void
	{
		$this->orders->withLock($orderId, fn (OrderData $order): OrderData => $this->inventory->restock($order, $event));
	}

	/** An effect that changes the order: run and marked in one locked save. */
	private function change(string $orderId, PaymentEvent $event, string $effect): void
	{
		$this->orders->withLock($orderId, function (OrderData $order) use ($event, $effect): OrderData {
			if ($order->hasFlag($effect)) {
				return $order;
			}

			$changed = match ($effect) {
				SideEffect::Inventory->value => $this->inventory->apply($order),
				SideEffect::Downloads->value => $this->downloads->issue($order),
				SideEffect::Receipt->value   => $this->queueReceipt($order),
				default                      => $this->extras->run($effect, $order, $event) ?? $order,
			};

			// An effect may have delivered a line; the order's own status follows its lines.
			return $this->fulfillment->settle($changed)->withFlag($effect);
		});
	}

	private function queueReceipt(OrderData $order): OrderData
	{
		$this->receipts->queue($order);

		return $order;
	}

	/**
	 * Tell the listeners, then mark it told. Outside the order's lock, and
	 * in that order: a listener told twice can cope, one never told cannot.
	 *
	 * @param int|null $amount what the event moved; the order's total when null
	 */
	private function tell(string $orderId, string $flag, string $event, ?int $amount, string $transactionId): void
	{
		$order = $this->orders->find($orderId);
		if (!$order instanceof OrderData || $order->hasFlag($flag)) {
			return;
		}

		$this->events->dispatch($event, $order, $amount ?? $order->grandTotal(), $transactionId);
		$this->orders->withLock($orderId, static fn (OrderData $fresh): OrderData => $fresh->withFlag($flag));
	}

	private function notify(string $orderId, string $flag): void
	{
		$order = $this->orders->find($orderId);
		if (!$order instanceof OrderData || $order->hasFlag($flag)) {
			return;
		}

		$this->logger->warning('An order needs a person to look at it', ['orderId' => $orderId, 'number' => $order->number(), 'reason' => $order->needsAttention(), 'paymentStatus' => $order->paymentStatus()]);
		$this->orders->withLock($orderId, static fn (OrderData $fresh): OrderData => $fresh->withFlag($flag));
	}

	/**
	 * The events already applied to an order.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function applied(OrderData $order): array
	{
		$events = $order->flags()[self::EVENTS] ?? [];

		return array_filter(is_array($events) ? $events : [], is_array(...));
	}

	private function transactionOf(string $orderId, PaymentEvent $event): string
	{
		$order = $this->orders->find($orderId);
		$id    = $order instanceof OrderData ? ($this->applied($order)[$event->eventKey()]['transactionId'] ?? '') : '';

		return is_string($id) ? $id : '';
	}

	/** The ledger line of the payment itself, whichever event reported it. */
	private function paymentTransaction(string $orderId): string
	{
		return $this->fulfillment->paymentTransaction($orderId);
	}
}
