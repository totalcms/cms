<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\Recurrence;
use TotalCMS\Domain\Commerce\Service\StoreRecords;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionAccess;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionRepository;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Security\SignedToken;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * Checking out with a plan in the cart starts a subscription.
 *
 * The store makes the order as always, and beside it a subscription that
 * waits, pending, for the order's payment. The provider is asked for a
 * subscription: what is due now, and what is due every period after.
 *
 * The tee is $19.99, paid for once. The club is $10.00 a month.
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
	$this->add       = fn (string $productId, string $variantId = '', int $qty = 1) => postJson('/commerce/cart/add', ['productId' => $productId, 'variantId' => $variantId, 'qty' => $qty]);
	$this->buy       = fn (string $provider = 'fake-sub', array $body = []) => post('/commerce/checkout', $body + ['provider' => $provider, 'email' => 'a@example.com', 'shippingAddress' => ['country' => 'US']], ['Accept' => 'application/json']);
	$this->allOrders = fn (): array => array_values(array_filter(array_map(fn (array $row): ?OrderData => $this->orders->find((string)$row['id']), $this->orders->index())));
	$this->allSubs   = fn (): array => array_values(array_filter(array_map(fn (array $row): ?SubscriptionData => $this->subs->find((string)$row['id']), $this->subs->index())));
	$this->code      = fn ($response): string => (string)(json_decode((string)$response->getBody(), true)['error']['code'] ?? '');
});

describe('a cart with a plan', function (): void {
	it('starts one subscription beside the order, and asks the provider for both amounts', function (): void {
		($this->add)('tee', 'large');
		($this->add)('club');

		($this->buy)();
		[$order] = ($this->allOrders)();
		[$sub]   = ($this->allSubs)();
		$request = $this->sub->checkouts[0];

		expect($order->grandTotal())->toBe(2999)
			->and($order->subscriptionId())->toBe($sub->id())
			->and($order->paymentStatus())->toBe('pending')
			->and($sub->status())->toBe('pending')
			->and($sub->originOrderId())->toBe($order->id())
			->and($sub->recurringTotal())->toBe(1000)
			->and($sub->interval())->toBe('month')
			->and($sub->intervalCount())->toBe(1)
			->and($sub->email())->toBe('a@example.com')
			->and($sub->provider())->toBe('fake-sub')
			// Only the line that is billed again.
			->and(array_keys($sub->lines()))->toBe(['l2'])
			->and(strlen($sub->accessToken()))->toBe(64)
			->and($request->mode)->toBe('subscription')
			->and($request->total->amount())->toBe(2999)
			->and($request->recurringTotal?->amount())->toBe(1000)
			->and($request->subscriptionId)->toBe($sub->id())
			->and($request->lines[0]->recurrence)->toBeNull()
			->and($request->lines[1]->recurrence)->toEqual(new Recurrence('month', 1));
	});

	it('makes no subscription for a cart of things paid for once', function (): void {
		($this->add)('tee', 'large');
		($this->buy)();

		expect(($this->allSubs)())->toBe([])
			->and($this->sub->checkouts[0]->mode)->toBe('payment')
			->and($this->sub->checkouts[0]->recurringTotal)->toBeNull()
			->and(($this->allOrders)()[0]->subscriptionId())->toBe('');
	});

	it('cannot be paid with a provider that does not bill subscriptions', function (): void {
		($this->add)('club');

		expect(($this->code)(($this->buy)('fake')))->toBe('checkout_unavailable')
			->and(($this->allOrders)())->toBe([])
			->and(($this->allSubs)())->toBe([]);
	});

	it('keeps a code that repeats on every payment', function (): void {
		seedDiscount('always10', ['type' => 'percent', 'value' => '10', 'recurring' => 'forever']);
		($this->add)('club');
		post('/commerce/cart/discount', ['code' => 'always10'], ['Accept' => 'application/json']);

		($this->buy)();
		[$sub] = ($this->allSubs)();

		expect(($this->allOrders)()[0]->grandTotal())->toBe(900)
			->and($sub->recurringDiscount())->toBe(100)
			->and($sub->recurringTotal())->toBe(900)
			->and($sub->discountCode())->toBe('ALWAYS10')
			->and($this->sub->checkouts[0]->metadata['discountCode'])->toBe('ALWAYS10')
			->and($this->sub->checkouts[0]->lines[0]->recurringDiscount->amount())->toBe(100);
	});

	it('takes a first-payment code off the first payment only', function (): void {
		seedDiscount('first10', ['type' => 'percent', 'value' => '10', 'recurring' => 'once']);
		($this->add)('club');
		post('/commerce/cart/discount', ['code' => 'first10'], ['Accept' => 'application/json']);

		($this->buy)();

		expect(($this->allOrders)()[0]->grandTotal())->toBe(900)
			->and(($this->allSubs)()[0]->recurringTotal())->toBe(1000)
			->and($this->sub->checkouts[0]->lines[0]->recurringDiscount->amount())->toBe(0);
	});

	it('works out the tax for every period on the recurring lines', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['taxMode' => 'exclusive', 'taxRates' => [['country' => 'US', 'region' => '', 'rate' => '10', 'label' => 'Tax']]] + $config->commerce;
		($this->add)('tee', 'large');
		($this->add)('club');

		($this->buy)('fake-sub', ['billingAddress' => ['country' => 'US']]);
		[$sub] = ($this->allSubs)();

		expect($sub->recurringTax())->toBe(100)
			->and($sub->recurringTotal())->toBe(1100)
			->and($sub->taxMode())->toBe('exclusive')
			->and($this->sub->checkouts[0]->recurringTax?->amount())->toBe(100)
			->and($this->sub->checkouts[0]->recurringTotal?->amount())->toBe(1100);
	});

	it('still goes to the provider when nothing is due now', function (): void {
		seedDiscount('firstfree', ['type' => 'percent', 'value' => '100', 'recurring' => 'once']);
		($this->add)('club');
		post('/commerce/cart/discount', ['code' => 'firstfree'], ['Accept' => 'application/json']);

		($this->buy)();

		expect($this->sub->checkouts)->toHaveCount(1)
			->and($this->sub->checkouts[0]->total->amount())->toBe(0)
			->and(($this->allOrders)()[0]->paymentStatus())->toBe('pending');
	});

	it('gives up on the stale order and its waiting subscription when the buyer checks out again', function (): void {
		($this->add)('club');
		($this->buy)();
		($this->buy)();

		$orders = ($this->allOrders)();
		$subs   = ($this->allSubs)();
		usort($orders, static fn (OrderData $a, OrderData $b): int => $a->number() <=> $b->number());
		$first = $this->subs->find($orders[0]->subscriptionId());

		expect($orders)->toHaveCount(2)
			->and($orders[0]->paymentStatus())->toBe('expired')
			->and($first->status())->toBe('canceled')
			->and($this->subs->find($orders[1]->subscriptionId())->status())->toBe('pending')
			->and($subs)->toHaveCount(2);
	});

	it('gives up on the subscription too when the provider cannot start the checkout', function (): void {
		($this->add)('club');
		$this->sub->failNextCheckout();

		expect(($this->code)(($this->buy)()))->toBe('provider_error')
			->and(($this->allSubs)()[0]->status())->toBe('canceled');
	});
});

it('tells the buyer what is due today and what is due every period after, before they pay', function (): void {
	($this->add)('tee', 'large');
	($this->add)('club');

	$quote = (array)json_decode((string)post('/commerce/checkout/quote', ['provider' => 'fake-sub'], ['Accept' => 'application/json'])->getBody(), true);
	$html  = (string)post('/commerce/checkout/quote', ['provider' => 'fake-sub'])->getBody();

	expect($quote)->toMatchArray(['total' => 2999, 'recurringTotal' => 1000, 'interval' => 'month', 'intervalCount' => 1])
		->and($html)->toContain('Due today', '$29.99', 'Then every month', '$10.00');
});

describe('where subscriptions are kept', function (): void {
	beforeEach(function (): void {
		($this->add)('club');
		($this->buy)();
		$this->kept = ($this->allSubs)()[0];
	});

	it('is a record only the store writes', function (): void {
		expect($this->c->get(StoreRecords::class)->holds('subscriptions'))->toBeTrue();
	});

	it('is found by the provider\'s own name for it, and only for that provider', function (): void {
		$this->subs->withLock($this->kept->id(), static fn (SubscriptionData $s): SubscriptionData => $s->with(['providerSubscriptionRef' => 'sub_1']));

		expect($this->subs->findByProviderRef('fake-sub', 'sub_1')?->id())->toBe($this->kept->id())
			->and($this->subs->findByProviderRef('stripe', 'sub_1'))->toBeNull()
			->and($this->subs->findByProviderRef('fake-sub', ''))->toBeNull();
	});

	it('is changed only under its lock, and says so when there is no such subscription', function (): void {
		$changed = $this->subs->withLock($this->kept->id(), static fn (SubscriptionData $s): SubscriptionData => $s->with(['status' => 'active'])->withFlag('createdDispatched'));

		expect($this->subs->find($this->kept->id())->status())->toBe('active')
			->and($changed->hasFlag('createdDispatched'))->toBeTrue()
			->and(fn () => $this->subs->withLock('nope', static fn (SubscriptionData $s): SubscriptionData => $s))->toThrow(CommerceException::class);
	});

	it('counts as live while the subscriber has what they pay for', function (string $status, bool $live): void {
		expect((new SubscriptionData(['status' => $status]))->isLive())->toBe($live);
	})->with([['pending', false], ['trialing', true], ['active', true], ['past_due', true], ['paused', false], ['canceled', false]]);
});

describe('the signed link to a subscription', function (): void {
	beforeEach(function (): void {
		($this->add)('club');
		($this->buy)();
		$this->kept   = ($this->allSubs)()[0];
		$this->access = $this->c->get(SubscriptionAccess::class);
	});

	it('opens exactly the subscription it was made for', function (): void {
		$token = $this->access->token($this->kept);

		expect($this->access->verify($this->kept->id(), $token)?->id())->toBe($this->kept->id())
			->and($this->access->verify('another-id', $token))->toBeNull()
			->and($this->access->verify($this->kept->id(), 'made-up'))->toBeNull()
			->and($this->access->verify($this->kept->id(), ''))->toBeNull()
			->and($this->access->url($this->kept))->toContain("/commerce/subscription/{$this->kept->id()}?t=");
	});

	it('stops working when it runs out, and when the subscription\'s secret changes', function (): void {
		$expired = $this->access->token($this->kept, -10);
		$token   = $this->access->token($this->kept);
		$this->subs->withLock($this->kept->id(), static fn (SubscriptionData $s): SubscriptionData => $s->with(['accessToken' => bin2hex(random_bytes(32))]));

		expect($this->access->verify($this->kept->id(), $expired))->toBeNull()
			->and($this->access->verify($this->kept->id(), $token))->toBeNull();
	});

	it('is not an order\'s link', function (): void {
		$other = $this->c->get(SignedToken::class)->sign('commerce-order', ['s' => $this->kept->id(), 'h' => 'x'], time() + 60);

		expect($this->access->verify($this->kept->id(), $other))->toBeNull();
	});
});
