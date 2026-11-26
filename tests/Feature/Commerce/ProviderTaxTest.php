<?php

declare(strict_types=1);

use Odan\Session\PhpSession;
use Tests\Fakes\FakePaymentProvider;
use TotalCMS\Domain\Auth\Data\Actor;
use TotalCMS\Domain\Auth\Service\ActorContext;
use TotalCMS\Domain\Commerce\Order\Address;
use TotalCMS\Domain\Commerce\Order\OrderData;
use TotalCMS\Domain\Commerce\Order\OrderRepository;
use TotalCMS\Domain\Commerce\Order\TaxCalculator;
use TotalCMS\Domain\Commerce\Payment\PaymentEvent;
use TotalCMS\Domain\Commerce\Payment\PaymentEventProcessor;
use TotalCMS\Domain\Commerce\Payment\PaymentEventType;
use TotalCMS\Domain\Commerce\Payment\PaymentProviderRegistry;
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
 * Two settings say so: Tax is on (added to prices here), and it is worked
 * out by the provider when the provider can. The store then works out
 * everything but the tax and asks the provider for that much. The provider
 * finds out where the buyer is, adds the tax it works out, and says how
 * much when the order is paid. The order takes its tax and its total from
 * the payment.
 *
 * A provider that cannot work out tax gets the store's own table of rates:
 * California is taxed at 10%.
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
	$config->commerce = ['taxMode' => 'exclusive', 'taxBy' => 'provider', 'taxRates' => [['country' => 'US', 'region' => 'CA', 'rate' => '10', 'label' => 'CA sales tax']]] + $config->commerce;

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
	expect($this->c->get(CommerceSettings::class)->taxBy())->toBe('provider')
		->and($this->c->get(CommerceSettings::class)->taxMode())->toBe('exclusive');
});

test('unless told otherwise, the store works the tax out itself', function (): void {
	$config = $this->c->get(Config::class);
	unset($config->commerce['taxBy']);

	expect($this->c->get(CommerceSettings::class)->taxBy())->toBe('table');
});

test('only a provider that can is left the tax, and only while tax is on', function (bool $can, array $settings, bool $left): void {
	$this->fakeProvider->tax = $can;
	$config                  = $this->c->get(Config::class);
	$config->commerce        = $settings + $config->commerce;

	expect($this->c->get(PaymentProviderRegistry::class)->worksOutTax($this->fakeProvider))->toBe($left);
})->with([
	'it can, and the store asks it to'  => [true, [], true],
	'it cannot'                         => [false, [], false],
	'the store works out its own'       => [true, ['taxBy' => 'table'], false],
	'the store charges no tax'          => [true, ['taxMode' => 'none'], false],
]);

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
			->and($request->taxIncluded)->toBeFalse()
			->and($request->total->amount())->toBe(900)
			->and($request->tax->amount())->toBe(0);
	});

	it('tells the provider when the store\'s prices already hold the tax', function (): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['taxMode' => 'inclusive'] + $config->commerce;
		($this->add)('guide');

		($this->buy)();

		expect($this->fakeProvider->checkouts[0]->taxIncluded)->toBeTrue()
			->and($this->fakeProvider->checkouts[0]->total->amount())->toBe(900);
	});

	// The store's table would tax this address. The provider is working the
	// tax out, so the table's tax is left off the order and off every line.
	it('leaves the store\'s own tax off an order the provider will tax', function (): void {
		($this->add)('mug', 2);

		($this->buy)(['shippingAddress' => ['country' => 'US', 'region' => 'CA']]);
		$order   = ($this->order)();
		$request = $this->fakeProvider->checkouts[0];

		expect($order->taxMode())->toBe('provider')
			->and($order->taxTotal())->toBe(0)
			->and($order->taxLines())->toBe([])
			->and($order->grandTotal())->toBe(2500)
			->and(array_sum(array_column($order->lines(), 'taxTotal')))->toBe(0)
			->and($request->total->amount())->toBe(2500)
			->and($request->tax->amount())->toBe(0);
	});

	it('does not ask a buyer of a download where they are: the provider does', function (): void {
		($this->add)('guide');

		expect(($this->buy)([], ['Accept' => 'application/json'])->getStatusCode())->toBeIn([200, 201, 302, 303]);
	});

	it('leaves the "where you are" fields off the checkout form when every provider works out the tax', function (): void {
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
});

/*
 * PayPal cannot work out tax. An order paid with a provider like it is
 * taxed from the store's own table, as if the store did all its own tax.
 */
describe('with a provider that cannot work out tax', function (): void {
	beforeEach(function (): void {
		$this->fakeProvider->tax = false;
	});

	it('taxes the order from the store\'s own table, and asks the provider for the total with the tax', function (): void {
		($this->add)('mug', 2);

		($this->buy)(['shippingAddress' => ['country' => 'US', 'region' => 'CA']]);
		$order   = ($this->order)();
		$request = $this->fakeProvider->checkouts[0];

		expect($order->taxMode())->toBe('exclusive')
			->and($order->taxTotal())->toBe(250)
			->and($order->grandTotal())->toBe(2750)
			->and($request->providerTax)->toBeFalse()
			->and($request->shipTo)->toBeNull()
			->and($request->tax->amount())->toBe(250)
			->and($request->total->amount())->toBe(2750);
	});

	it('has to know where the buyer of a download is, as the table goes by that', function (): void {
		($this->add)('guide');

		$res = ($this->buy)([], ['Accept' => 'application/json']);

		expect((string)(json_decode((string)$res->getBody(), true)['error']['code'] ?? ''))->toBe('address_required')
			->and($this->fakeProvider->checkouts)->toBe([]);
	});

	it('needs the region too where a rate depends on it', function (): void {
		($this->add)('guide');

		$res = ($this->buy)(['billingAddress' => ['country' => 'US']], ['Accept' => 'application/json']);

		expect((string)(json_decode((string)$res->getBody(), true)['error']['code'] ?? ''))->toBe('region_required');
	});

	it('asks where the buyer is on the checkout form', function (): void {
		($this->add)('guide');
		$session = $this->c->get(PhpSession::class);
		$session->isStarted() || $session->start();

		expect(renderTwig('{{ cms.cart.checkoutForm() }}'))->toContain('billingAddress[country]', 'billingAddress[region]');
	});
});

/*
 * A store with both: one provider works the tax out, the other needs the
 * table. The form has the fields, and says which way to pay needs them, so
 * the page can put them away for a buyer the provider will ask itself.
 */
describe('with a provider of each kind', function (): void {
	beforeEach(function (): void {
		$plain = new FakePaymentProvider(id: 'plain');
		$this->c->get(PaymentProviderRegistry::class)->register($plain);
		$config           = $this->c->get(Config::class);
		$config->commerce = ['providers' => ['fake', 'plain']] + $config->commerce;
		($this->add)('guide');
		$session = $this->c->get(PhpSession::class);
		$session->isStarted() || $session->start();
		$this->form = renderTwig('{{ cms.cart.checkoutForm() }}');
	});

	it('has the fields, marked as the ones that depend on how the buyer pays', function (): void {
		expect($this->form)->toContain('billingAddress[country]', 'data-commerce-location');
	});

	it('says of each way to pay whether it works the tax out itself', function (): void {
		expect($this->form)->toMatch('/name="provider" value="fake"[^>]* data-works-out-tax/')
			->and($this->form)->not->toMatch('/name="provider" value="plain"[^>]* data-works-out-tax/');
	});

	it('does not ask the buyer who pays with the first for where they are', function (): void {
		expect(($this->buy)(['provider' => 'fake'], ['Accept' => 'application/json'])->getStatusCode())->toBeIn([200, 201, 302, 303])
			->and($this->fakeProvider->checkouts[0]->providerTax)->toBeTrue();
	});

	it('does ask the buyer who pays with the second', function (): void {
		$res = ($this->buy)(['provider' => 'plain'], ['Accept' => 'application/json']);

		expect((string)(json_decode((string)$res->getBody(), true)['error']['code'] ?? ''))->toBe('address_required');
	});
});

test('a store that works out all its own tax asks no provider for any', function (): void {
	$config           = $this->c->get(Config::class);
	$config->commerce = ['taxBy' => 'table'] + $config->commerce;
	($this->add)('mug');

	($this->buy)(['shippingAddress' => ['country' => 'US', 'region' => 'CA']]);

	expect($this->fakeProvider->checkouts[0]->providerTax)->toBeFalse()
		->and($this->fakeProvider->checkouts[0]->shipTo)->toBeNull()
		->and(($this->order)()->taxTotal())->toBe(125);
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
