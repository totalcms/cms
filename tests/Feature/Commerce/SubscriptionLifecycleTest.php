<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\ProviderEventRouter;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEventType as T;
use TotalCMS\Domain\Commerce\Payment\TransactionLedger;
use TotalCMS\Domain\Commerce\Subscription\RenewalOrderFactory;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionRepository;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;

use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * A subscription's life, as the provider tells it: the first payment that
 * begins it, the periods paid after, a charge that fails, and the end.
 *
 * Every period paid is an order of its own, so it has a receipt, its
 * listeners hear of it, and it can be refunded. A provider tells of each
 * thing more than once; each is done once.
 *
 * The club is $10.00 a month.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore();
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);
	$this->subs   = $this->c->get(SubscriptionRepository::class);
	$this->sub    = enableSubscriptions();
	seedPlan('club', 1000);
	$session = $this->c->get(PhpSession::class);
	$session->isStarted() || $session->start();
	withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());

	$this->heard = [];
	foreach ([CoreEvent::ORDER_PAID, CoreEvent::SUBSCRIPTION_CREATED, CoreEvent::SUBSCRIPTION_RENEWED, CoreEvent::SUBSCRIPTION_PAST_DUE, CoreEvent::SUBSCRIPTION_CANCELED] as $name) {
		$this->c->get(EventDispatcher::class)->listen($name, function (array $payload) use ($name): void {
			$this->heard[] = [$name, $payload];
		});
	}
	$this->named = fn (string $event): array => array_values(array_filter($this->heard, static fn (array $h): bool => $h[0] === $event));
	$this->route = fn (PaymentEvent|SubscriptionEvent $event): bool => $this->c->get(ProviderEventRouter::class)->route($event);

	// The buyer checks out, and the provider is asked for a subscription.
	postJson('/commerce/cart/add', ['productId' => 'club', 'variantId' => '', 'qty' => 1]);
	post('/commerce/checkout', ['provider' => 'fake-sub', 'email' => 'a@example.com'], ['Accept' => 'application/json']);
	$this->subId  = (string)$this->subs->index()[0]['id'];
	$this->origin = $this->orders->find($this->subs->find($this->subId)->originOrderId());
	$this->kept   = fn (): SubscriptionData => $this->subs->find($this->subId);

	$this->firstPayment = fn (string $eventId = 'evt_first'): PaymentEvent => new PaymentEvent('fake-sub', $eventId, PaymentEventType::Paid, $this->origin->id(), Money::of(1000, 'USD'), 'pi_first', 'pi_first', [], 'sub_1', 'cus_1');
	$this->event        = fn (T $type, array $with = []): SubscriptionEvent => new SubscriptionEvent(...$with + ['provider' => 'fake-sub', 'eventId' => 'evt_' . bin2hex(random_bytes(4)), 'type' => $type, 'subscriptionId' => $this->subId, 'providerSubscriptionRef' => 'sub_1']);
	$this->renewal      = fn (string $invoice = 'in_2', int $amount = 1000, array $with = []): SubscriptionEvent => ($this->event)(T::Renewed, $with + ['amount' => Money::of($amount, 'USD'), 'providerInvoiceRef' => $invoice, 'providerPaymentRef' => 'pi_' . $invoice, 'currentPeriodEnd' => 1900000000]);
	$this->renewalOrder = fn (string $invoice = 'in_2'): ?OrderData => $this->orders->find(RenewalOrderFactory::orderId('fake-sub', $invoice));
	$this->emails       = fn (): array => array_values(array_filter(
		array_map(static fn ($job): array => ['type' => $job->type] + (array)json_decode($job->payload, true), $this->c->get(JobRepository::class)->fetchPendingJobs()),
		static fn (array $job): bool => $job['type'] === 'email',
	));
	$this->emailsFor = fn (string $objectId): array => array_values(array_filter(($this->emails)(), static fn (array $job): bool => ($job['objectId'] ?? '') === $objectId));
});

describe('the first payment', function (): void {
	it('begins the subscription, with the provider\'s names for it', function (): void {
		($this->route)(($this->firstPayment)());
		$sub = ($this->kept)();

		expect($sub->status())->toBe('active')
			->and($sub->providerSubscriptionRef())->toBe('sub_1')
			->and($sub->providerCustomerRef())->toBe('cus_1')
			->and($this->orders->find($this->origin->id())->paymentStatus())->toBe('paid')
			->and($this->subs->findByProviderRef('fake-sub', 'sub_1')?->id())->toBe($this->subId);
	});

	it('tells the listeners a subscription was created, once, after the order was paid', function (): void {
		($this->route)(($this->firstPayment)());
		($this->route)(($this->firstPayment)('evt_again'));

		$created = ($this->named)(CoreEvent::SUBSCRIPTION_CREATED);

		expect(array_column($this->heard, 0))->toBe([CoreEvent::ORDER_PAID, CoreEvent::SUBSCRIPTION_CREATED])
			->and($created[0][1])->toMatchArray([
				'collection'     => 'subscriptions',
				'subscriptionId' => $this->subId,
				'status'         => 'active',
				'email'          => 'a@example.com',
				'customer'       => null,
				'currency'       => 'USD',
				'recurringTotal' => 1000,
				'interval'       => 'month',
				'intervalCount'  => 1,
				'renewalCount'   => 0,
				'orderId'        => $this->origin->id(),
				'provider'       => 'fake-sub',
			])
			->and($created[0][1])->not->toHaveKey('accessToken')
			->and($created[0][1]['lines']['l1'])->toMatchArray(['productId' => 'club', 'qty' => 1, 'unitPrice' => 1000]);
	});

	it('does not begin a subscription for money that does not match', function (): void {
		($this->route)(new PaymentEvent('fake-sub', 'evt_first', PaymentEventType::Paid, $this->origin->id(), Money::of(500, 'USD'), 'pi_first', 'pi_first', [], 'sub_1', 'cus_1'));

		expect(($this->kept)()->status())->toBe('pending')
			->and($this->orders->find($this->origin->id())->paymentStatus())->toBe('review')
			->and(($this->named)(CoreEvent::SUBSCRIPTION_CREATED))->toBe([]);
	});

	it('never begins when the order that was to start it expires', function (): void {
		($this->route)(new PaymentEvent('fake-sub', 'evt_x', PaymentEventType::Expired, $this->origin->id(), Money::of(1000, 'USD'), 'cs_1'));

		expect(($this->kept)()->status())->toBe('canceled')
			->and(($this->named)(CoreEvent::SUBSCRIPTION_CANCELED))->toBe([]);
	});
});

describe('a period paid', function (): void {
	beforeEach(function (): void {
		($this->route)(($this->firstPayment)());
		$this->heard = [];
	});

	it('makes an order for the period, pays it, and carries the subscription on', function (): void {
		($this->route)(($this->renewal)());
		$order = ($this->renewalOrder)();
		$sub   = ($this->kept)();

		expect($order)->not->toBeNull()
			->and($order->kind())->toBe('renewal')
			->and($order->isRenewal())->toBeTrue()
			->and($order->subscriptionId())->toBe($this->subId)
			->and($order->paymentStatus())->toBe('paid')
			->and($order->grandTotal())->toBe(1000)
			->and($order->email())->toBe('a@example.com')
			->and($order->provider())->toBe('fake-sub')
			->and($order->providerPaymentRef())->toBe('pi_in_2')
			->and($order->number())->toBeGreaterThan($this->origin->number())
			->and(array_keys($order->lines()))->toBe(['l1'])
			->and($sub->status())->toBe('active')
			->and($sub->renewalCount())->toBe(1)
			->and($sub->lastOrderId())->toBe($order->id())
			->and($sub->currentPeriodEnd())->toBe(date(DATE_ATOM, 1900000000));
	});

	it('writes the payment to the ledger and queues a receipt for it', function (): void {
		($this->route)(($this->renewal)());
		$order = ($this->renewalOrder)();

		$payments = array_values(array_filter($this->c->get(TransactionLedger::class)->forOrder($order->id()), static fn (array $t): bool => $t['type'] === 'payment'));

		expect($payments)->toHaveCount(1)
			->and($payments[0]['amount'])->toBe(1000)
			->and(($this->emailsFor)($order->id()))->toHaveCount(1);
	});

	it('tells the listeners of the order, then of the renewal', function (): void {
		($this->route)(($this->renewal)());
		$order = ($this->renewalOrder)();

		expect(array_column($this->heard, 0))->toBe([CoreEvent::ORDER_PAID, CoreEvent::SUBSCRIPTION_RENEWED])
			->and($this->heard[0][1])->toMatchArray(['orderId' => $order->id(), 'kind' => 'renewal', 'subscriptionId' => $this->subId])
			->and($this->heard[1][1])->toMatchArray(['subscriptionId' => $this->subId, 'orderId' => $order->id(), 'renewalCount' => 1, 'status' => 'active']);
	});

	it('is one order and one renewal however often the provider tells of the invoice', function (): void {
		($this->route)(($this->renewal)());
		($this->route)(($this->renewal)());
		($this->route)(($this->renewal)());

		$renewals = array_filter($this->orders->index(), static fn (array $row): bool => ($row['kind'] ?? '') === 'renewal');

		expect($renewals)->toHaveCount(1)
			->and(($this->kept)()->renewalCount())->toBe(1)
			->and(($this->named)(CoreEvent::SUBSCRIPTION_RENEWED))->toHaveCount(1)
			->and(($this->named)(CoreEvent::ORDER_PAID))->toHaveCount(1)
			->and(($this->emailsFor)(($this->renewalOrder)()->id()))->toHaveCount(1);
	});

	it('is another order for the next invoice', function (): void {
		($this->route)(($this->renewal)('in_2'));
		($this->route)(($this->renewal)('in_3'));

		expect(($this->renewalOrder)('in_3')?->paymentStatus())->toBe('paid')
			->and(($this->renewalOrder)('in_3')->id())->not->toBe(($this->renewalOrder)('in_2')->id())
			->and(($this->kept)()->renewalCount())->toBe(2);
	});

	it('holds the order for a person when the amount is not what the subscription is billed at', function (): void {
		($this->route)(($this->renewal)('in_2', 700));
		$order = ($this->renewalOrder)();
		$sub   = ($this->kept)();

		expect($order->paymentStatus())->toBe('review')
			->and($sub->needsAttention())->toBe('amount_mismatch')
			->and($sub->renewalCount())->toBe(0)
			->and($sub->status())->toBe('active')
			->and($this->heard)->toBe([])
			->and(($this->emailsFor)($order->id()))->toBe([]);
	});

	it('takes the provider\'s own tax when the store left the tax to it', function (): void {
		$this->subs->withLock($this->subId, static fn (SubscriptionData $s): SubscriptionData => $s->with(['taxMode' => 'provider']));

		($this->route)(($this->renewal)('in_2', 1060, ['tax' => Money::of(60, 'USD'), 'orderTotal' => Money::of(1000, 'USD')]));
		$order = ($this->renewalOrder)();

		expect($order->paymentStatus())->toBe('paid')
			->and($order->taxTotal())->toBe(60)
			->and($order->grandTotal())->toBe(1060)
			->and(($this->kept)()->needsAttention())->toBe('');
	});

	it('does not count a code\'s use again, or take anything from stock', function (): void {
		seedDiscount('always10', ['type' => 'percent', 'value' => '10', 'recurring' => 'forever', 'usedCount' => 3]);
		$this->subs->withLock($this->subId, static fn (SubscriptionData $s): SubscriptionData => $s->with(['discountCode' => 'ALWAYS10']));

		($this->route)(($this->renewal)());

		expect($this->c->get(TotalCMS\Domain\Commerce\Discount\DiscountRepository::class)->find('ALWAYS10')?->usedCount)->toBe(3)
			->and(($this->renewalOrder)()->discountCode())->toBe('ALWAYS10');
	});

	it('bills what was signed up for, whatever the product costs now', function (): void {
		$club = $this->c->get(TotalCMS\Domain\Object\Service\ObjectFetcher::class)->fetchObject('products', 'club')->toArray();
		$this->c->get(TotalCMS\Domain\Auth\Service\ActorContext::class)->runAs(TotalCMS\Domain\Auth\Data\Actor::system(), fn () => $this->c->get(TotalCMS\Domain\Object\Service\ObjectUpdater::class)->updateObject('products', 'club', ['price' => 50] + $club));
		($this->route)(($this->renewal)());

		expect(($this->renewalOrder)()->paymentStatus())->toBe('paid')
			->and(($this->renewalOrder)()->lines()['l1']['unitPrice'])->toBe(1000);
	});

	it('is not counted for a subscription that has not begun', function (): void {
		$this->subs->withLock($this->subId, static fn (SubscriptionData $s): SubscriptionData => $s->with(['status' => 'pending']));
		($this->route)(($this->renewal)());

		expect(($this->renewalOrder)())->toBeNull()->and(($this->kept)()->renewalCount())->toBe(0);
	});

	it('finds the subscription by the provider\'s name for it when the event does not carry the store\'s', function (): void {
		($this->route)(($this->renewal)('in_2', 1000, ['subscriptionId' => '']));

		expect(($this->kept)()->renewalCount())->toBe(1);
	});
});

describe('a renewal charge that fails', function (): void {
	beforeEach(function (): void {
		($this->route)(($this->firstPayment)());
		$this->heard = [];
	});

	it('makes the subscription past due, emails the subscriber and tells the listeners', function (): void {
		($this->route)(($this->event)(T::PaymentFailed, ['providerInvoiceRef' => 'in_2']));
		$emails = ($this->emailsFor)($this->subId);

		expect(($this->kept)()->status())->toBe('past_due')
			->and($emails)->toHaveCount(1)
			->and($emails[0])->toMatchArray(['mailerId' => 'commerce-payment-failed', 'collection' => 'subscriptions', 'overrideTo' => 'a@example.com'])
			->and(array_column($this->heard, 0))->toBe([CoreEvent::SUBSCRIPTION_PAST_DUE])
			->and($this->heard[0][1])->toMatchArray(['status' => 'past_due', 'orderId' => '']);
	});

	it('emails once for an invoice, however often the provider tries it again', function (): void {
		($this->route)(($this->event)(T::PaymentFailed, ['providerInvoiceRef' => 'in_2']));
		($this->route)(($this->event)(T::PaymentFailed, ['providerInvoiceRef' => 'in_2']));

		expect(($this->emailsFor)($this->subId))->toHaveCount(1)
			->and(($this->named)(CoreEvent::SUBSCRIPTION_PAST_DUE))->toHaveCount(1);
	});

	it('emails again when the next invoice fails too', function (): void {
		($this->route)(($this->event)(T::PaymentFailed, ['providerInvoiceRef' => 'in_2']));
		($this->route)(($this->event)(T::PaymentFailed, ['providerInvoiceRef' => 'in_3']));

		expect(($this->emailsFor)($this->subId))->toHaveCount(2)
			->and(($this->named)(CoreEvent::SUBSCRIPTION_PAST_DUE))->toHaveCount(2);
	});

	it('is active again once the invoice is paid', function (): void {
		($this->route)(($this->event)(T::PaymentFailed, ['providerInvoiceRef' => 'in_2']));
		($this->route)(($this->renewal)('in_2'));

		expect(($this->kept)()->status())->toBe('active')->and(($this->kept)()->renewalCount())->toBe(1);
	});
});

describe('a change at the provider, and the end', function (): void {
	beforeEach(function (): void {
		($this->route)(($this->firstPayment)());
		$this->heard = [];
	});

	it('takes "cancel at the end of the period" without ending anything yet', function (): void {
		($this->route)(($this->event)(T::Updated, ['status' => 'active', 'cancelAtPeriodEnd' => true, 'currentPeriodEnd' => 1900000000]));

		expect(($this->kept)()->status())->toBe('active')
			->and(($this->kept)()->cancelAtPeriodEnd())->toBeTrue()
			->and($this->heard)->toBe([]);
	});

	it('ends, and tells the listeners once', function (): void {
		($this->route)(($this->event)(T::Canceled));
		($this->route)(($this->event)(T::Canceled));
		$sub = ($this->kept)();

		expect($sub->status())->toBe('canceled')
			->and($sub->canceledAt())->not->toBe('')
			->and($sub->isLive())->toBeFalse()
			->and(($this->named)(CoreEvent::SUBSCRIPTION_CANCELED))->toHaveCount(1);
	});

	it('hears nothing more once it has ended', function (): void {
		($this->route)(($this->event)(T::Canceled));
		($this->route)(($this->renewal)());

		expect(($this->renewalOrder)())->toBeNull()->and(($this->kept)()->status())->toBe('canceled');
	});
});
