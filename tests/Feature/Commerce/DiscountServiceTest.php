<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Discount\DiscountService;
use TotalCMS\Domain\Commerce\Exception\CommerceException;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Money\Money;

/*
 * Checking a code a buyer typed. A code that cannot be used is refused the
 * same way whatever the reason, so the answer never says which codes exist.
 */

beforeEach(function (): void {
	bootCommerceStore();
	$this->c       = $this->app->getContainer();
	$this->service = $this->c->get(DiscountService::class);
	$this->lines   = commerceDraft(['tee:large' => 1, 'mug' => 1])->lines; // 1999 + 1250
	$this->now     = strtotime('2026-12-01T12:00:00Z');
	$this->resolve = fn (string $code, string $email = 'a@example.com', ?array $lines = null, int $subtotal = 3249) => $this->service->resolve($code, $lines ?? $this->lines, Money::of($subtotal, 'USD'), $email, $this->now);
});

it('works out a valid code against the cart', function (): void {
	seedDiscount('fall10', ['value' => 10]);
	$applied = ($this->resolve)(' Fall10 ');

	expect($applied->code)->toBe('FALL10')
		->and($applied->amount)->toBe(325)
		->and(array_sum($applied->lineAmounts))->toBe(325)
		->and(array_keys($applied->lineAmounts))->toBe(['l1', 'l2']);
});

it('answers every missing-code case with one error', function (array $fields): void {
	seedDiscount('x-code', $fields);
	if (isset($fields['perCustomerLimit'])) {
		$orders = $this->c->get(OrderRepository::class);
		$order  = $orders->create(commerceDraft(body: ['email' => 'a@example.com']), 'fake');
		$orders->withLock($order->id(), fn (OrderData $o): OrderData => $o->with(['discountCode' => 'X-CODE', 'paymentStatus' => 'paid']));
	}

	expect(fn () => ($this->resolve)('x-code'))
		->toThrow(function (CommerceException $e): void {
			expect($e->errorCode)->toBe('discount_invalid')->and($e->status)->toBe(422)->and($e->getMessage())->toBe('That code is not valid.');
		});
})->with([
	'switched off'       => [['active' => false]],
	'not started'        => [['startsAt' => '2027-01-01T00:00:00Z']],
	'ended'              => [['endsAt' => '2026-11-30T00:00:00Z']],
	'used up'            => [['usageLimit' => 5, 'usedCount' => 5]],
	'used by this buyer' => [['perCustomerLimit' => 1]],
]);

it('gives the same error for a code that does not exist', function (string $code): void {
	expect(fn () => ($this->resolve)($code))
		->toThrow(fn (CommerceException $e) => expect([$e->errorCode, $e->getMessage()])->toBe(['discount_invalid', 'That code is not valid.']));
})->with(['nope', 'a b', '', '../../etc/passwd']);

it('takes a code that is inside its dates and under its limits', function (): void {
	seedDiscount('okay', ['startsAt' => '2026-11-01T00:00:00Z', 'endsAt' => '2026-12-31T00:00:00Z', 'usageLimit' => 5, 'usedCount' => 4, 'perCustomerLimit' => 2]);

	expect(($this->resolve)('okay')->amount)->toBeGreaterThan(0);
});

it('names the minimum when the subtotal is short', function (): void {
	seedDiscount('big', ['minSubtotal' => 50]);

	expect(fn () => ($this->resolve)('big'))
		->toThrow(function (CommerceException $e): void {
			expect($e->errorCode)->toBe('discount_minimum')->and($e->status)->toBe(422)->and($e->getMessage())->toBe('This code needs a subtotal of $50.00.');
		})
		->and(($this->resolve)('big', subtotal: 5000)->amount)->toBeGreaterThan(0);
});

it('says so when the code fits nothing in the cart', function (array $fields): void {
	seedDiscount('hats', $fields);

	expect(fn () => ($this->resolve)('hats'))
		->toThrow(function (CommerceException $e): void {
			expect($e->errorCode)->toBe('discount_no_items')->and($e->getMessage())->toBe('This code does not apply to anything in your cart.');
		});
})->with([
	'a product not in the cart'     => [['products' => ['hat']]],
	'free shipping on another item' => [['type' => 'free_shipping', 'products' => ['hat']]],
	'a code worth nothing'          => [['type' => 'percent', 'value' => 0]],
]);

it('skips the per-customer check when no email is known', function (): void {
	seedDiscount('once-each', ['perCustomerLimit' => 1]);
	$orders = $this->c->get(OrderRepository::class);
	$order  = $orders->create(commerceDraft(body: ['email' => 'a@example.com']), 'fake');
	$orders->withLock($order->id(), fn (OrderData $o): OrderData => $o->with(['discountCode' => 'ONCE-EACH', 'paymentStatus' => 'paid']));

	// At the cart nobody has said who they are yet; checkout checks again with the email.
	expect(($this->resolve)('once-each', '')->amount)->toBeGreaterThan(0)
		->and(($this->resolve)('once-each', 'b@example.com')->amount)->toBeGreaterThan(0)
		->and(fn () => ($this->resolve)('once-each', 'A@example.com'))->toThrow(CommerceException::class);
});

it('takes a free-shipping code for a cart with something to ship', function (): void {
	seedDiscount('shipfree', ['type' => 'free_shipping']);
	$applied = ($this->resolve)('shipfree');

	expect($applied->freeShipping)->toBeTrue()->and($applied->amount)->toBe(0)->and($applied->lineAmounts)->toBe([]);
});
