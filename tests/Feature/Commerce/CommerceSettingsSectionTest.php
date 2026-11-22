<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;
use TotalCMS\Support\Config;

use function TotalCMS\Slim\Pest\get;

/*
 * The Commerce settings section, as the app ships it: its defaults, its place
 * in the settings list, and its form.
 */

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c = $this->app->getContainer();
});

test('the shipped defaults name every setting, and the reader agrees with them', function (): void {
	$defaults = $this->c->get(Config::class)->commerce;

	expect(array_keys($defaults))->toBe([
		'currency', 'storeName', 'storeEmail', 'collections', 'customerCollection', 'providers', 'orderNumberStart', 'orderNumberPrefix',
		'taxMode', 'taxShipping', 'taxRates', 'shippingMode', 'shippingFlatRate', 'shippingFreeOver', 'shippingZones', 'receiptMailer',
		'downloadTtlHours', 'downloadMaxCount', 'pendingOrderTtlHours', 'cartMaxLines', 'cartMaxQty', 'checkoutAttributes', 'returnPage',
		'purchaseUrl', 'checkoutMode', 'payPage', 'grantableGroups', 'paymentFailedMailer',
	])
		->and($defaults['collections'])->toBe(['products' => 'products', 'orders' => 'orders', 'transactions' => 'transactions', 'carts' => 'carts', 'subscriptions' => 'subscriptions', 'discounts' => 'discounts']);

	// A site with none of them set behaves exactly as the reader's own fallbacks say.
	$bare           = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$bare->commerce = [];
	$shipped        = $this->c->get(CommerceSettings::class);
	$fallback       = new CommerceSettings($bare);

	foreach (['currency', 'storeName', 'orderNumberStart', 'orderNumberPrefix', 'taxMode', 'taxShipping', 'taxRates', 'shippingMode', 'shippingFlatRate', 'shippingFreeOver', 'receiptMailer', 'paymentFailedMailer', 'downloadTtlHours', 'downloadMaxCount', 'pendingOrderTtlHours', 'cartMaxLines', 'cartMaxQty', 'checkoutMode', 'purchaseUrl', 'grantableGroups', 'enabledProviders'] as $accessor) {
		expect($shipped->{$accessor}())->toBe($fallback->{$accessor}(), $accessor);
	}
});

// That it is left out below Pro is pinned in AdminTwigAdapterSettingsSectionsTest.
test('Commerce is in the settings list on a Pro site', function (): void {
	$sections = $this->c->get(AdminTwigAdapter::class)->settingsSections();

	expect($sections)->toHaveKey('commerce')
		->and($sections['commerce']['label'])->toBe('Commerce')
		->and($sections['commerce']['description'])->not->toBe('settings.commerce_desc');
});

test('the tax rate and shipping zone rows have schemas of their own', function (string $schema, array $fields): void {
	expect(array_keys($this->c->get(SchemaFetcher::class)->fetchSchema($schema)->properties))->toBe($fields);
})->with([
	['commerce-tax-rate', ['id', 'country', 'region', 'rate', 'label']],
	['commerce-shipping-zone', ['id', 'label', 'countries', 'regions', 'rate', 'freeOver']],
]);

test('the settings form renders with a field for each setting an operator chooses', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	$html = (string)get('/admin/settings/commerce')->assertOk()->getBody();

	foreach (['currency', 'storeName', 'storeEmail', 'customerCollection', 'providers', 'orderNumberStart', 'orderNumberPrefix', 'taxMode', 'taxShipping', 'taxRates', 'shippingMode', 'shippingFlatRate', 'shippingFreeOver', 'shippingZones', 'receiptMailer', 'paymentFailedMailer', 'downloadTtlHours', 'downloadMaxCount', 'pendingOrderTtlHours', 'cartMaxLines', 'cartMaxQty', 'checkoutAttributes', 'returnPage', 'payPage', 'checkoutMode', 'grantableGroups'] as $field) {
		expect($html)->toContain('name="' . $field . '"', $field);
	}
});

test('every locale names the section', function (): void {
	foreach (glob(dirname(__DIR__, 3) . '/resources/translations/admin.*.php') ?: [] as $file) {
		$strings = require $file;

		expect($strings)->toHaveKeys(['settings.commerce', 'settings.commerce_desc'], basename($file));
	}
});
