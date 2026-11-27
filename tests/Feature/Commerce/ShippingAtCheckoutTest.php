<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Data\ProductView;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Order\ShippingCalculator;
use TotalCMS\Domain\Commerce\Order\TableShippingCalculator;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * A store that charges for shipping. The mug is $12.50 and ships; the
 * guide is a $9.00 download.
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
	$this->c->get(ActorContext::class)->set(Actor::anonymous());

	$this->ship = function (array $settings): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = $settings + $config->commerce;
	};

	withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());
	$this->add = function (string $productId, int $qty = 1): void {
		expect(postJson('/commerce/cart/add', ['productId' => $productId, 'variantId' => '', 'qty' => $qty])->getStatusCode())->toBe(200);
	};
	$this->buy   = fn (array $body = []) => post('/commerce/checkout', $body + ['provider' => 'fake', 'email' => 'a@example.com', 'shippingAddress' => ['country' => 'US']], ['Accept' => 'application/json']);
	$this->count = fn (): int => count($this->c->get(IndexReader::class)->fetchIndex('orders')->objects);
	$this->order = function (): OrderData {
		$rows = $this->c->get(IndexReader::class)->fetchIndex('orders')->objects;
		expect($rows)->toHaveCount(1);

		return $this->orders->find((string)$rows[0]['id']);
	};
	$this->code = fn ($response): string => (string)(json_decode((string)$response->getBody(), true)['error']['code'] ?? '');
});

test('a product saved through the inventory part still ships by default', function (): void {
	$this->c->get(ActorContext::class)->set(Actor::system());
	$this->c->get(ObjectSaver::class)->saveObject('products', ['id' => 'poster', 'title' => 'Poster', 'status' => 'active', 'price' => 20]);

	$stored = $this->c->get(ObjectFetcher::class)->fetchObject('products', 'poster')->toArray();

	expect(ProductView::fromObject($stored)->requiresShipping)->toBeTrue();
});

test('the store works shipping out from its own settings', function (): void {
	expect($this->c->get(ShippingCalculator::class))->toBeInstanceOf(TableShippingCalculator::class);
});

describe('a flat rate', function (): void {
	beforeEach(fn () => ($this->ship)(['shippingMode' => 'flat', 'shippingFlatRate' => 5, 'shippingFreeOver' => 30]));

	it('is added to an order that ships, and the provider is asked for the total with it', function (): void {
		($this->add)('mug');
		($this->buy)();
		$order   = ($this->order)();
		$request = $this->fakeProvider->checkouts[0];

		expect($order->shippingTotal())->toBe(500)
			->and($order->grandTotal())->toBe(1750)
			->and($order->shippingLine())->toBe(['rateId' => 'flat', 'label' => 'Shipping', 'amount' => 500])
			->and($request->shipping->amount())->toBe(500)
			->and($request->total->amount())->toBe(1750);
	});

	it('is not charged on an order at or over the free amount', function (): void {
		($this->add)('mug', 3);
		($this->buy)();
		$order = ($this->order)();

		expect($order->subtotal())->toBe(3750)
			->and($order->shippingTotal())->toBe(0)
			->and($order->shippingLine()['label'])->toBe('Free shipping')
			->and($order->grandTotal())->toBe(3750);
	});

	it('is not charged on an order with nothing to ship', function (): void {
		($this->add)('guide');
		($this->buy)(['shippingAddress' => []]);

		expect(($this->order)()->shippingTotal())->toBe(0)
			->and(($this->order)()->grandTotal())->toBe(900);
	});

	it('is charged once, however many things ship', function (): void {
		($this->add)('mug');
		($this->add)('guide');
		($this->buy)();

		expect(($this->order)()->shippingTotal())->toBe(500)
			->and(($this->order)()->grandTotal())->toBe(1250 + 900 + 500);
	});

	it('is taxed with the goods only when the store taxes shipping', function (bool $taxShipping, int $tax): void {
		($this->ship)(['taxMode' => 'exclusive', 'taxShipping' => $taxShipping, 'taxRates' => [['country' => 'US', 'region' => '', 'rate' => '10', 'label' => 'Tax']]]);
		($this->add)('mug');
		($this->buy)();

		expect(($this->order)()->taxTotal())->toBe($tax)
			->and(($this->order)()->grandTotal())->toBe(1250 + 500 + $tax);
	})->with([[false, 125], [true, 175]]);
});

describe('a rate by where the order is going', function (): void {
	beforeEach(fn () => ($this->ship)(['shippingMode' => 'zones', 'shippingZones' => [
		['id' => 'zone_us', 'label' => 'United States', 'countries' => ['US'], 'regions' => [], 'rate' => 6, 'freeOver' => 0],
		['id' => 'zone_ca', 'label' => 'Canada', 'countries' => ['CA'], 'regions' => [], 'rate' => 14, 'freeOver' => 0],
	]]));

	it('charges the rate of the zone the address is in', function (string $country, int $shipping, string $label): void {
		($this->add)('mug');
		($this->buy)(['shippingAddress' => ['country' => $country]]);

		expect(($this->order)()->shippingTotal())->toBe($shipping)
			->and(($this->order)()->shippingLine()['label'])->toBe($label);
	})->with([['US', 600, 'United States'], ['CA', 1400, 'Canada']]);

	it('refuses an order going where the store does not ship, before anything is made', function (): void {
		($this->add)('mug');

		$res = ($this->buy)(['shippingAddress' => ['country' => 'JP']]);

		expect(($this->code)($res))->toBe('no_shipping')
			->and(($this->count)())->toBe(0)
			->and($this->fakeProvider->checkouts)->toBe([]);
	});

	it('ships a download anywhere: it does not ship', function (): void {
		($this->add)('guide');
		($this->buy)(['shippingAddress' => ['country' => 'JP']]);

		expect(($this->order)()->grandTotal())->toBe(900);
	});
});
