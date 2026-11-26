<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Discount\DiscountRepository;
use TotalCMS\Domain\Commerce\Order\Address;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Order\ShippingCalculator;
use TotalCMS\Domain\Commerce\Order\ShippingRate;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectUpdater;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * A discount code at checkout. The cart keeps the code and nothing else;
 * the order is built with the amount worked out again, the provider is
 * asked for the discounted total, and a code's use is counted once, when
 * the order is paid.
 */

beforeEach(function (): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	bootCommerceStore();
	$this->c      = $this->app->getContainer();
	$this->fake   = $this->fakeProvider;
	$this->orders = $this->c->get(OrderRepository::class);
	$this->codes  = $this->c->get(DiscountRepository::class);
	$this->c->get(ActorContext::class)->set(Actor::anonymous());
	withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());

	$this->add = function (string $productId, string $variantId = '', int $qty = 1): void {
		expect(postJson('/commerce/cart/add', ['productId' => $productId, 'variantId' => $variantId, 'qty' => $qty])->getStatusCode())->toBe(200);
	};
	$this->buy  = fn (array $more = []) => post('/commerce/checkout', $more + ['provider' => 'fake', 'email' => 'a@example.com', 'shippingAddress' => ['country' => 'US']], ['Accept' => 'application/json']);
	$this->only = function (): OrderData {
		$rows = $this->c->get(IndexReader::class)->fetchIndex('orders')->objects->all();
		expect($rows)->toHaveCount(1);

		return $this->orders->find((string)array_values($rows)[0]['id']);
	};
	$this->code = fn ($response): string => (string)(json_decode((string)$response->getBody(), true)['error']['code'] ?? '');
	$this->pay  = fn (OrderData $order, string $eventId = 'evt_1') => $this->c->get(PaymentEventProcessor::class)->process(commercePaidEvent($order->id(), $order->grandTotal(), $eventId));
});

it('charges the discounted total and snapshots the split', function (): void {
	seedDiscount('fall15', ['type' => 'percent', 'value' => 15]);
	($this->add)('tee', 'large');
	($this->add)('mug');
	applyCode('fall15');
	$res   = ($this->buy)(['amount' => 1, 'discount' => 3249, 'discountTotal' => 3249]); // money from the browser is not read
	$order = ($this->only)();

	expect($res->getStatusCode())->toBe(200)
		->and($order->discountCode())->toBe('FALL15')
		->and($order->subtotal())->toBe(3249)
		->and($order->discountTotal())->toBe(487)
		->and($order->line('l1')['discountTotal'])->toBe(300)
		->and($order->line('l2')['discountTotal'])->toBe(187)
		->and($order->grandTotal())->toBe(2762)
		->and($order->discountLine())->toBe(['code' => 'FALL15', 'type' => 'percent', 'value' => '15', 'amount' => 487, 'shippingWaived' => 0, 'recurring' => 'once'])
		->and($this->fake->checkouts[0]->total->amount())->toBe(2762)
		->and($this->fake->checkouts[0]->lines[0]->discount->amount())->toBe(300)
		->and($this->fake->checkouts[0]->lines[1]->discount->amount())->toBe(187)
		->and($this->fake->checkouts[0]->lines[0]->recurringDiscount->amount())->toBe(0);
});

it('builds an order with no code exactly as before', function (): void {
	($this->add)('mug');
	($this->buy)();
	$order = ($this->only)();

	expect([$order->discountCode(), $order->discountTotal(), $order->discountLine(), $order->grandTotal()])->toBe(['', 0, [], 1250])
		->and($this->fake->checkouts[0]->lines[0]->discount->amount())->toBe(0);
});

it('refuses checkout when the code is no longer valid', function (): void {
	seedDiscount('fall15');
	($this->add)('mug');
	applyCode('fall15');
	$code           = $this->c->get(ObjectFetcher::class)->fetchObject('discounts', 'fall15')->toArray();
	$code['active'] = false;
	$this->c->get(ActorContext::class)->runAs(Actor::system(), fn () => $this->c->get(ObjectUpdater::class)->updateObject('discounts', 'fall15', $code));

	$res = ($this->buy)();

	expect($res->getStatusCode())->toBe(422)
		->and(($this->code)($res))->toBe('discount_invalid')
		->and($this->c->get(IndexReader::class)->fetchIndex('orders')->objects->all())->toBe([])
		->and($this->fake->checkouts)->toBe([]);
});

it('checks the per-customer limit against the checkout email', function (): void {
	seedDiscount('once-each', ['perCustomerLimit' => 1]);
	$earlier = $this->c->get(ActorContext::class)->runAs(Actor::system(), fn () => $this->orders->create(commerceDraft(body: ['email' => 'a@example.com']), 'fake'));
	$this->orders->withLock($earlier->id(), fn (OrderData $o): OrderData => $o->with(['discountCode' => 'ONCE-EACH', 'paymentStatus' => 'paid']));
	($this->add)('mug');
	applyCode('once-each');

	$again = ($this->buy)(['email' => 'A@Example.com']);
	$other = ($this->buy)(['email' => 'b@example.com']);

	expect($again->getStatusCode())->toBe(422)
		->and(($this->code)($again))->toBe('discount_invalid')
		->and($other->getStatusCode())->toBe(200);
});

it('counts a use once, when the order is paid', function (): void {
	seedDiscount('fall15');
	($this->add)('mug');
	applyCode('fall15');
	($this->buy)();
	$order   = ($this->only)();
	$pending = $this->codes->find('fall15')->usedCount;

	($this->pay)($order, 'evt_1');
	$paid = $this->codes->find('fall15')->usedCount;
	($this->pay)($order, 'evt_2');
	($this->pay)($order, 'evt_1');

	expect($pending)->toBe(0)
		->and($paid)->toBe(1)
		->and($this->codes->find('fall15')->usedCount)->toBe(1)
		->and($this->orders->find($order->id())->hasFlag('discountCounted'))->toBeTrue();
});

it('does not count a use for an order that expires', function (): void {
	seedDiscount('fall15');
	($this->add)('mug');
	applyCode('fall15');
	($this->buy)();
	$this->c->get(PaymentEventProcessor::class)->process(commercePaymentEvent(PaymentEventType::Expired, ($this->only)()->id()));

	expect($this->codes->find('fall15')->usedCount)->toBe(0)->and(($this->only)()->paymentStatus())->toBe('expired');
});

it('does not count a use for an order that had no code', function (): void {
	seedDiscount('fall15');
	($this->add)('mug');
	($this->buy)();
	($this->pay)(($this->only)());

	expect($this->codes->find('fall15')->usedCount)->toBe(0)->and(($this->only)()->hasFlag('discountCounted'))->toBeTrue();
});

it('sends a fully discounted one-time cart down the free path', function (): void {
	seedDiscount('free100', ['type' => 'percent', 'value' => 100]);
	($this->add)('mug');
	applyCode('free100');
	$res   = ($this->buy)();
	$order = ($this->only)();

	expect($res->getStatusCode())->toBe(200)
		->and($order->paymentStatus())->toBe('paid')
		->and($order->provider())->toBe('free')
		->and($order->grandTotal())->toBe(0)
		->and($order->discountTotal())->toBe(1250)
		->and($this->fake->checkouts)->toBe([])
		->and($this->codes->find('free100')->usedCount)->toBe(1);
});

it('prints the checkout form for a cart a code makes free, on a store with no way to pay', function (): void {
	$config           = $this->c->get(Config::class);
	$config->commerce = ['providers' => []] + $config->commerce;
	seedDiscount('free100', ['type' => 'percent', 'value' => 100]);
	seedDiscount('half', ['type' => 'percent', 'value' => 50]);
	($this->add)('mug');

	applyCode('half');
	$stillCosts = renderTwig('{{ cms.cart.checkoutForm() }}');
	applyCode('free100');
	$free = renderTwig('{{ cms.cart.checkoutForm() }}');

	expect($stillCosts)->toBe('')
		->and($free)->toContain('action="/commerce/checkout"', 'name="email"')
		->and($free)->not->toContain('name="provider"');
});

it('waives shipping with a free-shipping code', function (): void {
	$this->c->set(ShippingCalculator::class, new class implements ShippingCalculator {
		public function rates(array $lines, Money $subtotal, ?Address $address): array
		{
			return [new ShippingRate('flat', 'Flat rate', Money::of(500, $subtotal->currency()))];
		}
	});
	seedDiscount('shipfree', ['type' => 'free_shipping']);
	($this->add)('mug');
	applyCode('shipfree');
	($this->buy)();
	$order = ($this->only)();

	expect($order->shippingTotal())->toBe(0)
		->and($order->discountTotal())->toBe(0)
		->and($order->discountLine()['shippingWaived'])->toBe(500)
		->and($order->discountCode())->toBe('SHIPFREE')
		->and($order->grandTotal())->toBe(1250)
		->and($this->fake->checkouts[0]->shipping->amount())->toBe(0)
		->and($this->fake->checkouts[0]->total->amount())->toBe(1250);
});
