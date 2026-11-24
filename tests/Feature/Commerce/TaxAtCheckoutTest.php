<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Order\TableTaxCalculator;
use TotalCMS\Domain\Commerce\Order\TaxCalculator;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * A store that charges tax from its own table. The mug is $12.50 and
 * ships; the guide is a $9.00 download. California is taxed at 10%.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore();
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);
	$this->c->get(ActorContext::class)->set(Actor::system());
	$this->c->get(ObjectSaver::class)->saveObject('products', ['id' => 'guide', 'title' => 'Guide', 'status' => 'active', 'type' => 'digital', 'price' => 9, 'requiresShipping' => false]);
	$this->c->get(ObjectSaver::class)->saveObject('products', ['id' => 'gift-card', 'title' => 'Gift Card', 'status' => 'active', 'type' => 'digital', 'price' => 20, 'requiresShipping' => false, 'taxable' => false]);
	$this->c->get(ActorContext::class)->set(Actor::anonymous());

	$this->tax = function (string $mode = 'exclusive', array $more = []): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = $more + ['taxMode' => $mode, 'taxRates' => [['country' => 'US', 'region' => 'CA', 'rate' => '10', 'label' => 'CA sales tax']]] + $config->commerce;
	};

	withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());

	$this->add = function (string $productId, int $qty = 1): void {
		expect(postJson('/commerce/cart/add', ['productId' => $productId, 'variantId' => '', 'qty' => $qty])->getStatusCode())->toBe(200);
	};
	// A page that asks for JSON is told what was refused; a plain form is sent back to the cart with a notice.
	$this->buy   = fn (array $body, array $headers = []) => post('/commerce/checkout', $body + ['provider' => 'fake', 'email' => 'a@example.com'], $headers);
	$this->order = function (): OrderData {
		$rows = $this->c->get(IndexReader::class)->fetchIndex('orders')->objects;
		expect($rows)->toHaveCount(1);

		return $this->orders->find((string)$rows[0]['id']);
	};
	$this->code = fn ($response): string => (string)(json_decode((string)$response->getBody(), true)['error']['code'] ?? '');
	$this->form = function (): string {
		$session = $this->c->get(PhpSession::class);
		$session->isStarted() || $session->start();

		return renderTwig('{{ cms.cart.checkoutForm() }}');
	};
});

test('the store works tax out from its own table', function (): void {
	expect($this->c->get(TaxCalculator::class))->toBeInstanceOf(TableTaxCalculator::class);
});

describe('an order that ships', function (): void {
	it('is taxed by where it is going, and the provider is asked for the total with the tax', function (): void {
		($this->tax)();
		($this->add)('mug', 2);

		($this->buy)(['shippingAddress' => ['country' => 'US', 'region' => 'California']]);
		$order = ($this->order)();

		expect($order->subtotal())->toBe(2500)
			->and($order->taxTotal())->toBe(250)
			->and($order->grandTotal())->toBe(2750)
			->and($order->taxMode())->toBe('exclusive')
			->and($order->taxLines())->toBe([['label' => 'CA sales tax', 'rate' => '10', 'amount' => 250]])
			->and($order->lines()['l1']['taxTotal'])->toBe(250)
			->and($this->fakeProvider->checkouts[0]->total->amount())->toBe(2750)
			->and($this->fakeProvider->checkouts[0]->tax->amount())->toBe(250);
	});

	it('is not taxed where the store has no rate', function (): void {
		($this->tax)();
		($this->add)('mug', 2);

		($this->buy)(['shippingAddress' => ['country' => 'US', 'region' => 'TX']]);

		expect(($this->order)()->taxTotal())->toBe(0)
			->and(($this->order)()->grandTotal())->toBe(2500);
	});

	it('with tax already in the prices, costs the same and says how much of it is tax', function (): void {
		($this->tax)('inclusive');
		($this->add)('mug', 2);

		($this->buy)(['shippingAddress' => ['country' => 'US', 'region' => 'CA']]);
		$order = ($this->order)();

		expect($order->grandTotal())->toBe(2500)
			->and($order->taxTotal())->toBe(227)
			->and($order->taxMode())->toBe('inclusive');
	});
});

describe('an order with nothing to ship', function (): void {
	it('is taxed by where the buyer says they are', function (): void {
		($this->tax)();
		($this->add)('guide');

		($this->buy)(['billingAddress' => ['country' => 'US', 'region' => 'ca']]);
		$order = ($this->order)();

		expect($order->taxTotal())->toBe(90)
			->and($order->grandTotal())->toBe(990)
			->and($order->billingAddress()['region'] ?? '')->toBe('ca');
	});

	it('is refused without a country, and without a region where the tax depends on it', function (array $billing, string $code): void {
		($this->tax)();
		($this->add)('guide');

		$res = ($this->buy)(['billingAddress' => $billing], ['Accept' => 'application/json']);

		expect($res->getStatusCode())->toBe(422)
			->and(($this->code)($res))->toBe($code)
			// Refused before any order exists.
			->and(count($this->c->get(IndexReader::class)->fetchIndex('orders')->objects))->toBe(0);
	})->with([
		'nothing said'           => [[], 'address_required'],
		'a country with no region' => [['country' => 'US'], 'region_required'],
	]);

	it('needs no region in a country the store has one rate for, or none', function (): void {
		($this->tax)();
		($this->add)('guide');

		expect(($this->buy)(['billingAddress' => ['country' => 'DE']])->getStatusCode())->toBeLessThan(400)
			->and(($this->order)()->taxTotal())->toBe(0);
	});

	it('does not tax a product the store marked as not taxable', function (): void {
		($this->tax)();
		($this->add)('guide');
		($this->add)('gift-card');

		($this->buy)(['billingAddress' => ['country' => 'US', 'region' => 'CA']]);

		expect(($this->order)()->taxTotal())->toBe(90)
			->and(($this->order)()->grandTotal())->toBe(2990);
	});
});

describe('the checkout form', function (): void {
	it('asks a buyer of something that does not ship where they are, when the store charges tax', function (): void {
		($this->tax)();
		($this->add)('guide');

		$form = ($this->form)();

		expect($form)->toContain('name="billingAddress[country]"', 'autocomplete="billing country"', 'name="billingAddress[region]"', 'autocomplete="billing address-level1"')
			->not->toContain('shippingAddress');
	});

	it('does not ask when the store charges no tax', function (): void {
		($this->add)('guide');

		expect(($this->form)())->toContain('name="email"')->not->toContain('billingAddress');
	});

	it('asks once, for the shipping address, when something ships', function (): void {
		($this->tax)();
		($this->add)('mug');
		($this->add)('guide');

		expect(($this->form)())->toContain('name="shippingAddress[country]"')->not->toContain('billingAddress');
	});
});

test('a store that charges no tax is as it was: nothing is asked and nothing is added', function (): void {
	($this->add)('guide');

	expect(($this->buy)([])->getStatusCode())->toBeLessThan(400)
		->and(($this->order)()->taxTotal())->toBe(0)
		->and(($this->order)()->grandTotal())->toBe(900);
});
