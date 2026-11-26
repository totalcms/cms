<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Exception\AccessDeniedException;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\OrderAccess;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Object\Service\ObjectSaver;

/*
 * An order is written once, by the store, from a draft the server built.
 * After that it changes only inside a lock on that order, so two things
 * happening to it at once (a webhook and an admin, two webhooks) cannot
 * each undo the other.
 */

beforeEach(function (): void {
	bootCommerceStore();
	$this->c      = $this->app->getContainer();
	$this->orders = $this->c->get(OrderRepository::class);
	// The visitor is nobody in particular; the repository writes as the store.
	$this->c->get(ActorContext::class)->set(Actor::anonymous());
});

describe('creating an order', function (): void {
	it('writes the draft as a pending order that can be read back', function (): void {
		$draft = commerceDraft(['tee:large' => 2, 'mug' => 1], ['shippingAddress' => ['name' => 'Ada', 'country' => 'US']]);
		$order = $this->orders->create($draft, 'fake');
		$found = $this->orders->find($order->id());

		expect($found)->toBeInstanceOf(OrderData::class)
			->and($found->id())->toMatch('/^[0-9a-f]{32}$/')
			->and($found->kind())->toBe('checkout')
			->and($found->isRenewal())->toBeFalse()
			->and([$found->status(), $found->paymentStatus(), $found->fulfillmentStatus()])->toBe(['open', 'pending', 'unfulfilled'])
			->and($found->email())->toBe('buyer@example.com')
			->and($found->currency())->toBe('USD')
			->and($found->provider())->toBe('fake')
			->and($found->subtotal())->toBe(3998 + 1250)
			->and($found->grandTotal())->toBe(5248)
			->and($found->grandTotalMoney()->amount())->toBe(5248)
			->and($found->grandTotalMoney()->currency())->toBe('USD')
			->and($found->refundedTotal())->toBe(0)
			->and($found->taxMode())->toBe('none')
			->and($found->shippingAddress()['country'] ?? null)->toBe('US')
			->and($found->accessToken())->toMatch('/^[0-9a-f]{64}$/')
			->and($found->created())->not->toBe('')
			->and($found->flags())->toBe([])
			->and($this->orders->exists($order->id()))->toBeTrue()
			->and($this->orders->exists('nothing'))->toBeFalse()
			->and($this->orders->find('nothing'))->toBeNull();
	});

	it('snapshots each line under its own id', function (): void {
		$order = $this->orders->create(commerceDraft(['tee:large' => 2, 'mug' => 1]), 'fake');
		$lines = $this->orders->find($order->id())->lines();

		expect(array_keys($lines))->toBe(['l1', 'l2'])
			->and($lines['l1'])->toMatchArray(['id' => 'l1', 'productId' => 'tee', 'variantId' => 'large', 'qty' => 2, 'unitPrice' => 1999, 'lineTotal' => 3998, 'priceSource' => 'catalog', 'fulfillmentStatus' => 'unfulfilled', 'refundedQty' => 0])
			->and($lines['l2'])->toMatchArray(['id' => 'l2', 'productId' => 'mug', 'qty' => 1, 'lineTotal' => 1250]);
	});

	it('numbers orders from the store\'s first number, one after another', function (): void {
		$first  = $this->orders->create(commerceDraft(), 'fake');
		$second = $this->orders->create(commerceDraft(), 'fake');

		expect($first->number())->toBe(1001)
			->and($second->number())->toBe(1002)
			->and($second->id())->not->toBe($first->id())
			->and($second->accessToken())->not->toBe($first->accessToken());
	});

	it('is the store\'s write alone: the same record from anyone else is refused', function (): void {
		expect(fn () => $this->c->get(ObjectSaver::class)->saveObject('orders', ['id' => 'forged', 'number' => 9, 'grandTotal' => 1, 'paymentStatus' => 'paid']))
			->toThrow(AccessDeniedException::class)
			->and($this->orders->exists('forged'))->toBeFalse();
	});
});

describe('changing an order', function (): void {
	beforeEach(function (): void {
		$this->order = $this->orders->create(commerceDraft(['tee:large' => 2]), 'fake');
	});

	it('saves what the callback returns', function (): void {
		$returned = $this->orders->withLock($this->order->id(), fn (OrderData $o): OrderData => $o->withStatuses('paid', 'unfulfilled', 'open')->withFlag('receiptQueued')->with(['providerPaymentRef' => 'pi_1', 'paidAt' => '2026-11-22T10:00:00+00:00']));
		$found    = $this->orders->find($this->order->id());

		expect($found->paymentStatus())->toBe('paid')
			->and($found->hasFlag('receiptQueued'))->toBeTrue()
			->and($found->hasFlag('inventoryApplied'))->toBeFalse()
			->and($found->providerPaymentRef())->toBe('pi_1')
			->and($found->paidAt())->not->toBe('')
			->and($returned->paymentStatus())->toBe('paid')
			->and($found->grandTotal())->toBe(3998)
			->and($found->number())->toBe($this->order->number());
	});

	it('changes one line and leaves the rest of it as it was', function (): void {
		$this->orders->withLock($this->order->id(), fn (OrderData $o): OrderData => $o->withLine('l1', ['fulfillmentStatus' => 'fulfilled', 'fulfillmentNote' => 'Posted']));
		$line = $this->orders->find($this->order->id())->line('l1');

		expect($line)->toMatchArray(['fulfillmentStatus' => 'fulfilled', 'fulfillmentNote' => 'Posted', 'unitPrice' => 1999, 'qty' => 2, 'productId' => 'tee']);
	});

	it('holds the order\'s lock while the callback runs', function (): void {
		$lockFile = cmsDataDir() . 'orders/' . $this->order->id() . '.lock';
		$held     = null;

		$this->orders->withLock($this->order->id(), function (OrderData $o) use ($lockFile, &$held): OrderData {
			$other = fopen($lockFile, 'c');
			$held  = !flock($other, LOCK_EX | LOCK_NB);
			fclose($other);

			return $o;
		});
		$other = fopen($lockFile, 'c');

		expect($held)->toBeTrue()
			->and(flock($other, LOCK_EX | LOCK_NB))->toBeTrue();
		fclose($other);
	});

	it('gives the callback the order as it is on disk now, not as this request first read it', function (): void {
		$stale = $this->orders->find($this->order->id());

		// Another process pays the order while this one is still holding its old copy.
		$file          = cmsDataDir() . 'orders/' . $this->order->id() . '.json';
		$onDisk        = json_decode((string)file_get_contents($file), true);
		$onDisk['paymentStatus'] = 'paid';
		file_put_contents($file, json_encode($onDisk));

		$this->orders->withLock($this->order->id(), fn (OrderData $o): OrderData => $o->withFlag('adminNote'));
		$found = $this->orders->find($this->order->id());

		expect($stale->paymentStatus())->toBe('pending')
			->and($found->paymentStatus())->toBe('paid')
			->and($found->hasFlag('adminNote'))->toBeTrue();
	});

	it('refuses an order that does not exist', function (): void {
		expect(fn () => $this->orders->withLock('nothing', fn (OrderData $o): OrderData => $o))
			->toThrow(fn (CommerceException $e) => expect($e->errorCode)->toBe('order_not_found')->and($e->status)->toBe(404));
	});
});

describe('finding orders', function (): void {
	it('finds the pending orders a cart started', function (): void {
		$mine  = $this->orders->create(commerceDraft()->withMetadata(['cartKey' => 'cart-a'], []), 'fake');
		$other = $this->orders->create(commerceDraft()->withMetadata(['cartKey' => 'cart-b'], []), 'fake');
		$paid  = $this->orders->create(commerceDraft()->withMetadata(['cartKey' => 'cart-a'], []), 'fake');
		$this->orders->withLock($paid->id(), fn (OrderData $o): OrderData => $o->withStatuses('paid', 'unfulfilled', 'open'));

		$found = array_map(fn (OrderData $o): string => $o->id(), $this->orders->pendingForCart('cart-a'));

		expect($found)->toBe([$mine->id()])
			->and($mine->cartKey())->toBe('cart-a')
			->and($this->orders->pendingForCart(''))->toBe([])
			->and($other->id())->not->toBe($mine->id());
	});

	it('finds the order a payment belongs to, for that provider only', function (): void {
		$order = $this->orders->create(commerceDraft(), 'fake');
		$this->orders->withLock($order->id(), fn (OrderData $o): OrderData => $o->with(['providerPaymentRef' => 'pi_1']));

		expect($this->orders->findByPaymentRef('fake', 'pi_1')?->id())->toBe($order->id())
			->and($this->orders->findByPaymentRef('stripe', 'pi_1'))->toBeNull()
			->and($this->orders->findByPaymentRef('fake', 'pi_unknown'))->toBeNull()
			->and($this->orders->findByPaymentRef('fake', ''))->toBeNull();
	});
});

describe('a link to an order, for a buyer without an account', function (): void {
	beforeEach(function (): void {
		$this->access = $this->c->get(OrderAccess::class);
		$this->order  = $this->orders->create(commerceDraft(), 'fake');
	});

	it('opens that order', function (): void {
		expect($this->access->verify($this->order->id(), $this->access->token($this->order))?->id())->toBe($this->order->id());
	});

	it('does not open another order', function (): void {
		$other = $this->orders->create(commerceDraft(), 'fake');

		expect($this->access->verify($other->id(), $this->access->token($this->order)))->toBeNull();
	});

	it('stops working when it expires', function (): void {
		expect($this->access->verify($this->order->id(), $this->access->token($this->order, -1)))->toBeNull();
	});

	it('stops working when the order\'s secret changes', function (): void {
		$token = $this->access->token($this->order);
		$this->orders->withLock($this->order->id(), fn (OrderData $o): OrderData => $o->with(['accessToken' => bin2hex(random_bytes(32))]));

		expect($this->access->verify($this->order->id(), $token))->toBeNull();
	});

	it('is not opened by anything that is not a link it made', function (string $token): void {
		expect($this->access->verify($this->order->id(), $token))->toBeNull();
	})->with(['', 'not-a-token', 'a.b.c']);

	it('gives a provider a view of the order without its secret', function (): void {
		$view = $this->order->view();

		expect($view->id)->toBe($this->order->id())
			->and($view->number)->toBe(1001)
			->and($view->grandTotal->amount())->toBe(1250)
			->and($view->provider)->toBe('fake')
			->and(get_object_vars($view))->not->toHaveKey('accessToken');
	});
});
