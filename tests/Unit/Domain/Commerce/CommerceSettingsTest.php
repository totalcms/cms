<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Support\Config;

// The store's settings, read through one class so that a missing, mistyped or
// hand-edited value becomes a safe one in one place.

beforeEach(function (): void {
	/** @param array<string,mixed> $commerce */
	$this->settings = function (array $commerce = []): CommerceSettings {
		$config           = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->commerce = $commerce;

		return new CommerceSettings($config);
	};
});

it('has a working default for everything', function (): void {
	$s = ($this->settings)();

	expect($s->currency())->toBe('USD')
		->and($s->collection('products'))->toBe('products')
		->and($s->collection('orders'))->toBe('orders')
		->and($s->collection('transactions'))->toBe('transactions')
		->and($s->collection('carts'))->toBe('carts')
		->and($s->collection('subscriptions'))->toBe('subscriptions')
		->and($s->collection('discounts'))->toBe('discounts')
		->and($s->customerCollection())->toBe('')
		->and($s->enabledProviders())->toBe([])
		->and($s->orderNumberStart())->toBe(1001)
		->and($s->orderNumberPrefix())->toBe('#')
		->and($s->taxMode())->toBe('none')
		->and($s->taxShipping())->toBeFalse()
		->and($s->taxRates())->toBe([])
		->and($s->shippingMode())->toBe('none')
		->and($s->shippingFlatRate())->toBe('0')
		->and($s->shippingFreeOver())->toBe('')
		->and($s->shippingZones())->toBe([])
		->and($s->receiptMailer())->toBe('commerce-receipt')
		->and($s->paymentFailedMailer())->toBe('commerce-payment-failed')
		->and($s->storeName())->toBe('')
		->and($s->storeEmail())->toBe('')
		->and($s->downloadTtlHours())->toBe(72)
		->and($s->downloadMaxCount())->toBe(5)
		->and($s->pendingOrderTtlHours())->toBe(24)
		->and($s->cartMaxLines())->toBe(100)
		->and($s->cartMaxQty())->toBe(99)
		->and($s->checkoutAttributes())->toBe([])
		->and($s->returnPage())->toBe('')
		->and($s->payPage())->toBe('')
		->and($s->checkoutMode())->toBe('hosted')
		->and($s->grantableGroups())->toBe([])
		->and($s->purchaseUrl())->toBe('https://totalcms.co/commerce');
});

it('reads what the site set', function (): void {
	$s = ($this->settings)([
		'currency'             => 'eur',
		'storeName'            => 'Acme Coffee',
		'storeEmail'           => 'shop@acme.example',
		'collections'          => ['orders' => 'shop-orders'],
		'customerCollection'   => 'members',
		'providers'            => ['stripe', 'paypal'],
		'orderNumberStart'     => 5000,
		'orderNumberPrefix'    => 'AC-',
		'taxMode'              => 'exclusive',
		'taxShipping'          => true,
		'shippingMode'         => 'flat',
		'shippingFlatRate'     => '4.95',
		'shippingFreeOver'     => '50',
		'receiptMailer'        => 'my-receipt',
		'paymentFailedMailer'  => 'my-failed',
		'downloadTtlHours'     => 24,
		'downloadMaxCount'     => 3,
		'pendingOrderTtlHours' => 2,
		'cartMaxLines'         => 20,
		'cartMaxQty'           => 10,
		'checkoutAttributes'   => ['licenseKey', 'giftNote'],
		'returnPage'           => 'thanks',
		'payPage'              => 'pay',
		'checkoutMode'         => 'embedded',
		'purchaseUrl'          => 'https://reseller.example/commerce',
	]);

	expect($s->currency())->toBe('EUR')
		->and($s->storeName())->toBe('Acme Coffee')
		->and($s->storeEmail())->toBe('shop@acme.example')
		// One collection renamed; the others keep their defaults.
		->and($s->collection('orders'))->toBe('shop-orders')
		->and($s->collection('products'))->toBe('products')
		->and($s->customerCollection())->toBe('members')
		->and($s->enabledProviders())->toBe(['stripe', 'paypal'])
		->and($s->orderNumberStart())->toBe(5000)
		->and($s->orderNumberPrefix())->toBe('AC-')
		->and($s->taxMode())->toBe('exclusive')
		->and($s->taxShipping())->toBeTrue()
		->and($s->shippingMode())->toBe('flat')
		->and($s->shippingFlatRate())->toBe('4.95')
		->and($s->shippingFreeOver())->toBe('50')
		->and($s->receiptMailer())->toBe('my-receipt')
		->and($s->paymentFailedMailer())->toBe('my-failed')
		->and($s->downloadTtlHours())->toBe(24)
		->and($s->downloadMaxCount())->toBe(3)
		->and($s->pendingOrderTtlHours())->toBe(2)
		->and($s->cartMaxLines())->toBe(20)
		->and($s->cartMaxQty())->toBe(10)
		->and($s->checkoutAttributes())->toBe(['licenseKey', 'giftNote'])
		->and($s->returnPage())->toBe('thanks')
		->and($s->payPage())->toBe('pay')
		->and($s->checkoutMode())->toBe('embedded')
		->and($s->purchaseUrl())->toBe('https://reseller.example/commerce');
});

it('refuses a collection role it does not know', fn () => ($this->settings)()->collection('nope'))->throws(InvalidArgumentException::class);

it('falls back to USD for anything that is not a currency code', function (mixed $currency): void {
	expect(($this->settings)(['currency' => $currency])->currency())->toBe('USD');
})->with(['dollars', 'US', '', 12, null]);

it('falls back to a safe mode for a value it does not know', function (): void {
	$s = ($this->settings)(['taxMode' => 'vat', 'shippingMode' => 'drone', 'checkoutMode' => 'popup']);

	expect($s->taxMode())->toBe('none')
		->and($s->shippingMode())->toBe('none')
		->and($s->checkoutMode())->toBe('hosted');
});

it('never lets the admin group be granted, whatever the setting says', function (): void {
	expect(($this->settings)(['grantableGroups' => ['members', 'admin', 'members', ' vip ', '', 7]])->grantableGroups())->toBe(['members', 'vip']);
});

it('reads tax rates and shipping zones whether they were saved as a list or by id', function (): void {
	$rate = ['country' => 'US', 'region' => 'NY', 'rate' => '8.875', 'label' => 'NY sales tax'];
	$zone = ['id' => 'eu', 'label' => 'Europe', 'countries' => ['DE', 'FR'], 'regions' => [], 'rate' => '12.00', 'freeOver' => '100'];

	expect(($this->settings)(['taxRates' => [$rate]])->taxRates())->toBe([$rate])
		// The settings form saves a deck: items keyed by their id.
		->and(($this->settings)(['taxRates' => ['ny' => ['id' => 'ny'] + $rate]])->taxRates())->toBe([$rate])
		->and(($this->settings)(['shippingZones' => ['eu' => $zone]])->shippingZones())->toBe([$zone]);
});

it('gives every tax rate its four fields as strings, and drops what is not a rate', function (): void {
	$rates = ($this->settings)(['taxRates' => [
		['country' => 'us', 'rate' => 8.5],
		'not a rate',
		['country' => 'DE', 'region' => '', 'rate' => '19', 'label' => 'MwSt'],
	]])->taxRates();

	expect($rates)->toBe([
		['country' => 'US', 'region' => '', 'rate' => '8.5', 'label' => ''],
		['country' => 'DE', 'region' => '', 'rate' => '19', 'label' => 'MwSt'],
	]);
});

it('keeps numbers inside sensible bounds', function (): void {
	$s = ($this->settings)(['orderNumberStart' => -5, 'cartMaxQty' => 0, 'cartMaxLines' => -1, 'downloadTtlHours' => 0, 'downloadMaxCount' => -2, 'pendingOrderTtlHours' => 0]);

	expect($s->orderNumberStart())->toBe(1)
		->and($s->cartMaxQty())->toBe(1)
		->and($s->cartMaxLines())->toBe(1)
		->and($s->downloadTtlHours())->toBe(1)
		->and($s->downloadMaxCount())->toBe(1)
		->and($s->pendingOrderTtlHours())->toBe(1);
});

it('drops anything that is not a plain name from a list of names', function (): void {
	$s = ($this->settings)(['providers' => ['stripe', '', 5, ['x'], 'stripe', ' paypal '], 'checkoutAttributes' => ['licenseKey', null, 'licenseKey']]);

	expect($s->enabledProviders())->toBe(['stripe', 'paypal'])
		->and($s->checkoutAttributes())->toBe(['licenseKey']);
});
