<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\Address;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Order\TaxCalculator;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Money\Money;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Security\CSRF\CSRFTokenManager;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\post;
use function TotalCMS\Slim\Pest\postJson;
use function TotalCMS\Slim\Pest\withHeader;

/*
 * A store that leaves the tax to its payment provider (Stripe Tax).
 *
 * The store works out everything but the tax and asks the provider for
 * that much. The provider finds out where the buyer is, adds the tax it
 * works out, and says how much when the order is paid. The order then takes
 * its tax and its total from the payment.
 *
 * The mug is $12.50 and ships; the guide is a $9.00 download.
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

	$config           = $this->c->get(Config::class);
	$config->commerce = ['taxMode' => 'provider'] + $config->commerce;

	$this->fakeProvider->tax = true;

	withHeader('X-CSRF-Token', $this->c->get(CSRFTokenManager::class)->getToken());

	$this->add = function (string $productId, int $qty = 1): void {
		expect(postJson('/commerce/cart/add', ['productId' => $productId, 'variantId' => '', 'qty' => $qty])->getStatusCode())->toBe(200);
	};
	$this->buy   = fn (array $body = [], array $headers = []) => post('/commerce/checkout', $body + ['provider' => 'fake', 'email' => 'a@example.com'], $headers);
	$this->order = function (): OrderData {
		$rows = $this->c->get(IndexReader::class)->fetchIndex('orders')->objects;
		expect($rows)->toHaveCount(1);

		return $this->orders->find((string)$rows[0]['id']);
	};

	// The provider's word that an order was paid: what it charged, the tax
	// in that, and the total the store had asked it for.
	$this->paid = fn (OrderData $order, int $charged, ?int $tax, ?int $asked): PaymentEvent => new PaymentEvent(
		'fake',
		'evt_paid',
		PaymentEventType::Paid,
		$order->id(),
		Money::of($charged, 'USD'),
		'cs_1',
		'pi_1',
		tax: $tax === null ? null : Money::of($tax, 'USD'),
		orderTotal: $asked === null ? null : Money::of($asked, 'USD'),
	);
	$this->process = fn (PaymentEvent $event) => $this->c->get(PaymentEventProcessor::class)->process($event);
});

test('the store can leave the tax to the payment provider', function (): void {
	expect($this->c->get(CommerceSettings::class)->taxMode())->toBe('provider')
		->and($this->c->get(TaxCalculator::class)->mode())->toBe(TaxCalculator::PROVIDER);
});

describe('at checkout', function (): void {
	it('asks the provider for the order without tax, and to work the tax out', function (): void {
		($this->add)('guide');

		($this->buy)();
		$order   = ($this->order)();
		$request = $this->fakeProvider->checkouts[0];

		expect($order->taxMode())->toBe('provider')
			->and($order->taxTotal())->toBe(0)
			->and($order->grandTotal())->toBe(900)
			->and($request->providerTax)->toBeTrue()
			->and($request->total->amount())->toBe(900)
			->and($request->tax->amount())->toBe(0);
	});

	it('does not ask a buyer of a download where they are: the provider does', function (): void {
		($this->add)('guide');

		expect(($this->buy)([], ['Accept' => 'application/json'])->getStatusCode())->toBeIn([200, 201, 302, 303]);
	});

	it('leaves the "where you are" fields off the checkout form', function (): void {
		($this->add)('guide');
		$session = $this->c->get(PhpSession::class);
		$session->isStarted() || $session->start();

		expect(renderTwig('{{ cms.cart.checkoutForm() }}'))->not->toContain('billingAddress[country]');
	});

	it('hands the provider the address the order ships to, to tax it by', function (): void {
		($this->add)('mug');

		($this->buy)(['shippingAddress' => ['name' => 'Ada Buyer', 'line1' => '1 Main St', 'city' => 'Boise', 'region' => 'ID', 'postalCode' => '83702', 'country' => 'US']]);
		$shipTo = $this->fakeProvider->checkouts[0]->shipTo;

		expect($shipTo)->toBeInstanceOf(Address::class)
			->and($shipTo->line1)->toBe('1 Main St')
			->and($shipTo->region)->toBe('ID')
			->and($shipTo->country)->toBe('US');
	});

	it('tells the provider which products the store does not tax', function (): void {
		$this->c->get(ActorContext::class)->set(Actor::system());
		$this->c->get(ObjectSaver::class)->saveObject('products', ['id' => 'gift-card', 'title' => 'Gift Card', 'status' => 'active', 'type' => 'digital', 'price' => 20, 'requiresShipping' => false, 'taxable' => false]);
		$this->c->get(ActorContext::class)->set(Actor::anonymous());
		($this->add)('guide');
		($this->add)('gift-card');

		($this->buy)();

		expect(array_column($this->fakeProvider->checkouts[0]->lines, 'taxable', 'title'))->toBe(['Guide' => true, 'Gift Card' => false]);
	});

	it('hands over no address for an order with nothing to ship', function (): void {
		($this->add)('guide');
		($this->buy)();

		expect($this->fakeProvider->checkouts[0]->shipTo)->toBeNull();
	});

	it('asks for no tax from a provider that cannot work it out', function (): void {
		$this->fakeProvider->tax = false;
		($this->add)('guide');

		($this->buy)();

		expect($this->fakeProvider->checkouts[0]->providerTax)->toBeFalse();
	});

	it('asks for no tax when the store works out its own', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['taxMode' => 'exclusive'] + $config->commerce;
		($this->add)('mug');

		($this->buy)(['shippingAddress' => ['country' => 'US', 'region' => 'CA']]);

		expect($this->fakeProvider->checkouts[0]->providerTax)->toBeFalse()
			->and($this->fakeProvider->checkouts[0]->shipTo)->toBeNull();
	});
});

describe('when the order is paid', function (): void {
	beforeEach(function (): void {
		($this->add)('guide');
		($this->buy)();
		$this->placed = ($this->order)();
	});

	it('takes the tax and the total from the payment, when the tax was added on top', function (): void {
		($this->process)(($this->paid)($this->placed, 954, 54, 900));
		$order = ($this->order)();

		expect($order->paymentStatus())->toBe('paid')
			->and($order->taxTotal())->toBe(54)
			->and($order->grandTotal())->toBe(954)
			->and($order->taxMode())->toBe('exclusive')
			->and($order->taxLines())->toBe([['label' => 'Tax', 'rate' => '', 'amount' => 54]])
			->and($order->needsAttention())->toBe('');
	});

	it('keeps its total when the prices already held the tax', function (): void {
		($this->process)(($this->paid)($this->placed, 900, 75, 900));
		$order = ($this->order)();

		expect($order->paymentStatus())->toBe('paid')
			->and($order->taxTotal())->toBe(75)
			->and($order->grandTotal())->toBe(900)
			->and($order->taxMode())->toBe('inclusive');
	});

	it('is an order with no tax when the provider worked out none', function (): void {
		($this->process)(($this->paid)($this->placed, 900, 0, 900));
		$order = ($this->order)();

		expect($order->paymentStatus())->toBe('paid')
			->and($order->taxTotal())->toBe(0)
			->and($order->grandTotal())->toBe(900)
			->and($order->taxMode())->toBe('none')
			->and($order->taxLines())->toBe([]);
	});

	it('is paid with no tax when the provider says nothing of tax', function (): void {
		($this->process)(($this->paid)($this->placed, 900, null, null));
		$order = ($this->order)();

		expect($order->paymentStatus())->toBe('paid')
			->and($order->taxMode())->toBe('none')
			->and($order->grandTotal())->toBe(900);
	});

	it('is held for a person when what the provider was asked for is not the order\'s total', function (): void {
		($this->process)(($this->paid)($this->placed, 854, 54, 800));
		$order = ($this->order)();

		expect($order->paymentStatus())->toBe('review')
			->and($order->needsAttention())->toBe('amount_mismatch')
			->and($order->grandTotal())->toBe(900)
			->and($order->taxTotal())->toBe(0);
	});

	it('can then be refunded up to all that was paid, tax included', function (): void {
		($this->process)(($this->paid)($this->placed, 954, 54, 900));

		expect(($this->order)()->paidTotal())->toBe(954);
	});

	it('queues the receipt after the tax is on the order', function (): void {
		($this->process)(($this->paid)($this->placed, 954, 54, 900));

		expect(($this->order)()->hasFlag('receiptQueued'))->toBeTrue();
	});
});

test('a taxed payment held for review keeps its tax when staff accept it', function (): void {
	($this->add)('guide');
	($this->buy)();
	$placed = ($this->order)();
	// The store gave up on the checkout, and then the buyer paid it.
	($this->process)(new PaymentEvent('fake', 'evt_expired', PaymentEventType::Expired, $placed->id(), Money::of(900, 'USD'), 'cs_1'));
	($this->process)(($this->paid)($placed, 954, 54, 900));
	expect(($this->order)()->paymentStatus())->toBe('review');

	$this->c->get(PaymentEventProcessor::class)->accept($placed->id(), 'admin');
	$order = ($this->order)();

	expect($order->paymentStatus())->toBe('paid')
		->and($order->taxTotal())->toBe(54)
		->and($order->grandTotal())->toBe(954)
		->and($order->taxMode())->toBe('exclusive')
		->and($order->paidTotal())->toBe(954);
});

test('an order the store taxed itself is still checked against all that was charged', function (): void {
	$config           = $this->c->get(Config::class);
	$config->commerce = ['taxMode' => 'none'] + $config->commerce;
	($this->add)('guide');
	($this->buy)();
	$placed = ($this->order)();

	// A provider that added tax nobody asked it for charged more than the order.
	($this->process)(($this->paid)($placed, 954, 54, 900));

	expect(($this->order)()->paymentStatus())->toBe('review')
		->and(($this->order)()->needsAttention())->toBe('amount_mismatch');
});
