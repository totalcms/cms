<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\ProviderEventRouter;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEventType;
use TotalCMS\Domain\Commerce\Service\CommerceNav;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionQuery;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionRepository;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * The store's subscriptions in the admin: a list, a page for one, and the
 * two things staff can do to one. Cancelling asks the provider, like the
 * subscriber's own button; "check with the provider" asks where it stands
 * and puts the store's record right.
 *
 * Pat subscribes to the club, $10.00 a month, which gives `members`.
 * Bo subscribes to the yearly plan, $120.00 a year, as a guest.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore();
	seedCommerceStaff($this->app);
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);
	$this->subs   = $this->c->get(SubscriptionRepository::class);
	$this->query  = $this->c->get(SubscriptionQuery::class);
	$this->fake   = enableSubscriptions();

	$config           = $this->c->get(Config::class);
	$config->commerce = ['customerCollection' => 'customers', 'grantableGroups' => ['members']] + $config->commerce;
	$asSystem         = fn (callable $do): mixed => $this->c->get(ActorContext::class)->runAs(Actor::system(), $do);
	$asSystem(fn () => $this->c->get(CollectionSaver::class)->saveCollection(['id' => 'customers', 'name' => 'Customers', 'schema' => 'auth']));
	$asSystem(fn () => $this->c->get(ObjectSaver::class)->saveObject('customers', ['id' => 'pat', 'name' => 'Pat', 'email' => 'pat@example.test', 'password' => 'commerce-Password-1', 'active' => true]));
	seedPlan('club', 1000, ['accessGroup' => 'members']);
	seedPlan('annual', 12000, ['interval' => 'year']);

	$session = $this->c->get(PhpSession::class);
	$session->isStarted() || $session->start();
	withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());
	$this->route = fn (PaymentEvent|SubscriptionEvent $event): bool => $this->c->get(ProviderEventRouter::class)->route($event);

	/** Someone subscribes to $plan, and the provider says the first payment went through. */
	$this->subscribe = function (string $plan, string $email, string $ref, string $customer = ''): SubscriptionData {
		if ($customer !== '') {
			signInAs($this->app, $customer, 'customers');
		}
		post('/commerce/cart/clear');
		postJson('/commerce/cart/add', ['productId' => $plan, 'variantId' => '', 'qty' => 1]);
		$known = array_column($this->subs->index(), 'id');
		post('/commerce/checkout', ['provider' => 'fake-sub', 'email' => $email], ['Accept' => 'application/json']);
		$id    = (string)array_values(array_diff(array_column($this->subs->index(), 'id'), $known))[0];
		$order = $this->orders->find($this->subs->find($id)->originOrderId());
		($this->route)(new PaymentEvent('fake-sub', 'evt_' . $ref, PaymentEventType::Paid, $order->id(), Money::of($order->grandTotal(), 'USD'), 'pi_' . $ref, 'pi_' . $ref, [], $ref, 'cus_' . $ref));

		return $this->subs->find($id);
	};
	$this->pat = ($this->subscribe)('club', 'pat@example.test', 'sub_1', 'pat');
	// Bo is a guest.
	$guest = $this->c->get(PhpSession::class);
	$guest->isStarted() || $guest->start();
	$guest->delete(TotalCMS\Domain\Session\SessionKeys::AUTH_USER);
	$guest->delete(TotalCMS\Domain\Session\SessionKeys::AUTH_COLLECTION);
	$this->bo = ($this->subscribe)('annual', 'bo@example.org', 'sub_2');

	$this->kept   = fn (SubscriptionData $s): SubscriptionData => $this->subs->find($s->id());
	$this->groups = fn (): array => $this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('customers', 'pat')->toArray()['groups'] ?? [];
	$this->code   = fn ($response): string => (string)(json_decode((string)$response->getBody(), true)['error']['code'] ?? '');
});

describe('finding subscriptions', function (): void {
	it('lists every subscription, newest first', function (): void {
		$found = $this->query->search([], 1);

		expect($found['total'])->toBe(2)
			->and(array_map(static fn (SubscriptionData $s): string => $s->email(), $found['subscriptions']))->toEqualCanonicalizing(['pat@example.test', 'bo@example.org']);
	});

	it('filters and searches', function (array $filters, array $emails): void {
		$this->subs->withLock($this->bo->id(), static fn (SubscriptionData $s): SubscriptionData => $s->with(['status' => 'past_due', 'needsAttention' => 'amount_mismatch']));

		$found = $this->query->search($filters, 1);

		expect(array_map(static fn (SubscriptionData $s): string => $s->email(), $found['subscriptions']))->toEqualCanonicalizing($emails);
	})->with([
		'by status'             => [['status' => 'past_due'], ['bo@example.org']],
		'needing someone'       => [['needsAttention' => '1'], ['bo@example.org']],
		'by part of an email'   => [['q' => 'PAT@'], ['pat@example.test']],
		'by nothing that is so' => [['status' => 'paused'], []],
	]);

	it('gives one page at a time', function (): void {
		$found = $this->query->search([], 2, 1);

		expect($found['total'])->toBe(2)->and($found['subscriptions'])->toHaveCount(1);
	});

	it('says what a subscription is worth a month, whatever it is billed by', function (int $total, string $interval, int $every, int $monthly): void {
		$subscription = new SubscriptionData(['recurringTotal' => $total, 'interval' => $interval, 'intervalCount' => $every]);

		expect($this->query->monthlyValue($subscription))->toBe($monthly);
	})->with([
		'a year'             => [12000, 'year', 1, 1000],
		'a month'            => [1000, 'month', 1, 1000],
		'every three months' => [3000, 'month', 3, 1000],
		'a week'             => [500, 'week', 1, 2167],
		'a day'              => [100, 'day', 1, 3042],
	]);

	it('adds up what the running subscriptions bring in a month', function (): void {
		$before = $this->query->running();
		$this->subs->withLock($this->bo->id(), static fn (SubscriptionData $s): SubscriptionData => $s->with(['status' => 'canceled']));

		expect($before)->toBe(['active' => 2, 'monthly' => 2000, 'currency' => 'USD'])
			->and($this->query->running())->toBe(['active' => 1, 'monthly' => 1000, 'currency' => 'USD']);
	});

	it('counts a subscription that needs someone in the sidebar, with the orders that do', function (): void {
		$nav    = $this->c->get(CommerceNav::class);
		$before = $nav->attention();
		$this->subs->withLock($this->pat->id(), static fn (SubscriptionData $s): SubscriptionData => $s->with(['needsAttention' => 'amount_mismatch']));

		expect($nav->attention())->toBe($before + 1);
	});
});

describe('the subscriptions pages', function (): void {
	beforeEach(fn () => signInAs($this->app, 'keeper', 'auth'));

	it('lists each subscription with its subscriber, plan, amount, status and next payment', function (): void {
		($this->route)(new SubscriptionEvent('fake-sub', 'evt_u', SubscriptionEventType::Updated, $this->pat->id(), 'sub_1', 'active', 1900000000));
		$res  = get('/admin/commerce/subscriptions');
		$html = (string)$res->getBody();

		expect($res->getStatusCode())->toBe(200)
			->and($html)->toContain('<html', 'pat@example.test', 'bo@example.org', 'Club', 'Annual', '$10.00', '$120.00', 'month', 'year', 'Active', "commerce/subscriptions/{$this->pat->id()}")
			->and($html)->toContain(date('M j, Y', 1900000000));
	});

	it('answers a filter from the page with the table alone', function (): void {
		$res  = get('/admin/commerce/subscriptions?q=bo', ['HX-Request' => 'true']);
		$html = (string)$res->getBody();

		expect($html)->not->toContain('<html')
			->and($html)->toContain('id="commerce-subscriptions-table"', 'bo@example.org', '1 subscription')
			->and($html)->not->toContain('pat@example.test');
	});

	it('shows one subscription with the payments made for it', function (): void {
		($this->route)(new SubscriptionEvent('fake-sub', 'evt_r', SubscriptionEventType::Renewed, $this->pat->id(), 'sub_1', '', 1900000000, false, Money::of(1000, 'USD'), 'in_2', 'pi_2'));
		$res  = get("/admin/commerce/subscriptions/{$this->pat->id()}");
		$html = (string)$res->getBody();

		expect($res->getStatusCode())->toBe(200)
			->and($html)->toContain('pat@example.test', 'Club', '$10.00', 'Active', 'sub_1', 'members')
			->and(substr_count($html, 'class="commerce-subscription-payment"'))->toBe(2)
			->and($html)->toContain('Renewal');
	});

	it('says what to do about a subscription that needs someone', function (): void {
		$this->subs->withLock($this->pat->id(), static fn (SubscriptionData $s): SubscriptionData => $s->with(['needsAttention' => 'amount_mismatch']));

		expect((string)get("/admin/commerce/subscriptions/{$this->pat->id()}")->getBody())->toContain('commerce-attention', 'did not match');
	});

	it('is not there for an id that is no subscription\'s', function (): void {
		expect(get('/admin/commerce/subscriptions/nope')->getStatusCode())->toBe(404);
	});

	it('never prints the subscription\'s secret, and escapes what a buyer typed', function (): void {
		$this->subs->withLock($this->bo->id(), static fn (SubscriptionData $s): SubscriptionData => $s->with(['email' => '<script>alert(1)</script>@example.org']));
		$list = (string)get('/admin/commerce/subscriptions')->getBody();
		$one  = (string)get("/admin/commerce/subscriptions/{$this->bo->id()}")->getBody();

		expect($list . $one)->not->toContain('<script>alert(1)</script>')
			->and($one)->not->toContain(($this->kept)($this->bo)->accessToken());
	});

	it('links between orders and subscriptions', function (): void {
		$order = $this->orders->find($this->pat->originOrderId());

		expect((string)get('/admin/commerce/orders')->getBody())->toContain('commerce/subscriptions')
			->and((string)get("/admin/commerce/orders/{$order->id()}")->getBody())->toContain("commerce/subscriptions/{$this->pat->id()}");
	});

	it('is for staff the store is open to, and nobody else', function (): void {
		signInAs($this->app, 'writer', 'auth');

		expect(get('/admin/commerce/subscriptions')->getStatusCode())->toBe(403)
			->and(get("/admin/commerce/subscriptions/{$this->pat->id()}")->getStatusCode())->toBe(403);
	});
});

describe('what staff can do to one', function (): void {
	it('lets only a super admin cancel or check one', function (): void {
		signInAs($this->app, 'keeper', 'auth');

		expect(post("/admin/commerce/subscriptions/{$this->pat->id()}/cancel", ['when' => 'now'])->getStatusCode())->toBe(403)
			->and(post("/admin/commerce/subscriptions/{$this->pat->id()}/sync")->getStatusCode())->toBe(403)
			->and($this->fake->cancellations)->toBe([]);
	});

	it('asks the provider to cancel, at once or at the end of the period, and changes nothing itself', function (string $when, bool $atPeriodEnd): void {
		signInAs($this->app, 'boss', 'auth');

		$res = post("/admin/commerce/subscriptions/{$this->pat->id()}/cancel", ['when' => $when], ['Accept' => 'application/json']);

		expect($res->getStatusCode())->toBe(200)
			->and($this->fake->cancellations)->toBe([['ref' => 'sub_1', 'atPeriodEnd' => $atPeriodEnd]])
			->and(($this->kept)($this->pat)->status())->toBe('active')
			->and(($this->kept)($this->pat)->cancelAtPeriodEnd())->toBeFalse();
	})->with([['now', false], ['period_end', true], ['anything else', true]]);

	it('refuses to cancel what is not running, and says so when the provider will not', function (): void {
		signInAs($this->app, 'boss', 'auth');
		$this->fake->failNextCancel();
		$failed = post("/admin/commerce/subscriptions/{$this->pat->id()}/cancel", ['when' => 'now'], ['Accept' => 'application/json']);
		$this->subs->withLock($this->bo->id(), static fn (SubscriptionData $s): SubscriptionData => $s->with(['status' => 'canceled']));
		$over = post("/admin/commerce/subscriptions/{$this->bo->id()}/cancel", ['when' => 'now'], ['Accept' => 'application/json']);

		expect(($this->code)($failed))->toBe('provider_error')
			->and($failed->getStatusCode())->toBe(502)
			->and(($this->code)($over))->toBe('subscription_not_live')
			->and($this->fake->cancellations)->toBe([]);
	});

	it('asks the provider where a subscription stands, puts the record right, and takes back the access', function (): void {
		signInAs($this->app, 'boss', 'auth');
		$had = ($this->groups)();
		// The provider ended it, and the store never heard.
		$this->fake->subscriptions['sub_1'] = new SubscriptionEvent('fake-sub', 'state_sub_1', SubscriptionEventType::Updated, $this->pat->id(), 'sub_1', 'canceled');

		$res = post("/admin/commerce/subscriptions/{$this->pat->id()}/sync", [], ['Accept' => 'application/json']);

		expect($res->getStatusCode())->toBe(200)
			->and($had)->toBe(['members'])
			->and(($this->kept)($this->pat)->status())->toBe('canceled')
			->and(($this->groups)())->toBe([]);
	});

	it('gives back access a subscriber is owed, when checked', function (): void {
		signInAs($this->app, 'boss', 'auth');
		$this->c->get(ActorContext::class)->runAs(Actor::system(), function (): void {
			$pat = $this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('customers', 'pat')->toArray();
			$this->c->get(TotalCMS\Domain\Object\Service\ObjectUpdater::class)->updateObject('customers', 'pat', ['groups' => []] + $pat);
		});
		$this->fake->subscriptions['sub_1'] = new SubscriptionEvent('fake-sub', 'state_sub_1', SubscriptionEventType::Updated, $this->pat->id(), 'sub_1', 'active');

		post("/admin/commerce/subscriptions/{$this->pat->id()}/sync", [], ['Accept' => 'application/json']);

		expect(($this->groups)())->toBe(['members']);
	});

	it('says so when the provider cannot say where it stands', function (): void {
		signInAs($this->app, 'boss', 'auth');
		$res = post("/admin/commerce/subscriptions/{$this->pat->id()}/sync", [], ['Accept' => 'application/json']);

		expect($res->getStatusCode())->toBe(502)
			->and(($this->code)($res))->toBe('provider_error')
			->and(($this->kept)($this->pat)->status())->toBe('active');
	});

	it('redraws the page with what was done, for the page\'s own buttons', function (): void {
		signInAs($this->app, 'boss', 'auth');
		$res  = post("/admin/commerce/subscriptions/{$this->pat->id()}/cancel", ['when' => 'period_end'], ['HX-Request' => 'true']);
		$html = (string)$res->getBody();

		expect($html)->not->toContain('<html')
			->and($html)->toContain('id="commerce-subscription"', 'commerce-notice');
	});
});
