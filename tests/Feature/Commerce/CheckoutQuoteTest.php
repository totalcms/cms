<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * What an order will come to, before the buyer commits to it.
 *
 * Shipping and tax depend on where the order is going, which the buyer
 * says on the checkout form. So the form shows the order's totals, and asks
 * the store for them again whenever what they depend on changes. Asking
 * makes no order and calls no payment provider.
 *
 * The mug is $12.50 and ships; the guide is a $9.00 download.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore();
	$this->c = $this->app->getContainer();
	$this->c->get(ActorContext::class)->set(Actor::system());
	$this->c->get(ObjectSaver::class)->saveObject('products', ['id' => 'guide', 'title' => 'Guide', 'status' => 'active', 'type' => 'digital', 'price' => 9, 'requiresShipping' => false]);
	$this->c->get(ActorContext::class)->set(Actor::anonymous());

	$this->store = function (array $settings): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = $settings + $config->commerce;
	};
	($this->store)([
		'shippingMode' => 'zones',
		'shippingZones' => [['id' => 'zone_us', 'label' => 'United States', 'countries' => ['US'], 'regions' => [], 'rate' => 6, 'freeOver' => 0]],
		'taxMode'  => 'exclusive',
		'taxRates' => [['country' => 'US', 'region' => 'CA', 'rate' => '10', 'label' => 'CA sales tax']],
	]);

	withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());
	$this->add   = fn (string $productId, int $qty = 1) => postJson('/commerce/cart/add', ['productId' => $productId, 'variantId' => '', 'qty' => $qty]);
	$this->quote = fn (array $body = []): array => (array)json_decode((string)post('/commerce/checkout/quote', $body + ['provider' => 'fake'], ['Accept' => 'application/json'])->getBody(), true);
	$this->html  = fn (array $body = []): string => (string)post('/commerce/checkout/quote', $body + ['provider' => 'fake'])->getBody();
	$this->orders = fn (): int => count($this->c->get(IndexReader::class)->fetchIndex('orders')->objects);
});

describe('what it works out', function (): void {
	beforeEach(fn () => ($this->add)('mug', 2));

	it('adds shipping and tax for where the order is going', function (): void {
		$quote = ($this->quote)(['shippingAddress' => ['country' => 'US', 'region' => 'CA']]);

		expect($quote)->toMatchArray([
			'currency'      => 'USD',
			'subtotal'      => 2500,
			'discount'      => 0,
			'shipping'      => 600,
			'shippingLabel' => 'United States',
			'shippingKnown' => true,
			'tax'           => 250,
			'taxLabel'      => 'CA sales tax',
			'taxIncluded'   => false,
			'taxAtPayment'  => false,
			'total'         => 3350,
			'problem'       => '',
		]);
	});

	it('leaves shipping open until it knows where the order is going', function (): void {
		$quote = ($this->quote)();

		expect($quote['shippingKnown'])->toBeFalse()
			->and($quote['shipping'])->toBe(0)
			->and($quote['total'])->toBe(2500)
			->and($quote['problem'])->toBe('');
	});

	it('says so when the store does not ship there', function (): void {
		$quote = ($this->quote)(['shippingAddress' => ['country' => 'JP']]);

		expect($quote['shippingKnown'])->toBeFalse()
			->and($quote['problem'])->toContain('cannot be shipped');
	});

	it('takes a discount code off first', function (): void {
		seedDiscount('fall15');
		post('/commerce/cart/discount', ['code' => 'fall15'], ['Accept' => 'application/json']);

		$quote = ($this->quote)(['shippingAddress' => ['country' => 'US', 'region' => 'CA']]);

		expect($quote['discount'])->toBe(375)
			->and($quote['discountCode'])->toBe('FALL15')
			->and($quote['tax'])->toBe(213)
			->and($quote['total'])->toBe(2500 - 375 + 600 + 213);
	});

	it('makes no order and asks nothing of the payment provider', function (): void {
		($this->quote)(['shippingAddress' => ['country' => 'US', 'region' => 'CA']]);

		expect(($this->orders)())->toBe(0)
			->and($this->fakeProvider->checkouts)->toBe([]);
	});

	it('says the tax comes at payment when the provider works it out', function (): void {
		($this->store)(['taxBy' => 'provider']);
		$this->fakeProvider->tax = true;

		$quote = ($this->quote)(['shippingAddress' => ['country' => 'US', 'region' => 'CA']]);

		expect($quote['taxAtPayment'])->toBeTrue()
			->and($quote['tax'])->toBe(0)
			->and($quote['total'])->toBe(3100);
	});

	it('says the tax is part of the prices when it is', function (): void {
		($this->store)(['taxMode' => 'inclusive']);

		$quote = ($this->quote)(['shippingAddress' => ['country' => 'US', 'region' => 'CA']]);

		expect($quote['taxIncluded'])->toBeTrue()
			->and($quote['tax'])->toBe(227)
			->and($quote['total'])->toBe(3100);
	});
});

it('has no shipping for a cart with nothing to ship', function (): void {
	($this->add)('guide');
	$quote = ($this->quote)(['billingAddress' => ['country' => 'US', 'region' => 'CA']]);

	expect($quote['ships'])->toBeFalse()
		->and($quote['shipping'])->toBe(0)
		->and($quote['tax'])->toBe(90)
		->and($quote['total'])->toBe(990);
});

it('has nothing to say about an empty cart', function (): void {
	$res = post('/commerce/checkout/quote', ['provider' => 'fake'], ['Accept' => 'application/json']);

	expect($res->getStatusCode())->toBe(422)
		->and(json_decode((string)$res->getBody(), true)['error']['code'])->toBe('cart_empty');
});

describe('as the part of the form that shows it', function (): void {
	beforeEach(fn () => ($this->add)('mug', 2));

	it('lists each amount, in the store\'s money', function (): void {
		$html = ($this->html)(['shippingAddress' => ['country' => 'US', 'region' => 'CA']]);

		expect($html)->toContain('Subtotal', '$25.00', 'United States', '$6.00', 'CA sales tax', '$2.50', 'Total', '$33.50')
			->and($html)->not->toContain('<html', '<form');
	});

	it('says what is still to come, instead of a number it does not have', function (): void {
		expect(($this->html)())->toContain('Shipping', 'Worked out from your address')
			->and(($this->html)())->not->toContain('$0.00');
	});

	it('says the store does not ship there, where the buyer is looking', function (): void {
		expect(($this->html)(['shippingAddress' => ['country' => 'JP']]))->toContain('role="alert"', 'cannot be shipped');
	});

	it('says tax is worked out when the buyer pays, when it is', function (): void {
		($this->store)(['taxBy' => 'provider']);
		$this->fakeProvider->tax = true;

		expect(($this->html)(['shippingAddress' => ['country' => 'US', 'region' => 'CA']]))->toContain('Tax', 'Worked out when you pay');
	});

	it('shows free shipping as free', function (): void {
		($this->store)(['shippingMode' => 'flat', 'shippingFlatRate' => 5, 'shippingFreeOver' => 20]);

		expect(($this->html)(['shippingAddress' => ['country' => 'US']]))->toContain('Free shipping', 'Free');
	});
});

describe('on the checkout form', function (): void {
	beforeEach(function (): void {
		($this->add)('mug', 2);
		$session = $this->c->get(PhpSession::class);
		$session->isStarted() || $session->start();
		$this->form = renderTwig('{{ cms.cart.checkoutForm() }}');
	});

	it('is there from the start, with what is known so far', function (): void {
		expect($this->form)->toContain('id="commerce-checkout-totals"', 'Subtotal', '$25.00', 'Worked out from your address');
	});

	it('asks again whenever the form changes, sending the whole form', function (): void {
		expect($this->form)->toContain('hx-post="/commerce/checkout/quote"', 'hx-include="#commerce-checkout-form"')
			->and($this->form)->toMatch('/id="commerce-checkout-totals"[^>]*hx-trigger="change from:#commerce-checkout-form/');
	});
});

it('must come from the site itself, like every storefront POST', function (): void {
	withHeader('X-CSRF-Token', 'wrong');
	($this->add)('mug');

	expect(post('/commerce/checkout/quote', ['provider' => 'fake'], ['Accept' => 'application/json'])->getStatusCode())->toBeIn([400, 403, 419]);
});
