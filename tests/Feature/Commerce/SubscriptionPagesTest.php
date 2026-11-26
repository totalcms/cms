<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use Tests\Fakes\ClosedCommerceGate;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\ProviderEventRouter;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEvent;
use TotalCMS\Domain\Commerce\Payment\SubscriptionEventType;
use TotalCMS\Domain\Commerce\Service\CheckoutService;
use TotalCMS\Domain\Commerce\Service\CommerceGate;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionAccess;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionData;
use TotalCMS\Domain\Commerce\Subscription\SubscriptionRepository;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * A subscriber's own page: what they pay for and when, a way to cancel,
 * and a way to change their card.
 *
 * It opens for the customer the subscription belongs to, and for anyone
 * holding its signed link (the one in their emails). Nobody else is told
 * it exists. Cancelling asks the provider and writes nothing: the
 * subscription changes when the provider says it has.
 *
 * Pat and Sam are customers. Pat subscribes to the club, $10.00 a month.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	// The customers, the plan and Pat's subscription are the same for every
	// test here: the first test in a worker makes them, the rest start from a copy.
	$stock        = bootCommerceScene('subscription-pages');
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);
	$this->subs   = $this->c->get(SubscriptionRepository::class);
	$this->fake   = enableSubscriptions();

	$config           = $this->c->get(Config::class);
	$config->commerce = ['customerCollection' => 'customers'] + $config->commerce;
	$asSystem         = fn (callable $do): mixed => $this->c->get(ActorContext::class)->runAs(Actor::system(), $do);
	if ($stock) {
		$asSystem(fn () => $this->c->get(CollectionSaver::class)->saveCollection(['id' => 'customers', 'name' => 'Customers', 'schema' => 'auth']));
		foreach (['pat', 'sam'] as $id) {
			$asSystem(fn () => $this->c->get(ObjectSaver::class)->saveObject('customers', ['id' => $id, 'name' => ucfirst($id), 'email' => "{$id}@example.test", 'password' => quickPasswordHash(), 'active' => true]));
		}
		seedPlan('club', 1000);
	}

	$session = $this->c->get(PhpSession::class);
	$session->isStarted() || $session->start();
	withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());

	$this->route = fn (PaymentEvent|SubscriptionEvent $event): bool => $this->c->get(ProviderEventRouter::class)->route($event);

	// Pat subscribes, and the provider says the first payment went through.
	// Pat is left signed in either way.
	signInAs($this->app, 'pat', 'customers');
	if ($stock) {
		postJson('/commerce/cart/add', ['productId' => 'club', 'variantId' => '', 'qty' => 1]);
		post('/commerce/checkout', ['provider' => 'fake-sub', 'email' => 'pat@example.test'], ['Accept' => 'application/json']);
		$first = $this->subs->find((string)$this->subs->index()[0]['id']);
		($this->route)(new PaymentEvent('fake-sub', 'evt_first', PaymentEventType::Paid, $first->originOrderId(), Money::of(1000, 'USD'), 'pi_first', 'pi_first', [], 'sub_1', 'cus_1'));
		keepCommerceScene('subscription-pages');
	}
	$this->id    = (string)$this->subs->index()[0]['id'];
	$this->order = $this->orders->find($this->subs->find($this->id)->originOrderId());

	$this->kept    = fn (): SubscriptionData => $this->subs->find($this->id);
	$this->token   = fn (): string => $this->c->get(SubscriptionAccess::class)->token(($this->kept)());
	$this->as      = fn (string $user) => signInAs($this->app, $user, 'customers');
	$this->guest   = function (): void {
		// Opened first: a session that is closed is read again from where it
		// is kept when the next request opens it, and the change would be lost.
		$session = $this->c->get(PhpSession::class);
		$session->isStarted() || $session->start();
		$session->delete(SessionKeys::AUTH_USER);
		$session->delete(SessionKeys::AUTH_COLLECTION);
		$this->c->get(ActorContext::class)->set(Actor::anonymous());
	};
	$this->code = fn ($response): string => (string)(json_decode((string)$response->getBody(), true)['error']['code'] ?? '');
});

describe('who it opens for', function (): void {
	it('opens for the customer it belongs to', function (): void {
		$page = get("/commerce/subscription/{$this->id}");
		$html = (string)$page->getBody();

		expect($page->getStatusCode())->toBe(200)
			->and($html)->toContain('Club', '$10.00', 'every month', 'Active')
			->and(html_entity_decode($html))->toContain("/commerce/subscription/{$this->id}/cancel", "/commerce/subscription/{$this->id}/billing")
			->and($page->getHeaderLine('Cache-Control'))->toContain('no-store');
	});

	it('opens for a guest holding its signed link, and carries the link into the page\'s own forms', function (): void {
		$token = ($this->token)();
		($this->guest)();

		$page = get("/commerce/subscription/{$this->id}?t=" . rawurlencode($token));

		expect($page->getStatusCode())->toBe(200)
			->and(html_entity_decode((string)$page->getBody()))->toContain("/commerce/subscription/{$this->id}/cancel?t={$token}");
	});

	it('is not there for anyone else', function (): void {
		($this->guest)();
		$guest = get("/commerce/subscription/{$this->id}");
		$wrong = get("/commerce/subscription/{$this->id}?t=made-up");
		($this->as)('sam');
		$other = get("/commerce/subscription/{$this->id}");

		expect($guest->getStatusCode())->toBe(404)
			->and($wrong->getStatusCode())->toBe(404)
			->and($other->getStatusCode())->toBe(404)
			->and(get('/commerce/subscription/no-such-thing')->getStatusCode())->toBe(404);
	});

	it('never shows what the store keeps to itself', function (): void {
		$html = (string)get("/commerce/subscription/{$this->id}")->getBody();

		expect($html)->not->toContain(($this->kept)()->accessToken())
			->and($html)->not->toContain('sub_1')
			->and($html)->not->toContain('cus_1');
	});

	it('lists the payments made for it', function (): void {
		($this->route)(new SubscriptionEvent('fake-sub', 'evt_r', SubscriptionEventType::Renewed, $this->id, 'sub_1', '', 1900000000, false, Money::of(1000, 'USD'), 'in_2', 'pi_2'));
		$html = (string)get("/commerce/subscription/{$this->id}")->getBody();

		expect(substr_count($html, 'class="commerce-subscription-order"'))->toBe(2)
			->and($html)->toContain((string)$this->order->number());
	});
});

describe('cancelling', function (): void {
	it('asks the provider to cancel at the end of the period, and changes nothing itself', function (): void {
		$response = post("/commerce/subscription/{$this->id}/cancel");
		$sub      = ($this->kept)();

		expect($response->getStatusCode())->toBe(303)
			->and($response->getHeaderLine('Location'))->toContain("/commerce/subscription/{$this->id}", 'requested=1')
			->and($this->fake->cancellations)->toBe([['ref' => 'sub_1', 'atPeriodEnd' => true]])
			->and($sub->status())->toBe('active')
			->and($sub->cancelAtPeriodEnd())->toBeFalse();
	});

	it('says the request was sent, when the buyer comes back to the page', function (): void {
		expect((string)get("/commerce/subscription/{$this->id}?requested=1")->getBody())->toContain('commerce-subscription-requested');
	});

	it('works from the signed link, and sends the buyer back with it', function (): void {
		$token = ($this->token)();
		($this->guest)();

		$response = post("/commerce/subscription/{$this->id}/cancel?t=" . rawurlencode($token));

		expect($response->getStatusCode())->toBe(303)
			->and($response->getHeaderLine('Location'))->toContain('t=' . rawurlencode($token))
			->and($this->fake->cancellations)->toHaveCount(1);
	});

	it('never cancels on a GET', function (): void {
		expect(get("/commerce/subscription/{$this->id}/cancel")->getStatusCode())->toBe(405)
			->and($this->fake->cancellations)->toBe([]);
	});

	it('needs the site\'s own token to cancel', function (): void {
		withHeader('X-CSRF-Token', 'not-the-token');

		expect(post("/commerce/subscription/{$this->id}/cancel")->getStatusCode())->toBe(403)
			->and($this->fake->cancellations)->toBe([]);
	});

	it('is not there for someone else to cancel', function (): void {
		($this->as)('sam');

		expect(post("/commerce/subscription/{$this->id}/cancel")->getStatusCode())->toBe(404)
			->and($this->fake->cancellations)->toBe([]);
	});

	it('refuses to cancel what is not running', function (string $status): void {
		$this->subs->withLock($this->id, static fn (SubscriptionData $s): SubscriptionData => $s->with(['status' => $status]));
		$response = post("/commerce/subscription/{$this->id}/cancel", [], ['Accept' => 'application/json']);

		expect($response->getStatusCode())->toBe(409)
			->and(($this->code)($response))->toBe('subscription_not_live')
			->and($this->fake->cancellations)->toBe([]);
	})->with(['canceled', 'pending']);

	it('says so when the provider will not cancel, and changes nothing', function (): void {
		$this->fake->failNextCancel();
		$response = post("/commerce/subscription/{$this->id}/cancel", [], ['Accept' => 'application/json']);

		expect($response->getStatusCode())->toBe(502)
			->and(($this->code)($response))->toBe('provider_error')
			->and(($this->kept)()->status())->toBe('active');
	});

	it('shows that it ends with the period once the provider has said so, and offers no second cancel', function (): void {
		($this->route)(new SubscriptionEvent('fake-sub', 'evt_u', SubscriptionEventType::Updated, $this->id, 'sub_1', 'active', 1900000000, true));
		$html = (string)get("/commerce/subscription/{$this->id}")->getBody();

		expect($html)->toContain('commerce-subscription-ending')
			->and(html_entity_decode($html))->not->toContain("/commerce/subscription/{$this->id}/cancel");
	});
});

describe('the card', function (): void {
	it('sends the buyer to the provider\'s own page for it', function (): void {
		$response = get("/commerce/subscription/{$this->id}/billing");

		expect($response->getStatusCode())->toBe(303)
			->and($response->getHeaderLine('Location'))->toBe('https://pay.example/portal/cus_1');
	});

	it('is not there when the provider has no such page for this buyer', function (): void {
		$this->subs->withLock($this->id, static fn (SubscriptionData $s): SubscriptionData => $s->with(['providerCustomerRef' => '']));

		expect(get("/commerce/subscription/{$this->id}/billing")->getStatusCode())->toBe(404);
	});

	it('is not there for someone else', function (): void {
		($this->as)('sam');

		expect(get("/commerce/subscription/{$this->id}/billing")->getStatusCode())->toBe(404);
	});
});

it('still answers a subscriber when the store\'s Commerce add-on has lapsed', function (): void {
	$this->c->set(CommerceGate::class, new ClosedCommerceGate());

	expect(get("/commerce/subscription/{$this->id}")->getStatusCode())->toBe(200)
		->and(post("/commerce/subscription/{$this->id}/cancel")->getStatusCode())->toBe(303)
		->and(get("/commerce/subscription/{$this->id}/billing")->getStatusCode())->toBe(303);
});

describe('in the store\'s own templates', function (): void {
	it('lists the signed-in customer\'s subscriptions, newest first, with a link to each', function (): void {
		// The first one is a day old.
		$this->subs->withLock($this->id, static fn (SubscriptionData $s): SubscriptionData => $s->with(['created' => date(DATE_ATOM, time() - 86400)]));
		post('/commerce/cart/clear');
		postJson('/commerce/cart/add', ['productId' => 'club', 'variantId' => '', 'qty' => 2]);
		post('/commerce/checkout', ['provider' => 'fake-sub', 'email' => 'pat@example.test'], ['Accept' => 'application/json']);
		$this->c->get(ActorContext::class)->set(new Actor(ActorKind::Member, 'pat', 'customers'));

		$out = renderTwig('{% for s in cms.commerce.subscriptions() %}{{ s.status }}:{{ s.recurringTotal }}:{{ s.url ? "link" : "none" }}:{{ s.accessToken is defined ? "SECRET" : "" }};{% endfor %}');

		expect($out)->toBe('pending:2000:link:;active:1000:link:;');
	});

	it('gives a guest none, and another customer none of Pat\'s', function (): void {
		$this->c->get(ActorContext::class)->set(Actor::anonymous());
		$guest = renderTwig('{{ cms.commerce.subscriptions()|length }}');
		$this->c->get(ActorContext::class)->set(new Actor(ActorKind::Member, 'sam', 'customers'));

		expect($guest)->toBe('0')->and(renderTwig('{{ cms.commerce.subscriptions()|length }}'))->toBe('0');
	});

	it('opens one subscription by its signed link', function (): void {
		$this->c->get(ActorContext::class)->set(Actor::anonymous());
		$token = ($this->token)();

		expect(renderTwig("{{ cms.commerce.subscription('{$this->id}', '{$token}').status }}"))->toBe('active')
			->and(renderTwig("{{ cms.commerce.subscription('{$this->id}', 'made-up') is null ? 'none' : 'open' }}"))->toBe('none')
			->and(renderTwig("{{ cms.commerce.subscription('{$this->id}') is null ? 'none' : 'open' }}"))->toBe('none');
	});

	it('makes the signed address of a subscription for the store\'s own emails, and for nobody who made one up', function (): void {
		$whole = ($this->kept)()->toArray();
		$this->c->get(TwigEngine::class);

		$url  = renderTwig('{{ cms.commerce.subscriptionUrl(data) }}', ['data' => $whole]);
		$fake = renderTwig('{{ cms.commerce.subscriptionUrl(data) }}', ['data' => ['id' => $this->id, 'accessToken' => 'guessed']]);

		expect($url)->toContain("/commerce/subscription/{$this->id}?t=")
			->and($fake)->toBe('');
	});

	it('makes that address from the order of one of its payments too: what a receipt is given', function (): void {
		$url = renderTwig('{{ cms.commerce.subscriptionUrl(data) }}', ['data' => $this->orders->find($this->order->id())->toArray()]);
		$not = renderTwig('{{ cms.commerce.subscriptionUrl(data) }}', ['data' => ['id' => $this->order->id(), 'accessToken' => 'guessed', 'subscriptionId' => $this->id]]);

		expect($url)->toContain("/commerce/subscription/{$this->id}?t=")->and($not)->toBe('');
	});

	it('links to the subscription from the page of the order that started it', function (): void {
		$url  = $this->c->get(CheckoutService::class)->orderUrl('return', $this->order);
		$path = (string)parse_url($url, PHP_URL_PATH) . '?' . (string)parse_url($url, PHP_URL_QUERY);
		$html = html_entity_decode((string)get($path)->getBody());

		expect($html)->toContain("/commerce/subscription/{$this->id}?t=");
	});
});
