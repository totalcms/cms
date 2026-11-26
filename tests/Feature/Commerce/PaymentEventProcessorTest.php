<?php

declare(strict_types=1);

use Tests\Fakes\FakePaymentProvider;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderFulfillment;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Order\ReceiptQueuer;
use TotalCMS\Domain\Commerce\Order\SideEffectRunner;
use TotalCMS\Domain\Commerce\Order\Transition;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType as T;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\RefundService;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;

/*
 * A provider tells the store what happened to a payment, and may tell it
 * more than once. Whatever arrives, and however often, an order is paid
 * once, its buyer gets one receipt, and its listeners hear once; and when
 * something fails halfway, the provider's retry finishes the job.
 */

beforeEach(function (): void {
	bootCommerceStore();
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);
	$this->ledger = $this->c->get(TransactionLedger::class);
	$this->c->get(ActorContext::class)->set(Actor::anonymous());

	$this->heard = [];
	foreach ([CoreEvent::ORDER_PAID, CoreEvent::ORDER_REFUNDED, CoreEvent::ORDER_DISPUTED, CoreEvent::ORDER_FULFILLED] as $name) {
		$this->c->get(EventDispatcher::class)->listen($name, function (array $payload) use ($name): void {
			$this->heard[] = [$name, $payload];
		});
	}

	$this->order     = $this->orders->create(commerceDraft(['tee:large' => 2]), 'fake');
	$this->processor = fn (): PaymentEventProcessor => $this->c->get(PaymentEventProcessor::class);
	$this->found     = fn (): OrderData => $this->orders->find($this->order->id());
	$this->named     = fn (string $event): array => array_values(array_filter($this->heard, static fn (array $h): bool => $h[0] === $event));
	$this->paid      = fn (string $eventId = 'evt_1', int $amount = 3998): PaymentEvent => commercePaidEvent($this->order->id(), $amount, $eventId);
	$this->receipts  = fn (): array => array_values(array_filter(
		$this->c->get(JobRepository::class)->fetchPendingJobs(),
		fn ($job): bool => $job->type === 'email' && (json_decode($job->payload, true)['objectId'] ?? '') === $this->order->id(),
	));
	$this->payments = fn (string $type = 'payment'): array => array_values(array_filter($this->ledger->forOrder($this->order->id()), static fn (array $t): bool => $t['type'] === $type));
});

describe('a paid event', function (): void {
	it('pays the order, writes the ledger, queues the receipt and tells the listeners', function (): void {
		$transition = ($this->processor)()->process(($this->paid)());
		$order      = ($this->found)();

		expect($transition->applied)->toBeTrue()
			->and($order->paymentStatus())->toBe('paid')
			->and($order->paidAt())->not->toBe('')
			->and($order->providerPaymentRef())->toBe('pi_1')
			->and($order->hasFlag('inventoryApplied'))->toBeTrue()
			->and($order->hasFlag('downloadsIssued'))->toBeTrue()
			->and($order->hasFlag('receiptQueued'))->toBeTrue()
			->and($order->hasFlag('paidDispatched'))->toBeTrue()
			->and(($this->payments)())->toHaveCount(1)
			->and(($this->payments)()[0])->toMatchArray(['orderId' => $order->id(), 'provider' => 'fake', 'type' => 'payment', 'status' => 'succeeded', 'amount' => 3998, 'currency' => 'USD', 'providerEventId' => 'evt_1', 'actor' => 'webhook:fake'])
			->and(($this->receipts)())->toHaveCount(1)
			->and(json_decode(($this->receipts)()[0]->payload, true))->toMatchArray(['mailerId' => 'commerce-receipt', 'collection' => 'orders', 'objectId' => $order->id()])
			->and(($this->named)(CoreEvent::ORDER_PAID))->toHaveCount(1);
	});

	it('processes a paid event twice and applies side effects once', function (): void {
		($this->processor)()->process(($this->paid)('evt_1'));
		$second = ($this->processor)()->process(($this->paid)('evt_2')); // a different event for the same payment

		expect($second->applied)->toBeFalse()
			->and(($this->payments)())->toHaveCount(1)
			->and(($this->receipts)())->toHaveCount(1)
			->and(($this->named)(CoreEvent::ORDER_PAID))->toHaveCount(1);
	});

	it('does nothing the second time the very same event arrives', function (): void {
		($this->processor)()->process(($this->paid)('evt_1'));
		$again = ($this->processor)()->process(($this->paid)('evt_1'));

		expect($again->applied)->toBeFalse()
			->and($again->reason)->toBe('already_applied')
			->and(($this->payments)())->toHaveCount(1)
			->and(($this->named)(CoreEvent::ORDER_PAID))->toHaveCount(1);
	});

	it('tells listeners what was bought, and never the order\'s secrets', function (): void {
		$this->orders->withLock($this->order->id(), fn (OrderData $o): OrderData => $o->with(['providerClientSecret' => 'cs_secret']));
		($this->processor)()->process(($this->paid)());
		$payload = ($this->named)(CoreEvent::ORDER_PAID)[0][1];

		expect($payload)->toMatchArray([
			'collection'         => 'orders',
			'orderId'            => $this->order->id(),
			'orderNumber'        => 1001,
			'kind'               => 'checkout',
			'paymentStatus'      => 'paid',
			'currency'           => 'USD',
			'grandTotal'         => 3998,
			'refundedTotal'      => 0,
			'amount'             => 3998,
			'email'              => 'buyer@example.com',
			'customer'           => null,
			'provider'           => 'fake',
			'providerPaymentRef' => 'pi_1',
			'subscriptionId'     => '',
			'discountCode'       => '',
			'discountTotal'      => 0,
		])
			->and($payload['transactionId'])->toBe(($this->payments)()[0]['id'])
			->and($payload['lines']['l1'])->toMatchArray(['productId' => 'tee', 'variantId' => 'large', 'qty' => 2, 'unitPrice' => 1999, 'lineTotal' => 3998, 'billing' => 'once', 'discountTotal' => 0, 'accessGroup' => '', 'fulfillmentStatus' => 'unfulfilled'])
			->and($payload['actor']['kind'])->toBe('system')
			->and(json_encode($payload))->not->toContain('cs_secret', $this->order->accessToken())
			->and($payload)->not->toHaveKey('providerClientSecret')
			->and($payload)->not->toHaveKey('accessToken');
	});

	it('marks paidDispatched even when a listener throws', function (): void {
		$this->c->get(EventDispatcher::class)->listen(CoreEvent::ORDER_PAID, function (): void {
			throw new RuntimeException('the license server is down');
		});

		($this->processor)()->process(($this->paid)());
		$order = ($this->found)();

		expect($order->paymentStatus())->toBe('paid')
			->and($order->hasFlag('paidDispatched'))->toBeTrue()
			->and($order->line('l1')['fulfillmentStatus'])->toBe('unfulfilled');
	});

	it('lets a listener write back to the order it was told about', function (): void {
		$this->c->get(EventDispatcher::class)->listen(CoreEvent::ORDER_PAID, function (array $payload): void {
			$this->c->get(OrderFulfillment::class)->markLineFulfilled($payload['orderId'], 'l1', 'Issued by a listener');
		});

		($this->processor)()->process(($this->paid)());
		$order = ($this->found)();

		expect($order->line('l1'))->toMatchArray(['fulfillmentStatus' => 'fulfilled', 'fulfillmentNote' => 'Issued by a listener'])
			->and($order->fulfillmentStatus())->toBe('fulfilled')
			->and($order->status())->toBe('completed')
			->and($order->hasFlag('paidDispatched'))->toBeTrue();
	});

	it('resumes unfinished side effects on retry', function (): void {
		$this->c->set(ReceiptQueuer::class, new class($this->c->get(JobQueuer::class), $this->c->get(CommerceSettings::class)) extends ReceiptQueuer {
			public int $calls = 0;

			public function queue(OrderData $order): void
			{
				if (++$this->calls === 1) {
					throw new RuntimeException('the queue is locked');
				}
				parent::queue($order);
			}
		});

		expect(fn () => ($this->processor)()->process(($this->paid)()))->toThrow(RuntimeException::class);
		$between = ($this->found)();
		($this->processor)()->process(($this->paid)());
		$after = ($this->found)();

		expect($between->paymentStatus())->toBe('paid')
			->and($between->hasFlag('inventoryApplied'))->toBeTrue()
			->and($between->hasFlag('receiptQueued'))->toBeFalse()
			->and($between->hasFlag('paidDispatched'))->toBeFalse()
			->and($after->hasFlag('receiptQueued'))->toBeTrue()
			->and($after->hasFlag('paidDispatched'))->toBeTrue()
			->and(($this->receipts)())->toHaveCount(1)
			->and(($this->payments)())->toHaveCount(1)
			->and(($this->named)(CoreEvent::ORDER_PAID))->toHaveCount(1);
	});

	it('sends a mismatch to review and dispatches nothing', function (): void {
		$transition = ($this->processor)()->process(($this->paid)('evt_1', 100));
		$order      = ($this->found)();

		expect($transition->paymentStatus)->toBe('review')
			->and($order->paymentStatus())->toBe('review')
			->and($order->needsAttention())->toBe('amount_mismatch')
			->and($order->paidAt())->toBe('')
			->and($order->hasFlag('receiptQueued'))->toBeFalse()
			->and(($this->receipts)())->toBe([])
			->and($this->heard)->toBe([])
			->and(($this->payments)()[0]['amount'])->toBe(100);
	});

	it('sends a payment from another provider to review', function (): void {
		$event = new PaymentEvent('other', 'evt_1', T::Paid, $this->order->id(), Money::of(3998, 'USD'), 'ref', 'pi_1');
		($this->processor)()->process($event);

		expect(($this->found)()->paymentStatus())->toBe('review')
			->and(($this->found)()->needsAttention())->toBe('provider_mismatch')
			->and($this->heard)->toBe([]);
	});

	it('does not rewrite lines when the product changes later', function (): void {
		($this->processor)()->process(($this->paid)());
		$tee                               = $this->c->get(ObjectFetcher::class)->fetchObject('products', 'tee')->toArray();
		$tee['variants']['large']['price'] = 99;
		$tee['title']                      = 'Renamed';
		$this->c->get(ActorContext::class)->runAs(Actor::system(), fn () => $this->c->get(ObjectUpdater::class)->updateObject('products', 'tee', $tee));

		expect(($this->found)()->line('l1'))->toMatchArray(['unitPrice' => 1999, 'lineTotal' => 3998, 'title' => 'Tee <i>Shirt</i> — Large'])
			->and(($this->found)()->grandTotal())->toBe(3998);
	});

	it('runs the effects other parts of the store add, after downloads and before the receipt', function (): void {
		$ran = [];
		$this->c->get(SideEffectRunner::class)->register('customEffect', function (OrderData $o) use (&$ran): ?OrderData {
			$ran[] = 'custom:' . ($o->hasFlag('downloadsIssued') ? 'after-downloads' : 'before-downloads') . ':' . ($o->hasFlag('receiptQueued') ? 'after-receipt' : 'before-receipt');

			return $o->with(['notes' => 'effect ran']);
		});

		($this->processor)()->process(($this->paid)());

		expect($ran)->toBe(['custom:after-downloads:before-receipt'])
			->and(($this->found)()->notes())->toBe('effect ran')
			->and(($this->found)()->hasFlag('customEffect'))->toBeTrue();
	});
});

describe('refunds and disputes', function (): void {
	beforeEach(fn () => ($this->processor)()->process(($this->paid)()));

	it('records refunds and dispatches order.refunded with the refund amount', function (): void {
		($this->processor)()->process(commercePaymentEvent(T::Refunded, $this->order->id(), 1000, 'evt_r1'));
		($this->processor)()->process(commercePaymentEvent(T::Refunded, $this->order->id(), 2998, 'evt_r2'));
		$order    = ($this->found)();
		$refunded = ($this->named)(CoreEvent::ORDER_REFUNDED);

		expect($order->paymentStatus())->toBe('refunded')
			->and($order->refundedTotal())->toBe(3998)
			->and(($this->payments)('refund'))->toHaveCount(2)
			->and($refunded)->toHaveCount(2)
			->and($refunded[0][1]['amount'])->toBe(1000)
			->and($refunded[0][1]['paymentStatus'])->toBe('partially_refunded')
			->and($refunded[0][1]['refundedTotal'])->toBe(1000)
			->and($refunded[1][1]['amount'])->toBe(2998)
			->and($refunded[1][1]['paymentStatus'])->toBe('refunded');
	});

	it('counts a refund once however often the provider reports it', function (): void {
		$refund = commercePaymentEvent(T::Refunded, $this->order->id(), 1000, 'evt_r1');
		($this->processor)()->process($refund);
		($this->processor)()->process($refund);

		expect(($this->found)()->refundedTotal())->toBe(1000)
			->and(($this->payments)('refund'))->toHaveCount(1)
			->and(($this->named)(CoreEvent::ORDER_REFUNDED))->toHaveCount(1);
	});

	it('refunds the order a payment belongs to when the event names only the payment', function (): void {
		$event = new PaymentEvent('fake', 'evt_r1', T::Refunded, '', Money::of(500, 'USD'), 're_1', 'pi_1');
		($this->processor)()->process($event);

		expect(($this->found)()->refundedTotal())->toBe(500)->and(($this->found)()->paymentStatus())->toBe('partially_refunded');
	});

	it('ignores a refund of a payment it has never seen, without throwing', function (): void {
		$event      = new PaymentEvent('fake', 'evt_r1', T::Refunded, '', Money::of(500, 'USD'), 're_1', 'pi_unknown');
		$transition = ($this->processor)()->process($event);

		expect($transition->applied)->toBeFalse()->and($transition->reason)->toBe('order_not_found')->and(($this->found)()->refundedTotal())->toBe(0);
	});

	it('tells listeners of a dispute, and flags the order', function (): void {
		($this->processor)()->process(commercePaymentEvent(T::Disputed, $this->order->id(), 3998, 'evt_d1'));

		expect(($this->found)()->paymentStatus())->toBe('disputed')
			->and(($this->found)()->needsAttention())->toBe('disputed')
			->and(($this->named)(CoreEvent::ORDER_DISPUTED))->toHaveCount(1)
			->and(($this->payments)('dispute'))->toHaveCount(1);
	});
});

describe('an order held for review', function (): void {
	beforeEach(function (): void {
		// The provider says $30.00 was paid for an order of $39.98.
		($this->processor)()->process(($this->paid)('evt_1', 3000));
		$this->accept = fn (): Transition => ($this->processor)()->accept($this->order->id(), 'user:auth/boss');
	});

	it('is not paid, and remembers the payment it is held for', function (): void {
		$order = ($this->found)();

		expect($order->paymentStatus())->toBe('review')
			->and($order->paidAt())->toBe('')
			->and($order->heldPayment())->toMatchArray(['provider' => 'fake', 'paymentRef' => 'pi_1', 'amount' => 3000, 'currency' => 'USD'])
			->and(($this->receipts)())->toBe([])
			->and(($this->named)(CoreEvent::ORDER_PAID))->toBe([]);
	});

	it('is paid once staff accept the payment: receipt, stock, listeners and a line in the ledger saying who', function (): void {
		$transition = ($this->accept)();
		$order      = ($this->found)();

		expect($transition->applied)->toBeTrue()
			->and($order->paymentStatus())->toBe('paid')
			->and($order->needsAttention())->toBe('')
			->and($order->paidAt())->not->toBe('')
			->and($order->providerPaymentRef())->toBe('pi_1')
			->and($order->hasFlag('inventoryApplied'))->toBeTrue()
			->and($order->hasFlag('downloadsIssued'))->toBeTrue()
			->and(($this->receipts)())->toHaveCount(1)
			->and(($this->named)(CoreEvent::ORDER_PAID))->toHaveCount(1)
			->and(($this->payments)())->toHaveCount(1)
			->and(($this->payments)('payment_accepted'))->toHaveCount(1)
			->and(($this->payments)('payment_accepted')[0])->toMatchArray(['status' => 'succeeded', 'amount' => 3000, 'currency' => 'USD', 'actor' => 'user:auth/boss', 'providerRef' => 'pi_1']);
	});

	it('is accepted once, however often it is asked', function (): void {
		($this->accept)();
		$again = ($this->accept)();

		expect($again->applied)->toBeFalse()
			->and(($this->receipts)())->toHaveCount(1)
			->and(($this->named)(CoreEvent::ORDER_PAID))->toHaveCount(1)
			->and(($this->payments)('payment_accepted'))->toHaveCount(1);
	});

	it('is closed, with nothing sent and nobody told, when the provider says all of the money went back', function (): void {
		($this->processor)()->process(commercePaymentEvent(T::Refunded, $this->order->id(), 3000, 'evt_r1'));
		$order  = ($this->found)();
		$accept = ($this->accept)();

		expect($order->paymentStatus())->toBe('refunded')
			->and($order->status())->toBe('cancelled')
			->and($order->needsAttention())->toBe('')
			->and(($this->payments)('refund'))->toHaveCount(1)
			->and(($this->named)(CoreEvent::ORDER_REFUNDED))->toBe([])
			// There is no money left to accept.
			->and($accept->applied)->toBeFalse()
			->and($accept->reason)->toBe('not_in_review')
			->and(($this->found)()->paymentStatus())->toBe('refunded');
	});

	it('is held for less after part of the money went back, and is then accepted for what is left', function (): void {
		($this->processor)()->process(commercePaymentEvent(T::Refunded, $this->order->id(), 1000, 'evt_r1'));
		$held = ($this->found)()->heldPayment();
		($this->accept)();

		expect($held['amount'])->toBe(2000)
			->and(($this->found)()->paymentStatus())->toBe('paid')
			->and(($this->found)()->refundedTotal())->toBe(0)
			->and($this->c->get(RefundService::class)->refundable(($this->found)()))->toBe(2000);
	});
});

it('can accept nothing for an order that was never held for review', function (string $state): void {
	if ($state === 'paid') {
		($this->processor)()->process(($this->paid)());
	}
	$before     = ($this->found)()->toArray();
	$transition = ($this->processor)()->accept($this->order->id(), 'user:auth/boss');

	expect($transition->applied)->toBeFalse()
		->and($transition->reason)->toBe('not_in_review')
		->and(($this->found)()->toArray())->toBe($before)
		->and(($this->payments)('payment_accepted'))->toBe([]);
})->with(['pending', 'paid']);

it('moves an order to the provider that took the money, when staff accept a payment another provider reported', function (): void {
	$this->c->get(PaymentProviderRegistry::class)->register(new FakePaymentProvider(true, 'other'));
	// The order was started with "fake". "other" says it was paid.
	($this->processor)()->process(new PaymentEvent('other', 'evt_o1', T::Paid, $this->order->id(), Money::of(3998, 'USD'), 'ref_o1', 'pay_other_1'));
	$held = ($this->found)();
	($this->processor)()->accept($this->order->id(), 'user:auth/boss');
	$order = ($this->found)();

	expect($held->paymentStatus())->toBe('review')
		->and($held->needsAttention())->toBe('provider_mismatch')
		->and($held->provider())->toBe('fake')
		->and($order->paymentStatus())->toBe('paid')
		->and($order->provider())->toBe('other')
		->and($order->providerPaymentRef())->toBe('pay_other_1')
		// A refund of it at that provider now finds the order.
		->and(($this->processor)()->process(new PaymentEvent('other', 'evt_o2', T::Refunded, '', Money::of(3998, 'USD'), 're_o1', 'pay_other_1'))->applied)->toBeTrue()
		->and(($this->found)()->paymentStatus())->toBe('refunded');
});

it('closes an order in review when the other provider that took the money gives it back', function (): void {
	$this->c->get(PaymentProviderRegistry::class)->register(new FakePaymentProvider(true, 'other'));
	($this->processor)()->process(new PaymentEvent('other', 'evt_o1', T::Paid, $this->order->id(), Money::of(3998, 'USD'), 'ref_o1', 'pay_other_1'));
	($this->processor)()->process(new PaymentEvent('other', 'evt_o2', T::Refunded, $this->order->id(), Money::of(3998, 'USD'), 're_o1', 'pay_other_1'));

	expect(($this->found)()->paymentStatus())->toBe('refunded')->and(($this->found)()->status())->toBe('cancelled');
});

it('ignores an event for an unknown order without throwing', function (): void {
	$transition = ($this->processor)()->process(commercePaidEvent('no-such-order'));

	expect($transition->applied)->toBeFalse()->and($transition->reason)->toBe('order_not_found')->and($this->heard)->toBe([]);
});

describe('fulfilling lines', function (): void {
	beforeEach(function (): void {
		$this->order = $this->orders->create(commerceDraft(['tee:large' => 1, 'mug' => 1]), 'fake');
		($this->processor)()->process(commercePaidEvent($this->order->id(), 1999 + 1250));
		$this->fulfillment = $this->c->get(OrderFulfillment::class);
	});

	it('dispatches order.fulfilled once when the last line is fulfilled', function (): void {
		$first           = $this->fulfillment->markLineFulfilled($this->order->id(), 'l1', 'Posted Monday');
		$heardAfterFirst = count(($this->named)(CoreEvent::ORDER_FULFILLED));
		$this->fulfillment->markLineFulfilled($this->order->id(), 'l2');
		$this->fulfillment->markLineFulfilled($this->order->id(), 'l2');
		$order = ($this->found)();

		expect($first->fulfillmentStatus())->toBe('partial')
			->and($first->line('l1'))->toMatchArray(['fulfillmentStatus' => 'fulfilled', 'fulfillmentNote' => 'Posted Monday'])
			->and($heardAfterFirst)->toBe(0)
			->and($order->fulfillmentStatus())->toBe('fulfilled')
			->and($order->status())->toBe('completed')
			->and(($this->named)(CoreEvent::ORDER_FULFILLED))->toHaveCount(1)
			->and(($this->named)(CoreEvent::ORDER_FULFILLED)[0][1]['fulfillmentStatus'])->toBe('fulfilled');
	});

	it('refuses a line the order does not have', function (): void {
		expect(fn () => $this->fulfillment->markLineFulfilled($this->order->id(), 'l9'))
			->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe('line_not_found'));
	});

	it('can tell the paid listeners again, for staff re-running fulfillment', function (): void {
		$before = count(($this->named)(CoreEvent::ORDER_PAID));
		$this->fulfillment->redispatchPaid($this->order->id());
		$paid = ($this->named)(CoreEvent::ORDER_PAID);

		expect(count($paid))->toBe($before + 1)
			->and(end($paid)[1]['amount'])->toBe(3249)
			->and(end($paid)[1]['transactionId'])->not->toBe('');
	});
});
