<?php

declare(strict_types=1);

use Odan\Session\MemorySession;
use TotalCMS\Domain\Commerce\Cart\CartItem;
use TotalCMS\Domain\Commerce\Cart\CartRepository;
use TotalCMS\Domain\Commerce\Cart\CartService;
use TotalCMS\Domain\Commerce\Cart\CartStore;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Pricing\PricingContext;
use TotalCMS\Domain\Commerce\Pricing\PricingService;
use TotalCMS\Domain\Commerce\Service\CommerceInstaller;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\CurrentCustomer;
use TotalCMS\Domain\Commerce\Service\ProductCatalog;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

/*
 * The cart holds what a visitor wants: product ids and quantities, nothing
 * else. Every price it shows is worked out from the catalog when it is
 * looked at, so nothing a browser sends can change what is charged.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c = $this->app->getContainer();
	$this->c->get(CommerceInstaller::class)->install();

	$save = fn (array $product) => $this->c->get(ObjectSaver::class)->saveObject('products', $product);
	$save(['id' => 'mug', 'title' => 'Mug', 'status' => 'active', 'price' => 12.5]);
	$save(['id' => 'poster', 'title' => 'Poster', 'status' => 'active', 'price' => 8]);
	$save(['id' => 'print', 'title' => 'Signed Print', 'status' => 'active', 'price' => 40, 'trackInventory' => true, 'stock' => 3]);
	$save(['id' => 'vinyl', 'title' => 'Vinyl', 'status' => 'active', 'price' => 25, 'trackInventory' => true, 'stock' => 1, 'allowBackorder' => true]);
	$save(['id' => 'tee', 'title' => 'Tee', 'status' => 'active', 'price' => 20, 'trackInventory' => true, 'stock' => 99, 'variants' => [
		'large' => ['id' => 'large', 'title' => 'Large', 'price' => 22, 'stock' => 2],
		'small' => ['id' => 'small', 'title' => 'Small', 'stock' => 50],
	]]);

	$this->session = new MemorySession();
	$this->store   = new CartStore($this->session);
	$this->cart    = new CartService($this->store, $this->c->get(ProductCatalog::class), $this->c->get(PricingService::class), $this->c->get(CommerceSettings::class), $this->c->get(CartRepository::class), $this->c->get(CurrentCustomer::class), $this->c->get(LoggerFactory::class));
	$this->usd     = new PricingContext('USD', null, null, []);

	$this->setting = function (array $settings): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = $settings + $config->commerce;
	};
	$this->change = function (string $id, array $fields): void {
		$product = $this->c->get(ObjectFetcher::class)->fetchObject('products', $id)->toArray();
		$this->c->get(ObjectUpdater::class)->updateObject('products', $id, $fields + $product);
	};
	$this->refused = function (Closure $attempt): CommerceException {
		try {
			$attempt();
		} catch (CommerceException $e) {
			return $e;
		}

		throw new RuntimeException('Nothing was refused');
	};
});

describe('what the session holds', function (): void {
	it('stores only ids and quantities in the session', function (): void {
		$this->cart->add('tee', 'large', 2);
		$raw = $this->session->get(SessionKeys::COMMERCE_CART);

		expect($raw['items'][CartItem::lineKey('tee', 'large')])->toBe(['productId' => 'tee', 'variantId' => 'large', 'qty' => 2])
			->and(json_encode($raw))->not->toContain('price')
			->and(array_keys($raw))->toBe(['v', 'items', 'attributes', 'cartId', 'discountCode'])
			->and($raw['v'])->toBe(1);
	});

	it('keys a line by its product and variant, in twelve hex characters', function (): void {
		expect(CartItem::lineKey('tee', 'large'))->toBe(substr(sha1("tee\0large"), 0, 12))
			->and(CartItem::lineKey('tee', 'large'))->not->toBe(CartItem::lineKey('teelarge', ''))
			->and(CartItem::lineKey('tee', 'large'))->toMatch('/^[0-9a-f]{12}$/');
	});

	it('survives a session that holds something else under its key', function (mixed $junk): void {
		$this->session->set(SessionKeys::COMMERCE_CART, $junk);

		expect($this->cart->items())->toBe([])
			->and($this->cart->view($this->usd)->lines)->toBe([]);

		$this->cart->add('mug', '', 1);
		expect($this->cart->items())->toHaveCount(1);
	})->with([
		'text'           => ['garbage'],
		'wrong shape'    => [['items' => 'nope']],
		'tampered items' => [['v' => 1, 'items' => ['x' => ['productId' => ['a'], 'qty' => 'many'], 'y' => 5, 'z' => ['productId' => 'mug', 'variantId' => '', 'qty' => -4]]]],
	]);

	it('stores a discount code in uppercase, and nothing else about it', function (): void {
		$this->store->setDiscountCode(' save10 ');
		$raw = $this->session->get(SessionKeys::COMMERCE_CART);

		expect($this->store->discountCode())->toBe('SAVE10')
			->and($raw['discountCode'])->toBe('SAVE10')
			->and(array_keys($raw))->toBe(['v', 'items', 'attributes', 'cartId', 'discountCode']);

		$this->store->setDiscountCode('');
		expect($this->store->discountCode())->toBe('');
	});

	it('remembers which saved cart it belongs to', function (): void {
		expect($this->store->cartId())->toBeNull();

		$this->store->setCartId('abc123');
		expect($this->store->cartId())->toBe('abc123');

		$this->store->setCartId(null);
		expect($this->store->cartId())->toBeNull();
	});

	it('is emptied completely by clear()', function (): void {
		($this->setting)(['checkoutAttributes' => ['giftNote']]);
		$this->cart->add('mug', '', 1);
		$this->cart->setAttribute('giftNote', 'Happy birthday');
		$this->store->setDiscountCode('SAVE10');
		$this->store->setCartId('abc123');

		$this->cart->clear();

		expect($this->cart->items())->toBe([])
			->and($this->store->attributes())->toBe([])
			->and($this->store->discountCode())->toBe('')
			->and($this->store->cartId())->toBeNull();
	});
});

describe('adding', function (): void {
	it('merges repeat adds into one line', function (): void {
		$this->cart->add('mug', '', 2);
		$this->cart->add('mug', '', 3);

		expect($this->cart->items())->toHaveCount(1)
			->and($this->cart->items()[CartItem::lineKey('mug', '')]->qty)->toBe(5);
	});

	it('keeps each variant on its own line', function (): void {
		$this->cart->add('tee', 'large', 1);
		$this->cart->add('tee', 'small', 1);

		expect(array_keys($this->cart->items()))->toBe([CartItem::lineKey('tee', 'large'), CartItem::lineKey('tee', 'small')]);
	});

	it('refuses what the catalog will not sell', function (string $product, string $variant, string $code): void {
		expect(($this->refused)(fn () => $this->cart->add($product, $variant, 1))->errorCode)->toBe($code)
			->and($this->cart->items())->toBe([]);
	})->with([
		['nothing', '', 'product_unavailable'], ['tee', '', 'variant_required'], ['tee', 'huge', 'variant_invalid'], ['mug', 'large', 'variant_invalid'],
	]);

	it('refuses a quantity below one', function (int $qty): void {
		$refusal = ($this->refused)(fn () => $this->cart->add('mug', '', $qty));

		expect($refusal->errorCode)->toBe('qty_invalid')
			->and($refusal->status)->toBe(422);
	})->with([0, -1]);

	it('refuses qty above cartMaxQty', function (): void {
		($this->setting)(['cartMaxQty' => 5]);

		expect(($this->refused)(fn () => $this->cart->add('mug', '', 6))->errorCode)->toBe('qty_invalid');

		// Repeat adds count together.
		$this->cart->add('mug', '', 4);
		expect(($this->refused)(fn () => $this->cart->add('mug', '', 2))->errorCode)->toBe('qty_invalid')
			->and($this->cart->items()[CartItem::lineKey('mug', '')]->qty)->toBe(4);
	});

	it('refuses a new line beyond cartMaxLines', function (): void {
		($this->setting)(['cartMaxLines' => 2]);
		$this->cart->add('mug', '', 1);
		$this->cart->add('poster', '', 1);

		$refusal = ($this->refused)(fn () => $this->cart->add('vinyl', '', 1));

		expect($refusal->errorCode)->toBe('cart_full')
			->and($refusal->status)->toBe(422);

		// More of a line already there is not a new line.
		$this->cart->add('mug', '', 1);
		expect($this->cart->items()[CartItem::lineKey('mug', '')]->qty)->toBe(2);
	});

	it('refuses out of stock when tracked without backorder', function (): void {
		$this->cart->add('print', '', 2);

		$refusal = ($this->refused)(fn () => $this->cart->add('print', '', 2));

		expect($refusal->errorCode)->toBe('out_of_stock')
			->and($refusal->status)->toBe(409)
			->and($this->cart->items()[CartItem::lineKey('print', '')]->qty)->toBe(2);

		$this->cart->add('print', '', 1);
		expect($this->cart->items()[CartItem::lineKey('print', '')]->qty)->toBe(3);
	});

	it("counts a variant's own stock, not the product's", function (): void {
		expect(($this->refused)(fn () => $this->cart->add('tee', 'large', 3))->errorCode)->toBe('out_of_stock');

		$this->cart->add('tee', 'small', 10);
		expect($this->cart->items()[CartItem::lineKey('tee', 'small')]->qty)->toBe(10);
	});

	it('allows over-stock adds when backorder is on', function (): void {
		$this->cart->add('vinyl', '', 5);

		expect($this->cart->items()[CartItem::lineKey('vinyl', '')]->qty)->toBe(5);
	});

	it('does not count stock for a product that does not track it', function (): void {
		$this->cart->add('mug', '', 50);

		expect($this->cart->items()[CartItem::lineKey('mug', '')]->qty)->toBe(50);
	});
});

describe('changing', function (): void {
	it('sets a line to the quantity given', function (): void {
		$this->cart->add('mug', '', 2);
		$this->cart->update(CartItem::lineKey('mug', ''), 7);

		expect($this->cart->items()[CartItem::lineKey('mug', '')]->qty)->toBe(7);
	});

	it('removes a line when updated to zero', function (): void {
		$this->cart->add('mug', '', 2);
		$this->cart->add('poster', '', 1);

		$this->cart->update(CartItem::lineKey('mug', ''), 0);

		expect(array_keys($this->cart->items()))->toBe([CartItem::lineKey('poster', '')]);
	});

	it('holds an update to the same limits as an add', function (): void {
		($this->setting)(['cartMaxQty' => 5]);
		$this->cart->add('mug', '', 1);
		$this->cart->add('print', '', 1);

		expect(($this->refused)(fn () => $this->cart->update(CartItem::lineKey('mug', ''), 6))->errorCode)->toBe('qty_invalid')
			->and(($this->refused)(fn () => $this->cart->update(CartItem::lineKey('mug', ''), -1))->errorCode)->toBe('qty_invalid')
			->and(($this->refused)(fn () => $this->cart->update(CartItem::lineKey('print', ''), 4))->errorCode)->toBe('out_of_stock')
			->and($this->cart->items()[CartItem::lineKey('mug', '')]->qty)->toBe(1);
	});

	it('removes a line, and does not mind when it is already gone', function (): void {
		$this->cart->add('mug', '', 2);

		$this->cart->remove(CartItem::lineKey('mug', ''));
		$this->cart->remove(CartItem::lineKey('mug', ''));
		$this->cart->update('000000000000', 3);

		expect($this->cart->items())->toBe([]);
	});
});

describe('looking at the cart', function (): void {
	it('prices every line from the catalog', function (): void {
		$this->cart->add('tee', 'large', 2);
		$this->cart->add('mug', '', 3);

		$view = $this->cart->view($this->usd);
		[$tee, $mug] = $view->lines;

		expect($view->count)->toBe(5)
			->and($view->subtotal)->toEqual(Money::of(4400 + 3750, 'USD'))
			->and($view->notices)->toBe([])
			->and($tee->lineKey)->toBe(CartItem::lineKey('tee', 'large'))
			->and($tee->productId)->toBe('tee')
			->and($tee->variantId)->toBe('large')
			->and($tee->title)->toBe('Tee')
			->and($tee->variantTitle)->toBe('Large')
			->and($tee->qty)->toBe(2)
			->and($tee->unitPrice)->toEqual(Money::of(2200, 'USD'))
			->and($tee->lineTotal)->toEqual(Money::of(4400, 'USD'))
			->and($tee->priceSource)->toBe('catalog')
			->and($mug->variantTitle)->toBe('')
			->and($mug->lineTotal)->toEqual(Money::of(3750, 'USD'));
	});

	it('is empty, at zero, before anything is added', function (): void {
		$view = $this->cart->view($this->usd);

		expect($view->lines)->toBe([])
			->and($view->count)->toBe(0)
			->and($view->subtotal)->toEqual(Money::zero('USD'))
			->and($view->isEmpty())->toBeTrue();
	});

	it('reprices on view from the catalog', function (): void {
		$this->cart->add('mug', '', 2);
		($this->change)('mug', ['price' => 15]);

		expect($this->cart->view($this->usd)->lines[0]->unitPrice)->toEqual(Money::of(1500, 'USD'))
			->and($this->cart->view($this->usd)->subtotal)->toEqual(Money::of(3000, 'USD'));
	});

	it('drops archived products on view and reports a notice', function (string $status): void {
		$this->cart->add('mug', '', 2);
		$this->cart->add('poster', '', 1);
		($this->change)('mug', ['status' => $status]);

		$view = $this->cart->view($this->usd);

		expect(array_column($view->lines, 'productId'))->toBe(['poster'])
			->and($view->notices)->toHaveCount(1)
			->and($view->notices[0])->toContain('Mug')
			->and($view->subtotal)->toEqual(Money::of(800, 'USD'))
			// Said once: the line is gone from the session too.
			->and(array_keys($this->cart->items()))->toBe([CartItem::lineKey('poster', '')])
			->and($this->cart->view($this->usd)->notices)->toBe([]);
	})->with(['archived', 'draft']);

	it('drops a line whose variant was retired', function (): void {
		$this->cart->add('tee', 'large', 1);
		$tee = $this->c->get(ObjectFetcher::class)->fetchObject('products', 'tee')->toArray();
		$tee['variants']['large']['active'] = false;
		$this->c->get(ObjectUpdater::class)->updateObject('products', 'tee', $tee);

		$view = $this->cart->view($this->usd);

		expect($view->lines)->toBe([])
			->and($view->notices[0])->toContain('Tee');
	});
});

describe('checkout attributes', function (): void {
	beforeEach(fn () => ($this->setting)(['checkoutAttributes' => ['giftNote', 'licenseKey']]));

	it('keeps an attribute the settings allow', function (): void {
		$this->cart->setAttribute('giftNote', '  Happy birthday  ');

		expect($this->store->attributes())->toBe(['giftNote' => 'Happy birthday']);
	});

	it('refuses attributes that are not whitelisted', function (): void {
		$refusal = ($this->refused)(fn () => $this->cart->setAttribute('price', '1'));

		expect($refusal->errorCode)->toBe('attribute_invalid')
			->and($refusal->status)->toBe(422)
			->and($this->store->attributes())->toBe([]);
	});

	it('truncates nothing and refuses attribute values over 200 chars', function (): void {
		$this->cart->setAttribute('giftNote', str_repeat('é', 200));

		expect(($this->refused)(fn () => $this->cart->setAttribute('giftNote', str_repeat('é', 201)))->errorCode)->toBe('attribute_invalid')
			->and(mb_strlen($this->store->attributes()['giftNote']))->toBe(200);
	});

	it('forgets an attribute set to nothing', function (): void {
		$this->cart->setAttribute('giftNote', 'Hi');
		$this->cart->setAttribute('giftNote', '   ');

		expect($this->store->attributes())->toBe([]);
	});
});

it('is built by the container, on the real session', function (): void {
	expect($this->c->get(CartService::class))->toBeInstanceOf(CartService::class);
});
