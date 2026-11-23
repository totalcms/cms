<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Data\ActorKind;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Discount\DiscountData;
use TotalCMS\Domain\Commerce\Discount\DiscountRepository;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Object\Service\ObjectSaver;

/*
 * A discount code is an object whose id is the code. Checking one is a
 * single read, never a search, and the count of its uses is the store's to
 * write: staff cannot set it, and two orders paid at once both count.
 */

beforeEach(function (): void {
	bootCommerceStore();
	$this->c    = $this->app->getContainer();
	$this->repo = $this->c->get(DiscountRepository::class);
});

it('finds a code whatever case the buyer types', function (): void {
	seedDiscount('save10', ['type' => 'percent', 'value' => 10]);

	expect($this->repo->find(' Save10 ')?->id)->toBe('save10')
		->and($this->repo->find('SAVE10')?->code())->toBe('SAVE10')
		->and($this->repo->find('save10'))->toBeInstanceOf(DiscountData::class);
});

it('returns null for malformed and unknown codes', function (): void {
	expect([$this->repo->find('a b'), $this->repo->find('../x'), $this->repo->find('nope'), $this->repo->find('ab'), $this->repo->find(''), $this->repo->find(str_repeat('a', 41))])
		->toBe([null, null, null, null, null, null]);
});

it('says which codes are well formed', function (string $typed, ?string $id): void {
	expect($this->repo->normalize($typed))->toBe($id);
})->with([
	['SAVE10', 'save10'], ['  Fall-15 ', 'fall-15'], ['abc', 'abc'], [str_repeat('A', 40), str_repeat('a', 40)],
	['ab', null], ['a b', null], ['save_10', null], ['../x', null], ['', null], [str_repeat('a', 41), null], ["save10\n", 'save10'], ['sävé10', null],
]);

it('reads every field of a code', function (): void {
	seedDiscount('fall15', [
		'title' => 'Fall sale', 'type' => 'fixed', 'value' => 5.5, 'products' => ['tee'], 'categories' => ['Kitchen'], 'minSubtotal' => 30,
		'startsAt' => '2026-11-01T00:00:00+00:00', 'endsAt' => '2026-12-01T00:00:00+00:00', 'usageLimit' => 100, 'perCustomerLimit' => 2, 'recurring' => 'forever', 'usedCount' => 7,
	]);
	$d = $this->repo->find('fall15');

	expect($d->title)->toBe('Fall sale')
		->and($d->active)->toBeTrue()
		->and($d->type)->toBe('fixed')
		->and($d->value)->toBe('5.5')
		->and($d->products)->toBe(['tee'])
		->and($d->categories)->toBe(['Kitchen'])
		->and($d->minSubtotal)->toBe('30')
		->and($d->startsAt)->toBe(strtotime('2026-11-01T00:00:00+00:00'))
		->and($d->endsAt)->toBe(strtotime('2026-12-01T00:00:00+00:00'))
		->and($d->usageLimit)->toBe(100)
		->and($d->perCustomerLimit)->toBe(2)
		->and($d->recurring)->toBe('forever')
		->and($d->usedCount)->toBe(7);
});

it('reads a code left at its defaults as unlimited, with no dates and no minimum', function (): void {
	seedDiscount('plain');
	$d = $this->repo->find('plain');

	expect([$d->minSubtotal, $d->startsAt, $d->endsAt, $d->usageLimit, $d->perCustomerLimit, $d->recurring, $d->usedCount, $d->products, $d->categories])
		->toBe(['', null, null, 0, 0, 'once', 0, [], []]);
});

it('will not guess what a code of an unknown type is worth', function (): void {
	expect(fn () => DiscountData::fromObject(['id' => 'odd', 'type' => 'bogo', 'value' => 10]))->toThrow(InvalidArgumentException::class)
		->and(DiscountData::fromObject(['id' => 'odd', 'type' => 'percent', 'value' => 10, 'recurring' => 'sometimes'])->recurring)->toBe('once');
});

it('drops usedCount posted by staff', function (): void {
	$this->c->get(ActorContext::class)->set(new Actor(ActorKind::Admin, 'admin-user-test-com', 'auth'));
	$this->c->get(ObjectSaver::class)->saveObject('discounts', ['id' => 'staff', 'type' => 'percent', 'value' => 10, 'usedCount' => 99]);

	expect($this->repo->find('staff')?->usedCount)->toBe(0);
});

describe('counting a use', function (): void {
	beforeEach(function (): void {
		seedDiscount('save10');
		// A buyer's request: nobody in particular. The count is the store's own write.
		$this->c->get(ActorContext::class)->set(Actor::anonymous());
	});

	it('adds one each time', function (): void {
		$this->repo->recordUse('save10');
		$this->repo->recordUse('SAVE10');

		expect($this->repo->find('save10')->usedCount)->toBe(2);
	});

	it('counts from what is on disk now, not from what this request read earlier', function (): void {
		$this->repo->find('save10');
		$file           = cmsDataDir() . 'discounts/save10.json';
		$data           = json_decode((string)file_get_contents($file), true);
		$data['usedCount'] = 5;
		file_put_contents($file, json_encode($data));

		$this->repo->recordUse('save10');

		expect($this->repo->find('save10')->usedCount)->toBe(6);
	});

	it('holds the code\'s lock while it counts', function (): void {
		$this->repo->recordUse('save10');

		expect(is_file(cmsDataDir() . 'discounts/save10.lock'))->toBeTrue();
	});

	it('does nothing for a code that is not there', function (): void {
		$this->repo->recordUse('gone');
		$this->repo->recordUse('a b');

		expect($this->repo->find('gone'))->toBeNull();
	});
});

it('counts uses by email from paid orders only', function (): void {
	seedDiscount('save10');
	$orders = $this->c->get(OrderRepository::class);
	$make   = function (string $email, string $status, string $code = 'SAVE10') use ($orders): void {
		$order = $orders->create(commerceDraft(body: ['email' => $email]), 'fake');
		$orders->withLock($order->id(), fn (OrderData $o): OrderData => $o->with(['discountCode' => $code, 'paymentStatus' => $status]));
	};
	$make('a@example.com', 'paid');
	$make('A@Example.com', 'partially_refunded');
	$make('a@example.com', 'pending');
	$make('a@example.com', 'expired');
	$make('a@example.com', 'refunded');
	$make('a@example.com', 'paid', 'OTHER');
	$make('b@example.com', 'paid');

	expect($this->repo->usesByEmail('save10', 'a@example.com'))->toBe(2)
		->and($this->repo->usesByEmail('SAVE10', ' A@EXAMPLE.COM '))->toBe(2)
		->and($this->repo->usesByEmail('save10', 'b@example.com'))->toBe(1)
		->and($this->repo->usesByEmail('save10', 'c@example.com'))->toBe(0)
		->and($this->repo->usesByEmail('save10', ''))->toBe(0);
});
