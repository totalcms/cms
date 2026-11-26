<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\ProviderEventRouter;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;

/*
 * A refund or a dispute can reach the store before the payment it is about.
 *
 * A provider sends each event on its own, and nothing promises their order:
 * with a card that is disputed the moment it is charged, the dispute has
 * arrived a fraction of a second ahead of the payment. The store then still
 * has the order as unpaid, and an event it drops is never sent again. So it
 * asks the provider whether the order was paid, records that first, and
 * then handles the event it was sent.
 */

beforeEach(function (): void {
	bootCommerceStore();
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);

	$order       = $this->orders->create(commerceDraft(['mug' => 2]), 'fake');
	$this->order = $this->orders->withLock($order->id(), static fn (OrderData $fresh): OrderData => $fresh->with(['providerSessionRef' => 'sess_1']));
	$this->total = $this->order->grandTotal();

	$this->fresh  = fn (): OrderData => $this->orders->find($this->order->id());
	$this->send   = fn (PaymentEvent $event) => $this->c->get(ProviderEventRouter::class)->route($event);
	$this->types  = fn (): array => array_map(static fn (array $line): string => $line['type'] . ':' . $line['status'], $this->c->get(TransactionLedger::class)->forOrder($this->order->id()));

	// What the provider answers when asked about the order's checkout.
	$this->asked               = [];
	$this->fakeProvider->fetch = function (string $sessionRef): ?PaymentEvent {
		$this->asked[] = $sessionRef;

		return commercePaidEvent($this->order->id(), $this->total, 'session-sess_1-paid');
	};
});

test('a dispute that arrives first: the store asks the provider, records the payment, then the dispute', function (): void {
	($this->send)(commercePaymentEvent(PaymentEventType::Disputed, $this->order->id(), $this->total, 'evt_dispute'));

	$order = ($this->fresh)();

	expect($this->asked)->toBe(['sess_1'])
		->and($order->paymentStatus())->toBe('disputed')
		->and($order->needsAttention())->toBe('disputed')
		->and($order->paidAt())->not->toBe('')
		// Paid in the full sense: the buyer's receipt was queued, once.
		->and($order->hasFlag('receiptQueued'))->toBeTrue()
		->and(($this->types)())->toContain('payment:succeeded', 'dispute:pending');
});

test('the payment event itself, arriving after, changes nothing', function (): void {
	($this->send)(commercePaymentEvent(PaymentEventType::Disputed, $this->order->id(), $this->total, 'evt_dispute'));
	$before = ($this->fresh)()->toArray();

	($this->send)(commercePaidEvent($this->order->id(), $this->total, 'evt_paid'));

	$after = ($this->fresh)();

	expect($after->paymentStatus())->toBe('disputed')
		->and($after->paidAt())->toBe($before['paidAt'])
		->and(array_count_values(($this->types)())['payment:succeeded'])->toBe(1);
});

test('a refund that arrives first is caught up the same way', function (): void {
	($this->send)(commercePaymentEvent(PaymentEventType::Refunded, $this->order->id(), 500, 'evt_refund'));

	expect(($this->fresh)()->paymentStatus())->toBe('partially_refunded')
		->and(($this->fresh)()->refundedTotal())->toBe(500);
});

test('an order the store already has as paid is not asked about', function (): void {
	($this->send)(commercePaidEvent($this->order->id(), $this->total, 'evt_paid'));

	($this->send)(commercePaymentEvent(PaymentEventType::Disputed, $this->order->id(), $this->total, 'evt_dispute'));

	expect($this->asked)->toBe([])
		->and(($this->fresh)()->paymentStatus())->toBe('disputed');
});

test('a payment event is never a reason to ask', function (): void {
	($this->send)(commercePaidEvent($this->order->id(), $this->total, 'evt_paid'));

	expect($this->asked)->toBe([]);
});

// The provider could not be reached, or says nothing was paid yet. A dispute
// exists only for a payment that was made, so the order will be paid by the
// time the event is sent again: refusing it now is what keeps it.
test('when the provider cannot say the order was paid, the event is refused so that it is sent again', function (?PaymentEvent $answer): void {
	$this->fakeProvider->fetch = static fn (string $sessionRef): ?PaymentEvent => $answer;

	expect(fn () => ($this->send)(commercePaymentEvent(PaymentEventType::Disputed, $this->order->id(), $this->total, 'evt_dispute')))->toThrow(RuntimeException::class)
		->and(($this->fresh)()->paymentStatus())->toBe('pending')
		->and(($this->types)())->not->toContain('dispute:pending');
})->with([
	'no answer'       => [null],
	'not a paid one'  => [fn () => commercePaymentEvent(PaymentEventType::Expired, 'order-x', 100, 'session-sess_1-expired')],
]);

test('an order that was cancelled or failed is not waited for', function (string $status): void {
	$this->orders->withLock($this->order->id(), static fn (OrderData $fresh): OrderData => $fresh->with(['paymentStatus' => $status]));

	($this->send)(commercePaymentEvent(PaymentEventType::Disputed, $this->order->id(), $this->total, 'evt_dispute'));

	expect($this->asked)->toBe([])
		->and(($this->fresh)()->paymentStatus())->toBe($status);
})->with(['failed', 'expired']);

test('an event that names no order is left to be found by its payment, as before', function (): void {
	($this->send)(commercePaymentEvent(PaymentEventType::Disputed, '', $this->total, 'evt_dispute', paymentRef: 'pi_unknown'));

	expect($this->asked)->toBe([])
		->and(($this->fresh)()->paymentStatus())->toBe('pending');
});
