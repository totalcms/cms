<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use Tests\Fakes\FakeSubscriptionProvider;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\OrderAccess;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;
use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * On-page checkout: the buyer pays inside the store's own page. Everything
 * behind it is the hosted checkout: the same order, the same session at the
 * provider, the same webhook. Only where the payment form shows is different.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore();
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);
	$this->c->get(ActorContext::class)->set(Actor::anonymous());
	withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());

	// A provider that can mount its form in the page, beside the plain one.
	$this->embeds = new FakeSubscriptionProvider(embedded: true, id: 'embeds');
	$this->c->get(PaymentProviderRegistry::class)->register($this->embeds);
	$this->mode = function (string $mode): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['providers' => ['embeds', 'fake'], 'checkoutMode' => $mode] + $config->commerce;
	};
	($this->mode)('embedded');

	postJson('/commerce/cart/add', ['productId' => 'mug']);
	$this->buy  = fn (string $provider = 'embeds', array $headers = []) => post('/commerce/checkout', ['provider' => $provider, 'email' => 'a@example.com', 'shippingAddress' => ['country' => 'US']], $headers);
	$this->only = function (): OrderData {
		$rows = array_values($this->c->get(IndexReader::class)->fetchIndex('orders')->objects->all());
		expect($rows)->toHaveCount(1);

		return $this->orders->find((string)$rows[0]['id']);
	};
	$this->pay = fn (OrderData $order): string => "/commerce/pay/{$order->id()}?t=" . rawurlencode($this->c->get(OrderAccess::class)->token($order));
});

it('asks the provider for an embedded session', function (): void {
	$res   = ($this->buy)();
	$order = ($this->only)();

	expect($this->embeds->checkouts)->toHaveCount(1)
		->and($this->embeds->checkouts[0]->uiMode)->toBe('embedded')
		->and($this->embeds->checkouts[0]->returnUrl)->toContain("/commerce/return/{$order->id()}?t=")
		->and($this->embeds->checkouts[0]->total->amount())->toBe(1250)
		->and($res->getStatusCode())->toBe(303)
		->and($res->getHeaderLine('Location'))->toContain("/commerce/pay/{$order->id()}?t=")
		->and($order->providerClientSecret())->toBe('secret_' . $order->id())
		->and($order->providerSessionRef())->toBe('cs_fake_' . $order->id())
		->and($order->paymentStatus())->toBe('pending');
});

it('swaps in the mount fragment for HTMX with no redirect header', function (): void {
	$res   = ($this->buy)('embeds', ['HX-Request' => 'true']);
	$order = ($this->only)();
	$html  = (string)$res->getBody();

	expect($res->getStatusCode())->toBe(200)
		->and($res->hasHeader('HX-Redirect'))->toBeFalse()
		->and($res->getHeaderLine('HX-Retarget'))->toBe('#commerce-checkout-form')
		->and($res->getHeaderLine('HX-Reswap'))->toBe('outerHTML')
		->and($html)->toContain('id="commerce-checkout"', 'data-commerce-embed', 'data-module="/fake/embed.js"')
		->and(html_entity_decode($html))->toContain('"clientSecret":"secret_' . $order->id() . '"');
});

it('gives a script what it needs to mount the form itself', function (): void {
	$res   = ($this->buy)('embeds', ['Accept' => 'application/json']);
	$order = ($this->only)();
	$json  = json_decode((string)$res->getBody(), true);

	expect($json['embed'])->toBe(['moduleUrl' => '/fake/embed.js', 'config' => ['clientSecret' => 'secret_' . $order->id()]])
		->and($json['payUrl'])->toContain("/commerce/pay/{$order->id()}?t=")
		->and($json)->not->toHaveKey('redirectUrl');
});

it('tells the buyer on the pay page that tax comes at payment, when it is left to the provider', function (): void {
	$config              = $this->c->get(Config::class);
	$config->commerce    = ['taxMode' => 'provider'] + $config->commerce;
	$this->embeds->tax   = true;
	($this->buy)();

	expect((string)get(($this->pay)(($this->only)()))->getBody())->toContain('Tax is worked out when you pay');
});

describe('the pay page', function (): void {
	beforeEach(function (): void {
		($this->buy)();
		$this->order = ($this->only)();
	});

	it('mounts the provider\'s form for the order', function (): void {
		$res  = get(($this->pay)($this->order));
		$html = (string)$res->getBody();

		expect($res->getStatusCode())->toBe(200)
			->and($html)->toContain('data-commerce-embed', 'data-module="/fake/embed.js"', 'Order 1001', '$12.50', '/assets/commerce.js', "/commerce/cancel/{$this->order->id()}?t=")
			->and($html)->not->toContain('htmx')
			->and(html_entity_decode($html))->toContain('"clientSecret":"secret_' . $this->order->id() . '"');
	});

	it('says nothing of tax when the store works out its own', function (): void {
		expect((string)get(($this->pay)($this->order))->getBody())->not->toContain('Tax is worked out when you pay');
	});

	it('reuses the stored session on reload', function (): void {
		$first  = (string)get(($this->pay)($this->order))->getBody();
		$second = (string)get(($this->pay)($this->order))->getBody();

		expect($this->embeds->checkouts)->toHaveCount(1)
			->and(($this->only)()->id())->toBe($this->order->id())
			->and(html_entity_decode($second))->toContain('"clientSecret":"secret_' . $this->order->id() . '"')
			->and(substr_count($first, 'data-commerce-embed'))->toBe(1);
	});

	it('requires a valid token on the pay page', function (string $query): void {
		expect(get("/commerce/pay/{$this->order->id()}{$query}")->getStatusCode())->toBe(404);
	})->with(['', '?t=forged']);

	it('leaves the pay page once the order is paid', function (): void {
		$this->c->get(PaymentEventProcessor::class)->process(new \TotalCMS\Domain\Commerce\Payment\PaymentEvent('embeds', 'evt_1', PaymentEventType::Paid, $this->order->id(), $this->order->grandTotalMoney(), 'ref', 'pi_1'));
		$res = get(($this->pay)($this->order));

		expect($res->getStatusCode())->toBe(303)->and($res->getHeaderLine('Location'))->toContain("/commerce/return/{$this->order->id()}?t=");
	});

	it('clears the client secret when the order leaves pending', function (PaymentEventType $type): void {
		$this->c->get(PaymentEventProcessor::class)->process(new \TotalCMS\Domain\Commerce\Payment\PaymentEvent('embeds', 'evt_1', $type, $this->order->id(), $this->order->grandTotalMoney(), 'ref', 'pi_1'));

		expect($this->orders->find($this->order->id())->providerClientSecret())->toBe('');
	})->with([PaymentEventType::Paid, PaymentEventType::Expired, PaymentEventType::Failed]);

	it('clears the client secret of an order a newer checkout replaced', function (): void {
		($this->buy)();
		$stale = $this->orders->find($this->order->id());

		expect($stale->status())->toBe('cancelled')->and($stale->providerClientSecret())->toBe('');
	});

	it('never shows the client secret through the order a template is given', function (): void {
		$token = $this->c->get(OrderAccess::class)->token($this->order);
		$this->c->get(PhpSession::class)->isStarted() || $this->c->get(PhpSession::class)->start();

		expect(renderTwig('{{ cms.commerce.order(id, token)|json_encode }}', ['id' => $this->order->id(), 'token' => $token]))->not->toContain('secret_');
	});

	it('gives a custom pay page the same mount', function (): void {
		$vars = ['order' => $this->order->forBuyer(), 'token' => $this->c->get(OrderAccess::class)->token($this->order)];

		expect(renderTwig('{{ cms.commerce.embed(order, token) }}', $vars))->toContain('data-commerce-embed', 'data-module="/fake/embed.js"');
	});

	it('gives a template nothing to mount without the order\'s signed link', function (): void {
		$vars = ['order' => $this->order->forBuyer(), 'token' => $this->c->get(OrderAccess::class)->token($this->order)];

		expect(renderTwig('[{{ cms.commerce.embed(order) }}][{{ cms.commerce.embed(order, "forged") }}][{{ cms.commerce.embed({id: "no-such-order"}, token) }}][{{ cms.commerce.embed({}, token) }}]', $vars))->toBe('[][][][]');
	});
});

it('falls back to hosted for a provider that cannot embed', function (): void {
	$res = ($this->buy)('fake');

	expect($this->fakeProvider->checkouts[0]->uiMode)->toBe('hosted')
		->and($this->fakeProvider->checkouts[0]->returnUrl)->toBe('')
		->and($res->getStatusCode())->toBe(303)
		->and($res->getHeaderLine('Location'))->toStartWith('https://pay.example/')
		->and(($this->only)()->providerClientSecret())->toBe('');
});

it('stays hosted when checkoutMode is hosted', function (): void {
	($this->mode)('hosted');
	$res = ($this->buy)('embeds');

	expect($this->embeds->checkouts[0]->uiMode)->toBe('hosted')
		->and($res->getStatusCode())->toBe(303)
		->and($res->getHeaderLine('Location'))->toStartWith('https://pay.example/');
});

it('gives the checkout form an id the mount can take the place of', function (): void {
	$this->c->get(PhpSession::class)->isStarted() || $this->c->get(PhpSession::class)->start();

	expect(renderTwig('{{ cms.cart.checkoutForm() }}'))->toContain('id="commerce-checkout-form"');
});
