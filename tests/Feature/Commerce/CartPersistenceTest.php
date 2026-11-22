<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Auth\Service\LoginService;
use TotalCMS\Domain\Collection\Service\CollectionRemover;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Commerce\Cart\CartItem;
use TotalCMS\Domain\Commerce\Cart\CartLoginListener;
use TotalCMS\Domain\Commerce\Cart\CartRepository;
use TotalCMS\Domain\Commerce\Cart\CartService;
use TotalCMS\Domain\Commerce\Cart\CartStore;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;

/*
 * A customer's cart is kept on the server while they are signed in, so it
 * is there on their next visit and on their other devices. A cart started
 * before signing in is joined to it and not lost, which matters because the
 * password and passkey logins both throw the old session away.
 */

beforeEach(function (): void {
	bootCommerceStore();
	$this->c = $this->app->getContainer();

	// Customers sign in to their own collection, not the admin one.
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'members', 'name' => 'Members', 'schema' => 'auth']);
	$this->c->get(ObjectSaver::class)->saveObject('members', ['id' => 'ann', 'email' => 'ann@example.com', 'name' => 'Ann', 'password' => 'correct horse battery', 'active' => true]);
	$config           = $this->c->get(Config::class);
	$config->commerce = ['customerCollection' => 'members', 'checkoutAttributes' => ['giftNote']] + $config->commerce;

	$this->cart  = $this->c->get(CartService::class);
	$this->store = $this->c->get(CartStore::class);
	$this->repo  = $this->c->get(CartRepository::class);
	$this->key   = CartItem::lineKey('mug', '');

	/** Every stored cart, as [id => object]. */
	$this->storedCarts = function (): array {
		$this->c->get(ObjectFetcher::class)->clearCollectionCache('carts');
		$carts = [];
		foreach ($this->c->get(IndexReader::class)->fetchIndex('carts')->objects->pluck('id')->all() as $id) {
			$carts[$id] = $this->c->get(ObjectFetcher::class)->fetchObject('carts', (string)$id)->toArray();
		}

		return $carts;
	};
	/** Sign Ann in for the rest of the test, as a request after login has it. */
	$this->signedIn = fn (string $user = 'ann', string $collection = 'members') => $this->c->get(ActorContext::class)->set(new Actor(ActorKind::Member, $user, $collection));
	/** The login event, as LoginService fires it. */
	$this->login = fn (string $user, string $collection) => ($this->c->get(CartLoginListener::class))(['user' => $user, 'collection' => $collection]);
	/** A cart Ann left on the server on an earlier visit. */
	$this->annsCart = fn (int $qty = 1): string => $this->repo->save(null, [
		'customerCollection' => 'members', 'customerId' => 'ann', 'status' => 'active', 'items' => [['productId' => 'mug', 'variantId' => '', 'qty' => $qty]], 'attributes' => ['giftNote' => 'From before'],
	]);
});

describe('signing in', function (): void {
	test('joins the cart started before signing in to the cart already stored', function (): void {
		$stored = ($this->annsCart)(1);
		$this->cart->add('mug', '', 2);
		$this->cart->add('tee', 'large', 1);

		($this->login)('ann', 'members');

		$carts = ($this->storedCarts)();
		expect($carts)->toHaveCount(1)
			->and($carts[$stored]['status'])->toBe('active')
			->and($carts[$stored]['customerId'])->toBe('ann')
			->and($carts[$stored]['customerCollection'])->toBe('members')
			->and($carts[$stored]['items'])->toBe([
				['productId' => 'mug', 'variantId' => '', 'qty' => 3],
				['productId' => 'tee', 'variantId' => 'large', 'qty' => 1],
			])
			// A login that keeps the session (registration does) has the joined cart at once.
			->and($this->store->items()[$this->key]->qty)->toBe(3)
			->and($this->store->cartId())->toBe($stored);
	});

	test('stores the cart of a customer who had none', function (): void {
		$this->cart->add('mug', '', 2);

		($this->login)('ann', 'members');

		$carts = array_values(($this->storedCarts)());
		expect($carts)->toHaveCount(1)
			->and($carts[0]['customerId'])->toBe('ann')
			->and($carts[0]['items'])->toBe([['productId' => 'mug', 'variantId' => '', 'qty' => 2]])
			->and($this->store->cartId())->toBe($carts[0]['id']);
	});

	test('with an empty cart stores nothing', function (): void {
		($this->login)('ann', 'members');

		expect(($this->storedCarts)())->toBe([]);
	});

	test('to another collection does nothing', function (): void {
		$this->cart->add('mug', '', 2);

		($this->login)('admin-user-test-com', 'auth');

		expect(($this->storedCarts)())->toBe([])
			->and($this->store->items()[$this->key]->qty)->toBe(2)
			->and($this->store->cartId())->toBeNull();
	});

	test('never joins more of one item than a cart may hold', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['cartMaxQty' => 4] + $config->commerce;
		$stored           = ($this->annsCart)(3);
		$this->cart->add('mug', '', 3);

		($this->login)('ann', 'members');

		expect(($this->storedCarts)()[$stored]['items'][0]['qty'])->toBe(4)
			->and($this->store->items()[$this->key]->qty)->toBe(4);
	});

	test('keeps what the buyer typed this visit over what was stored', function (): void {
		$stored = ($this->annsCart)(1);
		$this->cart->add('mug', '', 1);
		$this->cart->setAttribute('giftNote', 'From today');

		($this->login)('ann', 'members');

		expect(($this->storedCarts)()[$stored]['attributes'])->toBe(['giftNote' => 'From today']);
	});

	test('the real login says which collection the user signed in to', function (): void {
		$seen = null;
		$this->c->get(EventDispatcher::class)->listen(CoreEvent::USER_LOGIN, function (array $payload) use (&$seen): void {
			$seen = $payload;
		});
		$this->cart->add('mug', '', 2);

		$this->c->get(LoginService::class)->authenticate('ann', 'correct horse battery', 'members');

		expect($seen['user'])->toBe('ann')
			->and($seen['collection'])->toBe('members')
			// And the cart listener, wired in the container, ran on it.
			->and(array_values(($this->storedCarts)())[0]['items'])->toBe([['productId' => 'mug', 'variantId' => '', 'qty' => 2]]);
	});
});

describe('a signed-in customer', function (): void {
	test('gets their stored cart back in a fresh session, as after a password login', function (): void {
		$stored = ($this->annsCart)(2);
		($this->signedIn)();

		expect($this->cart->items()[$this->key]->qty)->toBe(2)
			->and($this->store->cartId())->toBe($stored)
			->and($this->store->attributes())->toBe(['giftNote' => 'From before'])
			->and($this->cart->view($this->c->get(\TotalCMS\Domain\Commerce\Pricing\PricingContextFactory::class)->current())->count)->toBe(2);
	});

	test('has every change written to their stored cart', function (): void {
		$stored = ($this->annsCart)(1);
		($this->signedIn)();

		$this->cart->update($this->key, 5);
		expect(($this->storedCarts)()[$stored]['items'])->toBe([['productId' => 'mug', 'variantId' => '', 'qty' => 5]]);

		$this->cart->add('tee', 'large', 1);
		expect(($this->storedCarts)()[$stored]['items'])->toHaveCount(2);

		$this->cart->remove($this->key);
		expect(($this->storedCarts)()[$stored]['items'])->toBe([['productId' => 'tee', 'variantId' => 'large', 'qty' => 1]]);

		$this->cart->setAttribute('giftNote', 'Changed');
		expect(($this->storedCarts)()[$stored]['attributes'])->toBe(['giftNote' => 'Changed']);

		$this->cart->clear();
		expect(($this->storedCarts)()[$stored]['items'])->toBe([])
			->and(($this->storedCarts)())->toHaveCount(1);
	});

	test('with no stored cart gets one on their first change, and only one', function (): void {
		($this->signedIn)();
		expect($this->cart->items())->toBe([])
			->and(($this->storedCarts)())->toBe([]);

		$this->cart->add('mug', '', 1);
		$this->cart->add('mug', '', 1);

		$carts = array_values(($this->storedCarts)());
		expect($carts)->toHaveCount(1)
			->and($carts[0]['items'][0]['qty'])->toBe(2)
			->and($carts[0]['customerId'])->toBe('ann');
	});

	test("is never given another customer's cart", function (): void {
		$anns = ($this->annsCart)(2);
		$this->store->setCartId($anns);
		($this->signedIn)('bob');

		$this->cart->add('tee', 'large', 1);

		$carts = ($this->storedCarts)();
		expect($carts)->toHaveCount(2)
			->and($carts[$anns]['customerId'])->toBe('ann')
			->and($carts[$anns]['items'])->toBe([['productId' => 'mug', 'variantId' => '', 'qty' => 2]])
			->and($this->cart->items())->not->toHaveKey($this->key);
	});

	test('still has a cart when it cannot be stored', function (): void {
		($this->signedIn)();
		$this->c->get(CollectionRemover::class)->deleteCollection('carts');

		$this->cart->add('mug', '', 2);

		expect($this->cart->items()[$this->key]->qty)->toBe(2);
	});
});

test('a guest has no cart on the server', function (): void {
	$this->cart->add('mug', '', 2);
	$this->cart->update($this->key, 3);

	expect(($this->storedCarts)())->toBe([])
		->and($this->store->cartId())->toBeNull();
});

test("an admin signed in to the site's own users is a guest to the store", function (): void {
	($this->signedIn)('admin-user-test-com', 'auth');
	$this->cart->add('mug', '', 2);

	expect(($this->storedCarts)())->toBe([]);
});

describe('the cart repository', function (): void {
	test("finds a customer's active cart, and only that", function (): void {
		$anns = ($this->annsCart)(1);
		$this->repo->save(null, ['customerCollection' => 'members', 'customerId' => 'bob', 'status' => 'active', 'items' => []]);

		expect($this->repo->activeFor('members', 'ann')['id'])->toBe($anns)
			->and($this->repo->activeFor('members', 'carol'))->toBeNull()
			->and($this->repo->activeFor('auth', 'ann'))->toBeNull();
	});

	test('a cart at checkout, or turned into an order, is no longer the active one', function (): void {
		$cart = ($this->annsCart)(1);

		$this->repo->markCheckout($cart, 'order-1');
		$stored = ($this->storedCarts)()[$cart];
		expect($stored['status'])->toBe('checkout')
			->and($stored['orderId'])->toBe('order-1')
			->and($this->repo->activeFor('members', 'ann'))->toBeNull();

		$this->repo->markConverted($cart);
		expect(($this->storedCarts)()[$cart]['status'])->toBe('converted');
	});

	test('saving changes the fields given and keeps the rest', function (): void {
		$cart = ($this->annsCart)(1);

		$same = $this->repo->save($cart, ['items' => [['productId' => 'tee', 'variantId' => 'large', 'qty' => 2]]]);

		$stored = ($this->storedCarts)()[$cart];
		expect($same)->toBe($cart)
			->and($stored['items'])->toBe([['productId' => 'tee', 'variantId' => 'large', 'qty' => 2]])
			->and($stored['customerId'])->toBe('ann')
			->and($stored['attributes'])->toBe(['giftNote' => 'From before'])
			->and($stored['updated'])->not->toBe('');
	});
});
