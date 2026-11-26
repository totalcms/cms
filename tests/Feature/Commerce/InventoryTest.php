<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Inventory\InventoryService;
use TotalCMS\Domain\Commerce\Inventory\StockInventoryApplier;
use TotalCMS\Domain\Commerce\Order\InventoryApplier;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Service\ProductCatalog;
use TotalCMS\Domain\Commerce\Service\RefundService;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectRemover;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Support\Config;

/*
 * Stock. A product that counts its stock has that count taken down when an
 * order for it is paid, once. Selling more than there is does not undo the
 * sale: the money is already taken, so the order is flagged for a person.
 *
 * The poster counts stock and has 5. The shirt counts stock by variant: 3
 * large, 1 small. The mug does not count stock.
 */

beforeEach(function (): void {
	// The two stocked products are the same for every test here: the first
	// test in a worker makes them, the rest start from a copy.
	$stock        = bootCommerceScene('inventory');
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);

	$this->asStore = fn (Closure $work) => $this->c->get(ActorContext::class)->runAs(Actor::system(), $work);
	$this->product = fn (array $product) => ($this->asStore)(fn () => $this->c->get(ObjectSaver::class)->saveObject('products', $product + ['status' => 'active']));
	if ($stock) {
		($this->product)(['id' => 'poster', 'title' => 'Poster', 'price' => 20, 'trackInventory' => true, 'stock' => 5]);
		($this->product)(['id' => 'shirt', 'title' => 'Shirt', 'price' => 25, 'trackInventory' => true, 'stock' => 99, 'variants' => [
			'large' => ['id' => 'large', 'title' => 'Large', 'stock' => 3],
			'small' => ['id' => 'small', 'title' => 'Small', 'stock' => 1],
		]]);
		keepCommerceScene('inventory');
	}

	// What the product's own record says now, read from disk.
	$this->stored = fn (string $id): array => $this->c->get(ObjectFetcher::class)->fetchObjectFromDisk('products', $id)->toArray();
	$this->stock  = fn (string $id, string $variant = ''): int => (int)($variant === '' ? ($this->stored)($id)['stock'] : ($this->stored)($id)['variants'][$variant]['stock']);

	$this->pay   = fn (OrderData $order, string $eventId = 'evt_paid') => $this->c->get(PaymentEventProcessor::class)->process(commercePaidEvent($order->id(), $order->grandTotal(), $eventId));
	$this->order = function (array $items, bool $paid = true): OrderData {
		$order = $this->orders->create(commerceDraft($items), 'fake');
		$paid && ($this->pay)($order, 'evt_' . $order->number());

		return $this->orders->find($order->id());
	};
});

test('the store counts stock', function (): void {
	expect($this->c->get(InventoryApplier::class))->toBeInstanceOf(StockInventoryApplier::class);
});

describe('when an order is paid', function (): void {
	it('takes what was sold out of stock', function (): void {
		($this->order)(['poster' => 2]);

		expect(($this->stock)('poster'))->toBe(3);
	});

	it('takes nothing for an order nobody has paid for', function (): void {
		($this->order)(['poster' => 2], false);

		expect(($this->stock)('poster'))->toBe(5);
	});

	it('takes it once, however often the provider says the order was paid', function (): void {
		$order = ($this->order)(['poster' => 2]);
		($this->pay)($order, 'evt_again');
		($this->pay)($order, 'evt_' . $order->number());

		expect(($this->stock)('poster'))->toBe(3);
	});

	it('counts a variant\'s own stock, and leaves the product\'s and the other variants\' alone', function (): void {
		($this->order)(['shirt:large' => 2]);

		expect(($this->stock)('shirt', 'large'))->toBe(1)
			->and(($this->stock)('shirt', 'small'))->toBe(1)
			->and(($this->stock)('shirt'))->toBe(99);
	});

	it('leaves a product that does not count stock alone', function (): void {
		$before = ($this->stored)('mug');
		($this->order)(['mug' => 3]);

		expect(($this->stored)('mug')['stock'] ?? null)->toBe($before['stock'] ?? null);
	});

	it('changes nothing else about the product', function (): void {
		$before = ($this->stored)('poster');
		($this->order)(['poster' => 1]);
		$after = ($this->stored)('poster');

		unset($before['stock'], $after['stock'], $before['updated'], $after['updated']);
		expect($after)->toBe($before);
	});

	it('shows the new count to the storefront at once', function (): void {
		($this->order)(['poster' => 5]);

		expect($this->c->get(ProductCatalog::class)->product('poster')?->stock)->toBe(0)
			->and(renderTwig('{{ cms.commerce.inStock("poster") ? "yes" : "no" }}'))->toBe('no');
	});

	it('counts every order', function (): void {
		($this->order)(['poster' => 2]);
		($this->order)(['poster' => 1]);

		expect(($this->stock)('poster'))->toBe(2);
	});
});

/*
 * Two buyers can both check out the last one: the check at the cart is a
 * courtesy, and the count is only taken down when each pays.
 */
describe('selling more than there is', function (): void {
	it('stops the count at nothing, keeps the order paid, and flags it for a person', function (): void {
		$first  = $this->orders->create(commerceDraft(['poster' => 4]), 'fake');
		$second = $this->orders->create(commerceDraft(['poster' => 3]), 'fake');
		($this->pay)($first, 'evt_1');
		($this->pay)($second, 'evt_2');

		expect(($this->stock)('poster'))->toBe(0)
			->and($this->orders->find($first->id())->needsAttention())->toBe('')
			->and($this->orders->find($second->id())->paymentStatus())->toBe('paid')
			->and($this->orders->find($second->id())->needsAttention())->toBe('oversold');
	});

	it('lets the count go below nothing for a product that takes back-orders, and flags nothing', function (): void {
		($this->product)(['id' => 'canvas', 'title' => 'Canvas', 'price' => 40, 'trackInventory' => true, 'stock' => 1, 'allowBackorder' => true]);
		$order = ($this->order)(['canvas' => 3]);

		expect(($this->stock)('canvas'))->toBe(-2)
			->and($order->needsAttention())->toBe('');
	});

	it('does not hide another reason the order already needs someone', function (): void {
		$order = $this->orders->create(commerceDraft(['poster' => 9]), 'fake');
		// Paid with the wrong amount: held for review, nothing is taken from stock.
		$this->c->get(PaymentEventProcessor::class)->process(commercePaidEvent($order->id(), 1, 'evt_wrong'));

		expect($this->orders->find($order->id())->needsAttention())->toBe('amount_mismatch')
			->and(($this->stock)('poster'))->toBe(5);
	});

	it('takes nothing for a product that has since been deleted, and does not fail the payment', function (): void {
		$order = $this->orders->create(commerceDraft(['poster' => 1]), 'fake');
		($this->asStore)(fn () => $this->c->get(ObjectRemover::class)->deleteObject('products', 'poster'));

		($this->pay)($order);

		expect($this->orders->find($order->id())->paymentStatus())->toBe('paid');
	});
});

describe('the inventory service', function (): void {
	beforeEach(fn () => $this->inventory = $this->c->get(InventoryService::class));

	it('says what it could not supply', function (): void {
		$result = $this->inventory->decrement('poster', '', 7);

		expect($result->tracked)->toBeTrue()
			->and($result->short)->toBe(2)
			->and($result->stock)->toBe(0);
	});

	it('says a product is not tracked, and changes nothing', function (): void {
		expect($this->inventory->decrement('mug', '', 7)->tracked)->toBeFalse()
			->and($this->inventory->decrement('nope', '', 1)->tracked)->toBeFalse();
	});

	it('puts stock back', function (): void {
		$this->inventory->decrement('poster', '', 2);
		$this->inventory->increment('poster', '', 1);
		$this->inventory->increment('shirt', 'small', 4);

		expect(($this->stock)('poster'))->toBe(4)
			->and(($this->stock)('shirt', 'small'))->toBe(5);
	});

	it('ignores a quantity that is not one', function (int $qty): void {
		$this->inventory->decrement('poster', '', $qty);
		$this->inventory->increment('poster', '', $qty);

		expect(($this->stock)('poster'))->toBe(5);
	})->with([0, -3]);
});

/*
 * A refund does not put things back by itself: the goods may never come
 * back. Staff say so when they ask for the refund, and the stock returns
 * when the provider confirms it.
 */
describe('when a refund is confirmed', function (): void {
	beforeEach(function (): void {
		seedCommerceStaff($this->app);
		$this->placed  = ($this->order)(['poster' => 2, 'shirt:small' => 1]);
		$this->boss    = new Actor(ActorKind::Admin, 'boss', 'auth');
		$this->ask     = fn (int $amount, bool $restock) => $this->c->get(RefundService::class)->request($this->placed->id(), $amount, 'Returned', $restock, $this->boss);
		$this->confirm = fn (int $amount, string $refundRef, string $eventId) => $this->c->get(PaymentEventProcessor::class)->process(
			new PaymentEvent('fake', $eventId, PaymentEventType::Refunded, $this->placed->id(), Money::of($amount, 'USD'), $refundRef, $this->placed->providerPaymentRef()),
		);
	});

	it('puts the items back when staff asked for that', function (): void {
		$result = ($this->ask)($this->placed->grandTotal(), true);
		expect(($this->stock)('poster'))->toBe(3);

		($this->confirm)($this->placed->grandTotal(), $result->providerRefundRef, 'evt_refund');

		expect(($this->stock)('poster'))->toBe(5)
			->and(($this->stock)('shirt', 'small'))->toBe(1);
	});

	it('puts nothing back until the provider confirms the refund', function (): void {
		($this->ask)($this->placed->grandTotal(), true);

		expect(($this->stock)('poster'))->toBe(3);
	});

	it('puts nothing back when staff did not ask', function (): void {
		$result = ($this->ask)($this->placed->grandTotal(), false);
		($this->confirm)($this->placed->grandTotal(), $result->providerRefundRef, 'evt_refund');

		expect(($this->stock)('poster'))->toBe(3);
	});

	it('puts nothing back for a refund made at the provider, which nobody asked the store for', function (): void {
		($this->confirm)(500, 're_made_at_the_provider', 'evt_refund');

		expect(($this->stock)('poster'))->toBe(3);
	});

	it('puts them back once, whatever is confirmed after', function (): void {
		$first = ($this->ask)(1000, true);
		($this->confirm)(1000, $first->providerRefundRef, 'evt_refund_1');
		($this->confirm)(1000, $first->providerRefundRef, 'evt_refund_1');
		$second = ($this->ask)(500, true);
		($this->confirm)(500, $second->providerRefundRef, 'evt_refund_2');

		expect(($this->stock)('poster'))->toBe(5)
			->and(($this->stock)('shirt', 'small'))->toBe(1);
	});

	it('still finds the request when the provider names the refund another way', function (): void {
		($this->ask)(1000, true);
		// An older event format names the charge, not the refund: the amount finds the request.
		($this->confirm)(1000, 'ch_the_charge', 'evt_refund');

		expect(($this->stock)('poster'))->toBe(5);
	});
});

/*
 * The store tells the site about stock it changed, with events of its own:
 * `stock.changed` each time, and `stock.low` or `stock.out` once, as a
 * count comes down past the line. A sale does not fire `object.updated` on
 * the product; these are what an automation listens for instead.
 */
describe('telling the site', function (): void {
	beforeEach(function (): void {
		$this->heard = [];
		foreach ([CoreEvent::STOCK_CHANGED, CoreEvent::STOCK_LOW, CoreEvent::STOCK_OUT, CoreEvent::OBJECT_UPDATED] as $event) {
			$this->c->get(EventDispatcher::class)->listen($event, function (array $payload) use ($event): void {
				// An order being paid updates the order and its ledger. Only what is said of a product counts here.
				if (($payload['collection'] ?? '') === 'products') {
					$this->heard[] = [$event, $payload];
				}
			});
		}
		$this->names = fn (): array => array_column($this->heard, 0);
		$this->of    = fn (string $event): array => array_values(array_column(array_filter($this->heard, static fn (array $h): bool => $h[0] === $event), 1));
	});

	it('says what a sale took: the product, the count before and after, and the order', function (): void {
		($this->product)(['id' => 'lamp', 'title' => 'Lamp', 'sku' => 'LMP-1', 'price' => 30, 'trackInventory' => true, 'stock' => 20]);
		$order = ($this->order)(['lamp' => 2]);

		expect(($this->names)())->toBe([CoreEvent::STOCK_CHANGED])
			->and(array_diff_key(($this->of)(CoreEvent::STOCK_CHANGED)[0], ['actor' => 1]))->toBe([
				'collection' => 'products',
				'productId'  => 'lamp',
				'variantId'  => '',
				'title'      => 'Lamp',
				'sku'        => 'LMP-1',
				'before'     => 20,
				'after'      => 18,
				'change'     => -2,
				'reason'     => 'sale',
				'orderId'    => $order->id(),
				'lowStockAt' => 5,
			]);
	});

	it('names the variant whose count it was', function (): void {
		($this->order)(['shirt:large' => 1]);
		$told = ($this->of)(CoreEvent::STOCK_CHANGED)[0];

		expect([$told['productId'], $told['variantId'], $told['before'], $told['after']])->toBe(['shirt', 'large', 3, 2]);
	});

	it('says it once, however often the provider says the order was paid', function (): void {
		$order = ($this->order)(['poster' => 1], false);
		($this->pay)($order, 'evt_a');
		($this->pay)($order, 'evt_b');

		expect(($this->of)(CoreEvent::STOCK_CHANGED))->toHaveCount(1);
	});

	it('says nothing for a product that does not count stock, or a count that did not move', function (): void {
		($this->product)(['id' => 'gone', 'title' => 'Gone', 'price' => 5, 'trackInventory' => true, 'stock' => 0]);
		($this->order)(['mug' => 1, 'gone' => 1]);

		expect(($this->names)())->toBe([]);
	});

	it('says a count is low once, as it comes down to the store\'s Low Stock At', function (): void {
		($this->product)(['id' => 'lamp', 'title' => 'Lamp', 'price' => 30, 'trackInventory' => true, 'stock' => 8]);
		($this->order)(['lamp' => 2]);
		expect(($this->of)(CoreEvent::STOCK_LOW))->toBe([]);

		($this->order)(['lamp' => 1]);
		($this->order)(['lamp' => 1]);

		$low = ($this->of)(CoreEvent::STOCK_LOW);
		expect($low)->toHaveCount(1)
			->and([$low[0]['productId'], $low[0]['before'], $low[0]['after'], $low[0]['lowStockAt']])->toBe(['lamp', 6, 5, 5]);
	});

	it('goes by the setting, and never says low when it is nothing', function (int $at, int $times): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['lowStockAt' => $at] + $config->commerce;
		($this->product)(['id' => 'lamp', 'title' => 'Lamp', 'price' => 30, 'trackInventory' => true, 'stock' => 12]);
		($this->order)(['lamp' => 2]);

		expect(($this->of)(CoreEvent::STOCK_LOW))->toHaveCount($times);
	})->with([
		'low at 10' => [10, 1],
		'low at 5'  => [5, 0],
		'never low' => [0, 0],
	]);

	it('says a count has run out, and not that it is low as well', function (): void {
		($this->order)(['shirt:small' => 1]);

		expect(($this->names)())->toBe([CoreEvent::STOCK_CHANGED, CoreEvent::STOCK_OUT])
			->and(($this->of)(CoreEvent::STOCK_OUT)[0]['variantId'])->toBe('small')
			->and(($this->of)(CoreEvent::STOCK_OUT)[0]['after'])->toBe(0);
	});

	it('says a back-ordered product ran out once, as it passes nothing', function (): void {
		($this->product)(['id' => 'vase', 'title' => 'Vase', 'price' => 30, 'trackInventory' => true, 'stock' => 1, 'allowBackorder' => true]);
		($this->order)(['vase' => 2]);
		($this->order)(['vase' => 1]);

		expect(($this->of)(CoreEvent::STOCK_OUT))->toHaveCount(1)
			->and(($this->of)(CoreEvent::STOCK_CHANGED))->toHaveCount(2)
			->and(($this->of)(CoreEvent::STOCK_CHANGED)[1]['after'])->toBe(-2);
	});

	it('says what a refund put back, and nothing about low or out', function (): void {
		seedCommerceStaff($this->app);
		$order       = ($this->order)(['shirt:small' => 1]);
		$result      = $this->c->get(RefundService::class)->request($order->id(), $order->grandTotal(), 'Returned', true, new Actor(ActorKind::Admin, 'boss', 'auth'));
		$this->heard = [];

		$this->c->get(PaymentEventProcessor::class)->process(
			new PaymentEvent('fake', 'evt_refund', PaymentEventType::Refunded, $order->id(), Money::of($order->grandTotal(), 'USD'), $result->providerRefundRef, $order->providerPaymentRef()),
		);

		$told = ($this->of)(CoreEvent::STOCK_CHANGED);
		expect(array_values(array_intersect(($this->names)(), [CoreEvent::STOCK_CHANGED, CoreEvent::STOCK_LOW, CoreEvent::STOCK_OUT])))->toBe([CoreEvent::STOCK_CHANGED])
			->and([$told[0]['before'], $told[0]['after'], $told[0]['change'], $told[0]['reason'], $told[0]['orderId']])->toBe([0, 1, 1, 'restock', $order->id()]);
	});

	it('does not tell the site the product was edited', function (): void {
		($this->order)(['poster' => 1]);

		expect(($this->names)())->not->toContain(CoreEvent::OBJECT_UPDATED);
	});
});
