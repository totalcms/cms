<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Commerce\Access\AccessGrantService;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\OrderAdmin;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\ProviderEventRouter;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEventType as T;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionRepository;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * Memberships: a product puts its buyer in an access group, and they stay
 * in it exactly while something they bought backs it.
 *
 * The store lets products give `members` and `vip`. Pat has an account and
 * is in `newsletter`, which is none of the store's business. The course is
 * $50.00, paid for once, and gives `vip`. The club is $10.00 a month and
 * gives `members`.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	// The customers collection, Pat and the products are the same for every
	// test here: the first test in a worker makes them, the rest start from a copy.
	$stock        = bootCommerceScene('access-grants');
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);
	$this->subs   = $this->c->get(SubscriptionRepository::class);
	$this->grants = $this->c->get(AccessGrantService::class);
	$this->sub    = enableSubscriptions();

	$config           = $this->c->get(Config::class);
	$config->commerce = ['grantableGroups' => ['members', 'vip', 'admin'], 'customerCollection' => 'customers'] + $config->commerce;
	// Customers sign in to their own collection, not the admin one.
	if ($stock) {
		$this->c->get(ActorContext::class)->runAs(Actor::system(), fn () => $this->c->get(CollectionSaver::class)->saveCollection(['id' => 'customers', 'name' => 'Customers', 'schema' => 'auth']));
	}

	$this->asSystem = fn (callable $do): mixed => $this->c->get(ActorContext::class)->runAs(Actor::system(), $do);
	$this->pat      = fn (array $groups = ['newsletter']) => ($this->asSystem)(fn () => $this->c->get(ObjectSaver::class)->saveObject('customers', [
		'id' => 'pat', 'name' => 'Pat', 'email' => 'pat@example.test', 'password' => quickPasswordHash(), 'active' => true, 'groups' => $groups,
	]));
	$stock && ($this->pat)();
	$this->groups = function (): array {
		$groups = $this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('customers', 'pat')->toArray()['groups'] ?? [];
		sort($groups);

		return $groups;
	};

	if ($stock) {
		seedPlan('club', 1000, ['accessGroup' => 'members']);
		seedPlan('lounge', 2000, ['accessGroup' => 'members']);
		$product = fn (string $id, string $group) => ($this->asSystem)(fn () => $this->c->get(ObjectSaver::class)->saveObject('products', [
			'id' => $id, 'title' => ucfirst($id), 'status' => 'active', 'type' => 'digital', 'requiresShipping' => false, 'price' => 50, 'accessGroup' => $group,
		]));
		$product('course', 'vip');
		$product('throne', 'admin');
		$product('backroom', 'staff');
		keepCommerceScene('access-grants');
	}

	$session = $this->c->get(PhpSession::class);
	$session->isStarted() || $session->start();
	withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());

	$this->route = fn (PaymentEvent|SubscriptionEvent $event): bool => $this->c->get(ProviderEventRouter::class)->route($event);
	$this->code  = fn ($response): string => (string)(json_decode((string)$response->getBody(), true)['error']['code'] ?? '');

	/** Pat, signed in, checks out with one product. The order that makes, not yet paid. */
	$this->checkout = function (string $productId, bool $signedIn = true): mixed {
		if ($signedIn) {
			signInAs($this->app, 'pat', 'customers');
		}
		postJson('/commerce/cart/add', ['productId' => $productId, 'variantId' => '', 'qty' => 1]);
		$known    = array_column($this->orders->index(), 'id');
		$response = post('/commerce/checkout', ['provider' => 'fake-sub', 'email' => 'pat@example.test'], ['Accept' => 'application/json']);
		$new      = array_values(array_diff(array_column($this->orders->index(), 'id'), $known));

		return $new === [] ? $response : $this->orders->find((string)$new[0]);
	};
	$this->event = fn (PaymentEventType $type, OrderData $order, int $amount, string $subscriptionRef = ''): PaymentEvent => new PaymentEvent(
		'fake-sub',
		'evt_' . bin2hex(random_bytes(4)),
		$type,
		$order->id(),
		Money::of($amount, 'USD'),
		'ref_' . bin2hex(random_bytes(4)),
		'pi_' . $order->number(),
		[],
		$subscriptionRef,
		$subscriptionRef === '' ? '' : 'cus_1',
	);
	/** Pat buys it, and the provider says it was paid. */
	$this->buy = function (string $productId, string $subscriptionRef = ''): OrderData {
		$order = ($this->checkout)($productId);
		($this->route)(($this->event)(PaymentEventType::Paid, $order, $order->grandTotal(), $subscriptionRef));

		return $this->orders->find($order->id());
	};
	$this->subEvent = fn (T $type, string $subscriptionId, array $with = []): SubscriptionEvent => new SubscriptionEvent(...$with + ['provider' => 'fake-sub', 'eventId' => 'evt_' . bin2hex(random_bytes(4)), 'type' => $type, 'subscriptionId' => $subscriptionId, 'providerSubscriptionRef' => '']);
});

describe('who can buy a membership', function (): void {
	it('asks a guest to sign in before buying anything that gives a group', function (string $productId): void {
		$response = ($this->checkout)($productId, false);

		expect(($this->code)($response))->toBe('account_required')
			->and($response->getStatusCode())->toBe(401)
			->and($this->orders->index())->toBe([]);
	})->with(['paid for once' => ['course'], 'a plan' => ['club']]);

	it('lets a guest buy a product whose group the store does not allow: it gives nothing', function (): void {
		$order = ($this->checkout)('backroom', false);

		expect($order)->toBeInstanceOf(OrderData::class)
			->and($order->lines()['l1']['accessGroup'])->toBe('');
	});
});

describe('the checkout form, with a membership in the cart', function (): void {
	it('tells a guest to sign in instead of asking them to pay, with a way to sign in and to join', function (): void {
		postJson('/commerce/cart/add', ['productId' => 'course', 'variantId' => '', 'qty' => 1]);
		$this->c->get(ActorContext::class)->set(Actor::anonymous());

		$html = html_entity_decode(renderTwig('{{ cms.cart.checkoutForm({registerUrl: "/join"}) }}'));

		expect($html)->toContain('commerce-checkout-account', '/admin/login/customers', 'href="/join"')
			->and($html)->not->toContain('name="email"')
			->and($html)->not->toContain('commerce-checkout-submit');
	});

	it('names no page to join on when the site gave none', function (): void {
		postJson('/commerce/cart/add', ['productId' => 'course', 'variantId' => '', 'qty' => 1]);
		$this->c->get(ActorContext::class)->set(Actor::anonymous());

		expect(renderTwig('{{ cms.cart.checkoutForm() }}'))->toContain('commerce-checkout-account')->not->toContain('commerce-checkout-register');
	});

	it('is the ordinary form for a customer who is signed in', function (): void {
		postJson('/commerce/cart/add', ['productId' => 'course', 'variantId' => '', 'qty' => 1]);
		$this->c->get(ActorContext::class)->set(new Actor(ActorKind::Member, 'pat', 'customers'));

		expect(renderTwig('{{ cms.cart.checkoutForm() }}'))->toContain('name="email"')->not->toContain('commerce-checkout-account');
	});

	it('is the ordinary form for a guest whose cart gives no group', function (string $productId): void {
		postJson('/commerce/cart/add', ['productId' => $productId, 'variantId' => '', 'qty' => 1]);
		$this->c->get(ActorContext::class)->set(Actor::anonymous());

		expect(renderTwig('{{ cms.cart.checkoutForm() }}'))->toContain('name="email"')->not->toContain('commerce-checkout-account');
	})->with(['a group the store does not allow' => ['backroom'], 'the admin group' => ['throne']]);
});

describe('a product paid for once', function (): void {
	it('puts its buyer in the group when it is paid, and marks the line delivered', function (): void {
		$order = ($this->buy)('course');

		expect(($this->groups)())->toBe(['newsletter', 'vip'])
			->and($order->lines()['l1']['accessGroup'])->toBe('vip')
			->and($order->lines()['l1']['fulfillmentStatus'])->toBe('fulfilled')
			->and($order->lines()['l1']['fulfillmentNote'])->toBe('Access given')
			->and($order->fulfillmentStatus())->toBe('fulfilled')
			->and($order->needsAttention())->toBe('');
	});

	it('gives nothing until it is paid', function (): void {
		($this->checkout)('course');

		expect(($this->groups)())->toBe(['newsletter']);
	});

	it('keeps the group through a part refund, and takes it back on a full one', function (): void {
		$order = ($this->buy)('course');

		($this->route)(($this->event)(PaymentEventType::Refunded, $order, 2000));
		$kept = ($this->groups)();
		($this->route)(($this->event)(PaymentEventType::Refunded, $order, 3000));

		expect($kept)->toBe(['newsletter', 'vip'])
			->and($this->orders->find($order->id())->paymentStatus())->toBe('refunded')
			->and(($this->groups)())->toBe(['newsletter']);
	});

	it('keeps the group while a dispute is open, and takes it back when the dispute is lost', function (): void {
		$order = ($this->buy)('course');

		($this->route)(($this->event)(PaymentEventType::Disputed, $order, 5000));
		$during = ($this->groups)();
		($this->route)(($this->event)(PaymentEventType::DisputeLost, $order, 5000));

		expect($during)->toBe(['newsletter', 'vip'])
			->and(($this->groups)())->toBe(['newsletter']);
	});

	it('keeps the group when the dispute is won', function (): void {
		$order = ($this->buy)('course');
		($this->route)(($this->event)(PaymentEventType::Disputed, $order, 5000));
		($this->route)(($this->event)(PaymentEventType::DisputeWon, $order, 5000));

		expect(($this->groups)())->toBe(['newsletter', 'vip']);
	});
});

describe('a plan', function (): void {
	it('puts the subscriber in the group when the first payment begins it', function (): void {
		$order = ($this->buy)('club', 'sub_1');

		expect(($this->groups)())->toBe(['members', 'newsletter'])
			->and($this->subs->find($order->subscriptionId())->status())->toBe('active')
			->and($order->lines()['l1']['fulfillmentStatus'])->toBe('fulfilled');
	});

	it('holds the group exactly while the subscription is live', function (string $status, bool $has): void {
		$order = ($this->buy)('club', 'sub_1');
		$this->subs->withLock($order->subscriptionId(), static fn (SubscriptionData $s): SubscriptionData => $s->with(['status' => $status]));

		$this->grants->sync('customers', 'pat');

		expect(in_array('members', ($this->groups)(), true))->toBe($has);
	})->with([['trialing', true], ['active', true], ['past_due', true], ['paused', false], ['canceled', false], ['pending', false]]);

	it('takes the group back when the provider says the subscription has ended', function (): void {
		$order = ($this->buy)('club', 'sub_1');

		($this->route)(($this->subEvent)(T::Canceled, $order->subscriptionId()));

		expect(($this->groups)())->toBe(['newsletter']);
	});

	it('takes the group away while a subscription is paused, and gives it back when it is active again', function (): void {
		$order = ($this->buy)('club', 'sub_1');

		($this->route)(($this->subEvent)(T::Updated, $order->subscriptionId(), ['status' => 'paused']));
		$paused = ($this->groups)();
		($this->route)(($this->subEvent)(T::Updated, $order->subscriptionId(), ['status' => 'active']));

		expect($paused)->toBe(['newsletter'])->and(($this->groups)())->toBe(['members', 'newsletter']);
	});

	it('keeps the group through a failed renewal charge', function (): void {
		$order = ($this->buy)('club', 'sub_1');

		($this->route)(($this->subEvent)(T::PaymentFailed, $order->subscriptionId(), ['providerInvoiceRef' => 'in_2']));

		expect(($this->groups)())->toBe(['members', 'newsletter']);
	});

	it('keeps a group another live subscription still gives', function (): void {
		$club   = ($this->buy)('club', 'sub_1');
		$lounge = ($this->buy)('lounge', 'sub_2');

		($this->route)(($this->subEvent)(T::Canceled, $club->subscriptionId()));
		$one = ($this->groups)();
		($this->route)(($this->subEvent)(T::Canceled, $lounge->subscriptionId()));

		expect($one)->toBe(['members', 'newsletter'])->and(($this->groups)())->toBe(['newsletter']);
	});

	it('does not hold the group by the order that started it once the subscription has ended', function (): void {
		$order = ($this->buy)('club', 'sub_1');
		($this->route)(($this->subEvent)(T::Canceled, $order->subscriptionId()));

		// The first order is still paid; the plan it started is over.
		expect($this->orders->find($order->id())->paymentStatus())->toBe('paid')
			->and($this->grants->entitledGroups('customers', 'pat'))->toBe([]);
	});
});

describe('what the store allows', function (): void {
	it('never gives the admin group, though the setting names it', function (): void {
		$order = ($this->buy)('throne');

		expect($order->paymentStatus())->toBe('paid')
			->and($order->lines()['l1']['accessGroup'])->toBe('')
			->and(($this->groups)())->toBe(['newsletter'])
			->and($this->grants->isGrantable('admin'))->toBeFalse();
	});

	it('never takes the admin group away either', function (): void {
		($this->asSystem)(fn () => $this->c->get(ObjectRemover::class)->deleteObject('customers', 'pat'));
		($this->pat)(['admin', 'newsletter']);

		$this->grants->sync('customers', 'pat');

		expect(($this->groups)())->toBe(['admin', 'newsletter']);
	});

	it('gives nothing for a group outside the allow-list', function (): void {
		$order = ($this->buy)('backroom');

		expect($order->lines()['l1']['accessGroup'])->toBe('')
			->and(($this->groups)())->toBe(['newsletter'])
			->and($this->grants->isGrantable('staff'))->toBeFalse();
	});

	it('does not give a group an order line names when the store no longer allows it', function (): void {
		$order            = ($this->checkout)('course');
		$config           = $this->c->get(Config::class);
		$config->commerce = ['grantableGroups' => ['members']] + $config->commerce;

		($this->route)(($this->event)(PaymentEventType::Paid, $order, 5000));

		expect(($this->groups)())->toBe(['newsletter']);
	});

	it('leaves every other group alone, whatever happens', function (): void {
		$order = ($this->buy)('course');
		($this->route)(($this->event)(PaymentEventType::Refunded, $order, 5000));
		($this->buy)('club', 'sub_1');

		expect(($this->groups)())->toContain('newsletter');
	});

	it('takes a sold group from someone who holds it with nothing bought', function (): void {
		($this->asSystem)(fn () => $this->c->get(ObjectRemover::class)->deleteObject('customers', 'pat'));
		($this->pat)(['newsletter', 'vip']);

		$this->grants->sync('customers', 'pat');

		expect(($this->groups)())->toBe(['newsletter']);
	});
});

describe('the account', function (): void {
	it('still signs in with the same password after its groups change', function (): void {
		$before = $this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('customers', 'pat')->toArray()['password'];
		($this->buy)('course');

		expect($this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('customers', 'pat')->toArray()['password'])->toBe($before);
	});

	it('writes nothing when nothing changed', function (): void {
		($this->buy)('course');
		$file  = cmsDataDir() . '/customers/pat.json';
		$mtime = filemtime($file);
		touch($file, $mtime - 100);
		clearstatcache();

		$this->grants->sync('customers', 'pat');
		clearstatcache();

		expect(filemtime($file))->toBe($mtime - 100);
	});

	it('says so when the account is gone, and the order stays paid', function (): void {
		$order = ($this->checkout)('course');
		($this->asSystem)(fn () => $this->c->get(ObjectRemover::class)->deleteObject('customers', 'pat'));

		($this->route)(($this->event)(PaymentEventType::Paid, $order, 5000));
		$kept = $this->orders->find($order->id());

		expect($kept->paymentStatus())->toBe('paid')
			->and($kept->needsAttention())->toBe('grant_failed')
			->and($kept->lines()['l1']['fulfillmentStatus'])->toBe('unfulfilled')
			->and(fn () => $this->grants->sync('customers', 'pat'))->toThrow(CommerceException::class);
	});

	it('gives the group and clears the flag when staff deliver the order again', function (): void {
		$order = ($this->checkout)('course');
		($this->asSystem)(fn () => $this->c->get(ObjectRemover::class)->deleteObject('customers', 'pat'));
		($this->route)(($this->event)(PaymentEventType::Paid, $order, 5000));
		($this->pat)();

		$this->c->get(OrderAdmin::class)->refulfill($order->id());
		$kept = $this->orders->find($order->id());

		expect(($this->groups)())->toBe(['newsletter', 'vip'])
			->and($kept->needsAttention())->toBe('')
			->and($kept->lines()['l1']['fulfillmentStatus'])->toBe('fulfilled');
	});

	it('flags the subscription when its subscriber\'s account is gone', function (): void {
		$order = ($this->buy)('club', 'sub_1');
		($this->asSystem)(fn () => $this->c->get(ObjectRemover::class)->deleteObject('customers', 'pat'));

		($this->route)(($this->subEvent)(T::Updated, $order->subscriptionId(), ['status' => 'active']));

		expect($this->subs->find($order->subscriptionId())->needsAttention())->toBe('grant_failed');
	});
});
