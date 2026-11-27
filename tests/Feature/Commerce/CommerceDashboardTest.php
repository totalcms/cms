<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Commerce\Service\CommerceDashboard;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Session\SessionKeys;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;

/*
 * The store on the admin's home page: what it took in the last 30 days,
 * its newest orders, what is running out, and how many orders need someone.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->boot = function (bool $open = true): void {
		// An open store with its staff is the same every time: the first test
		// in a worker makes it, the rest start from a copy.
		if (!$open) {
			bootCommerceStore(false);
			seedCommerceStaff($this->app);
		} elseif (bootCommerceScene('dashboard')) {
			seedCommerceStaff($this->app);
			keepCommerceScene('dashboard');
		}
		$this->c      = $this->app->getContainer();
		$this->orders = $this->c->get(OrderRepository::class);
		$this->store  = $this->c->get(CommerceDashboard::class);
	};
	($this->boot)();

	/** An order of $items, paid $ago unless $paid is false. */
	$this->order = function (array $items, bool $paid = true, string $ago = '-1 hour', ?int $amount = null): OrderData {
		$order = $this->orders->create(commerceDraft($items), 'fake');
		if ($paid) {
			$this->c->get(PaymentEventProcessor::class)->process(commercePaidEvent($order->id(), $amount ?? $order->grandTotal(), 'evt_' . $order->number()));
			$this->orders->withLock($order->id(), static fn (OrderData $fresh): OrderData => $fresh->with(array_filter(['paidAt' => $fresh->paidAt() !== '' ? date(DATE_ATOM, strtotime($ago)) : null])));
		}

		return $this->orders->find($order->id());
	};
	$this->product = function (array $product): void {
		$this->c->get(ActorContext::class)->set(Actor::system());
		$this->c->get(ObjectSaver::class)->saveObject('products', $product + ['status' => 'active', 'price' => 10]);
		$this->c->get(ActorContext::class)->set(Actor::anonymous());
	};
});

describe('what the store took', function (): void {
	it('adds up what was paid in the last 30 days, less what went back', function (): void {
		($this->order)(['mug' => 2]);                          // $25.00
		$part = ($this->order)(['tee:large' => 2]);            // $39.98, $10.00 of it refunded
		$this->c->get(PaymentEventProcessor::class)->process(commercePaymentEvent(PaymentEventType::Refunded, $part->id(), 1000, 'evt_refund'));
		$gone = ($this->order)(['mug' => 1]);                  // $12.50, all of it refunded
		$this->c->get(PaymentEventProcessor::class)->process(commercePaymentEvent(PaymentEventType::Refunded, $gone->id(), 1250, 'evt_refund_all'));

		expect($this->store->revenue())->toBe(['amount' => 2500 + 2998, 'currency' => 'USD', 'orders' => 2]);
	});

	it('leaves out what was not paid, what is in review and what is older than the window', function (): void {
		($this->order)(['mug' => 1]);
		($this->order)(['mug' => 4], paid: false);
		($this->order)(['mug' => 4], amount: 1);
		($this->order)(['mug' => 4], ago: '-45 days');

		expect($this->store->revenue())->toBe(['amount' => 1250, 'currency' => 'USD', 'orders' => 1])
			->and($this->store->revenue(60)['amount'])->toBe(1250 + 5000)
			->and($this->store->revenue(60)['orders'])->toBe(2);
	});

	it('is nothing on a store with no orders', function (): void {
		expect($this->store->revenue())->toBe(['amount' => 0, 'currency' => 'USD', 'orders' => 0]);
	});
});

it('lists the newest orders first', function (): void {
	($this->order)(['mug' => 1]);
	($this->order)(['mug' => 2], paid: false);
	($this->order)(['mug' => 3]);

	expect(array_map(static fn (OrderData $order): int => $order->number(), $this->store->recentOrders(2)))->toBe([1003, 1002]);
});

describe('what is running out', function (): void {
	it('lists tracked products and variants at or under the threshold, lowest first', function (): void {
		($this->product)(['id' => 'ink', 'title' => 'Ink', 'trackInventory' => true, 'stock' => 5]);
		($this->product)(['id' => 'nib', 'title' => 'Nib', 'trackInventory' => true, 'stock' => 6]);
		($this->product)(['id' => 'cap', 'title' => 'Cap', 'trackInventory' => false, 'stock' => 0]);
		($this->product)(['id' => 'old', 'title' => 'Old', 'trackInventory' => true, 'stock' => 0, 'status' => 'archived']);
		($this->product)(['id' => 'pad', 'title' => 'Pad', 'trackInventory' => true, 'stock' => 99, 'variants' => [
			'a5' => ['id' => 'a5', 'title' => 'A5', 'stock' => 2],
			'a4' => ['id' => 'a4', 'title' => 'A4', 'stock' => 40],
		]]);

		// `print` is in every test store: tracked, with none left.
		expect($this->store->lowStock())->toBe([
			['productId' => 'print', 'variantId' => '', 'title' => 'Print', 'stock' => 0],
			['productId' => 'pad', 'variantId' => 'a5', 'title' => 'Pad — A5', 'stock' => 2],
			['productId' => 'ink', 'variantId' => '', 'title' => 'Ink', 'stock' => 5],
		])->and(array_column($this->store->lowStock(1), 'productId'))->toBe(['print']);
	});

	it('goes by the store\'s Low Stock At when no line is given', function (): void {
		($this->product)(['id' => 'ink', 'title' => 'Ink', 'trackInventory' => true, 'stock' => 5]);
		$config           = $this->app->getContainer()->get(Config::class);
		$config->commerce = ['lowStockAt' => 2] + $config->commerce;

		expect(array_column($this->store->lowStock(), 'productId'))->toBe(['print']);
	});
});

it('counts the orders that need someone', function (): void {
	($this->order)(['mug' => 1]);
	($this->order)(['mug' => 1], amount: 1);

	expect($this->store->attention())->toBe(1);
});

describe('on the admin home page', function (): void {
	it('is shown to those who may see orders, and to nobody else', function (string $user, bool $shown): void {
		($this->order)(['mug' => 2]);
		signInAs($this->app, $user, 'auth');
		$html = (string)get('/admin')->getBody();

		expect(str_contains($html, 'id="commerce-dashboard"'))->toBe($shown);
		if ($shown) {
			expect($html)->toContain('$25.00', '#1001', 'buyer@example.com', 'Print', 'commerce/orders');
		} else {
			expect($html)->not->toContain('buyer@example.com');
		}
	})->with([['boss', true], ['keeper', true], ['writer', false]]);

	it('points at the orders that need someone', function (): void {
		($this->order)(['mug' => 1], amount: 1);
		signInAs($this->app, 'boss', 'auth');

		expect((string)get('/admin')->getBody())->toContain('commerce/orders?needsAttention=1');
	});

	it('answers nothing in a template a visitor or a member without the grant is shown', function (string $user): void {
		($this->order)(['mug' => 2]);
		signInAs($this->app, 'boss', 'auth');
		$boss = renderTwig('{{ cms.commerce.dashboard() is null ? "nothing" : "numbers" }}');
		// The same site, with whoever this is signed in, or nobody.
		$this->c->get(PhpSession::class)->set(SessionKeys::AUTH_USER, $user);

		expect($boss)->toBe('numbers')
			->and(renderTwig('{{ cms.commerce.dashboard() is null ? "nothing" : "numbers" }}'))->toBe('nothing');
	})->with(['a visitor' => [''], 'a member without the grant' => ['writer']]);

	it('is not there on a site without Commerce', function (): void {
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_destroy();
		}
		($this->boot)(false);
		signInAs($this->app, 'boss', 'auth');

		expect((string)get('/admin')->getBody())->not->toContain('id="commerce-dashboard"');
	});

	it('warns when paid downloads are open to the public', function (): void {
		commerceDownloadIs($this->c, 'file');
		$this->c->get(CollectionSaver::class)->updateCollection('downloads', ['id' => 'downloads', 'name' => 'Downloads', 'schema' => 'download-is-file', 'groups' => []]);
		signInAs($this->app, 'boss', 'auth');
		$html = (string)get('/admin')->getBody();

		expect($html)->toContain('Paid downloads are open to the public', 'Product files in the Downloads collection', 'href="collections/downloads/edit"', 'Open collection settings');
	});

	it('says nothing when downloads are protected', function (): void {
		signInAs($this->app, 'boss', 'auth');

		expect((string)get('/admin')->getBody())->not->toContain('Paid downloads are open to the public');
	});

	it('links running-low products into the configured products collection', function (): void {
		commerceDownloadIs($this->c, 'file');
		$config           = $this->c->get(Config::class);
		$config->commerce = ['lowStockAt' => 5] + $config->commerce;
		$this->c->get(CollectionSaver::class)->updateCollection('downloads', ['id' => 'downloads', 'name' => 'Downloads', 'schema' => 'download-is-file', 'groups' => ['admin']]);
		$this->c->get(ActorContext::class)->set(Actor::system());
		$this->c->get(ObjectSaver::class)->saveObject('downloads', ['id' => 'scarce', 'title' => 'Scarce', 'status' => 'active', 'price' => 10, 'trackInventory' => true, 'stock' => 1]);
		$this->c->get(ActorContext::class)->set(Actor::anonymous());
		signInAs($this->app, 'boss', 'auth');
		$html = (string)get('/admin')->getBody();

		expect($html)->toContain('collections/downloads/scarce')->not->toContain('collections/products/scarce');
	});
});
