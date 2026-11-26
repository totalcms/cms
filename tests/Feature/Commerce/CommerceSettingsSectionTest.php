<?php

declare(strict_types=1);

use TotalCMS\Domain\Commerce\Data\CommerceGateStatus;
use TotalCMS\Domain\Commerce\Service\CommerceSettings;
use TotalCMS\Domain\Commerce\Service\LicenseCommerceGate;
use TotalCMS\Domain\License\Service\Entitlements;
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
		'enabled', 'currency', 'collections', 'customerCollection', 'providers', 'orderNumberStart', 'orderNumberPrefix',
		'taxMode', 'taxBy', 'taxShipping', 'taxRates', 'shippingMode', 'shippingFlatRate', 'shippingFreeOver', 'shippingZones', 'receiptMailer',
		'downloadTtlHours', 'downloadMaxCount', 'lowStockAt', 'pendingOrderTtlHours', 'cartMaxLines', 'cartMaxQty', 'checkoutAttributes', 'returnPage',
		'checkoutMode', 'payPage', 'grantableGroups', 'paymentFailedMailer',
	])
		->and($defaults['collections'])->toBe(['products' => 'products', 'orders' => 'orders', 'transactions' => 'transactions', 'carts' => 'carts', 'subscriptions' => 'subscriptions', 'discounts' => 'discounts']);

	// A site with none of them set behaves exactly as the reader's own fallbacks say.
	$bare           = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$bare->commerce = [];
	$shipped        = $this->c->get(CommerceSettings::class);
	$fallback       = new CommerceSettings($bare);

	// The store name is not here: left empty it is the site's own name, which
	// CommerceSettingsTest pins.
	foreach (['enabled', 'currency', 'orderNumberStart', 'orderNumberPrefix', 'taxMode', 'taxBy', 'taxShipping', 'taxRates', 'shippingMode', 'shippingFlatRate', 'shippingFreeOver', 'receiptMailer', 'paymentFailedMailer', 'downloadTtlHours', 'downloadMaxCount', 'lowStockAt', 'pendingOrderTtlHours', 'cartMaxLines', 'cartMaxQty', 'checkoutMode', 'grantableGroups', 'enabledProviders'] as $accessor) {
		expect($shipped->{$accessor}())->toBe($fallback->{$accessor}(), $accessor);
	}
});

// The license says a site may have a store. The site says whether it wants one.
test('a site whose license carries Commerce has no store until the switch is turned on', function (): void {
	$this->c->set(Entitlements::class, entitlements(['totalcms/commerce']));
	$gate = $this->c->make(LicenseCommerceGate::class);

	expect($gate->status())->toBe(CommerceGateStatus::Off);

	// Read when asked: saving the setting opens the store without a restart.
	$config           = $this->c->get(Config::class);
	$config->commerce = ['enabled' => true] + $config->commerce;

	expect($gate->status())->toBe(CommerceGateStatus::Allowed);
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

	// The providers are a box to tick for each one installed, and this site
	// has none: the field is there, with nothing in it to name.
	expect($html)->toContain('data-type="checklist" style="--grid-area: providers;');

	foreach (['currency', 'customerCollection', 'orderNumberStart', 'orderNumberPrefix', 'taxMode', 'taxBy', 'taxShipping', 'taxRates', 'shippingMode', 'shippingFlatRate', 'shippingFreeOver', 'shippingZones', 'receiptMailer', 'paymentFailedMailer', 'downloadTtlHours', 'downloadMaxCount', 'pendingOrderTtlHours', 'cartMaxLines', 'cartMaxQty', 'checkoutAttributes', 'returnPage', 'payPage', 'checkoutMode', 'grantableGroups'] as $field) {
		expect($html)->toContain('name="' . $field . '"', $field);
	}
});

// A country is chosen by name. The row stores its two-letter code, which is
// what an address is matched by, so nobody has to know that Germany is DE.
test('a tax rate\'s country is chosen from a list of countries, with one choice for everywhere', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	$html = (string)get('/admin/settings/commerce')->assertOk()->getBody();

	preg_match('/<select[^>]*name="country"[^>]*>(.*?)<\/select>/s', $html, $select);
	$options = $select[1] ?? '';

	expect($options)->toContain('>United States</option>', 'value="US"', 'value="DE"', '>Germany</option>')
		// Everywhere comes first, and is what a new row starts as. The form
		// writes an empty value as a bare `value`.
		->and(preg_match('/^\s*<option value(="")?>Everywhere<\/option>/', $options))->toBe(1);
});

// A tax rate is a few short values, edited in place. A shipping zone holds
// lists of countries and regions, which need the room of a dialog.
test('tax rates are a table and shipping zones are a deck', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	$html = (string)get('/admin/settings/commerce')->assertOk()->getBody();

	expect($html)->toContain('data-type="deckTable" style="--grid-area: taxRates;')
		->toContain('data-type="deck" style="--grid-area: shippingZones;');
});

// A zone's id is made for it and hidden, so the deck names each zone by the
// label the owner gave it, and its form is laid out and not one long column.
test('a shipping zone is named by its label and has a layout of its own', function (): void {
	$schema = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/resources/schemas/settings/commerce.json'), true);
	$zone   = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/resources/schemas/commerce-shipping-zone.json'), true);

	expect($schema['properties']['shippingZones']['settings']['deckItemLabel'] ?? null)->toBe('${label}')
		->and($zone['formgrid'] ?? '')->toBe("label label\ncountries regions\nrate freeOver");
});

// A row's id is the store's own business: it is made for the row, named for
// what the row is, and never shown.
test('a row\'s id is made for it and kept out of sight', function (string $schema, string $pattern, string $heading): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');
	$id = $this->c->get(SchemaFetcher::class)->fetchSchema($schema)->properties['id'];

	expect($id['settings']['autogen'] ?? null)->toBe($pattern)
		->and($id['settings']['hide'] ?? null)->toBeTrue()
		->and((string)get('/admin/settings/commerce')->getBody())->not->toContain('>' . $heading . '</span>');
})->with([
	['commerce-tax-rate', 'tax_${uid}', 'Rate ID'],
	['commerce-shipping-zone', 'zone_${uid}', 'Zone ID'],
]);

// Countries are picked by name from the same list everywhere.
test('a shipping zone\'s countries are chosen from the list of countries', function (): void {
	$countries = $this->c->get(SchemaFetcher::class)->fetchSchema('commerce-shipping-zone')->properties['countries'];

	expect($countries['field'])->toBe('list')
		->and($countries['settings']['propertyOptions'] ?? null)->toBe('countries');
});

// A multiselect that offers the site's access groups. A click adds or
// removes a group (javascript/totalform/multiselect.js), with no key to hold.
test('the groups a product may grant are chosen from the site\'s access groups', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	$html = (string)get('/admin/settings/commerce')->assertOk()->getBody();

	expect($html)->toContain('data-type="multiselect" style="--grid-area: grantableGroups;')
		->toContain('<select id=')
		->and(preg_match('/<select[^>]*name="grantableGroups"[^>]*multiple/', $html))->toBe(1);
});

describe('the settings form', function (): void {
	beforeEach(function (): void {
		signInAs($this->app, 'admin-user-test-com', 'auth');
		$this->html  = (string)get('/admin/settings/commerce')->assertOk()->getBody();
		$this->field = function (string $name): string {
			preg_match('/<div class="form-field[^>]*--grid-area: ' . $name . ';.*?(?=<div class="form-field|<h3 |<\/form>)/s', $this->html, $found);

			return $found[0] ?? '';
		};
	});

	// The switch for the whole store is the first thing on the page, and off until someone turns it on.
	test('Enable Commerce is a toggle at the top, off on a new site', function (): void {
		$toggle = ($this->field)('enabled');

		expect($toggle)->toContain('Enable Commerce')
			->and($toggle)->toContain('type="checkbox"')
			->and($toggle)->not->toMatch('/<input[^>]*name="enabled"[^>]*\\schecked/')
			->and(strpos($this->html, '--grid-area: enabled;'))->toBeLessThan(strpos($this->html, '--grid-area: currency;'));
	});

	// A currency is chosen by name. The store keeps its three-letter code.
	test('the store currency is chosen from the currencies in use today', function (): void {
		$currency = ($this->field)('currency');

		expect($currency)->toContain('data-type="select"', '<option value="USD" selected>US Dollar (USD)</option>', '>Euro (EUR)</option>', '>Japanese Yen (JPY)</option>')
			// The German mark went out of use in 2002.
			->not->toContain('value="DEM"');
	});

	test('the customer accounts collection is chosen from the collections people sign in to', function (): void {
		$customers = ($this->field)('customerCollection');

		expect($customers)->toContain('data-type="select"', 'value="auth"')
			->not->toContain('value="products"');
	});

	test('a choice between two or three things shows every choice', function (string $name, array $labels): void {
		expect(($this->field)($name))->toContain('data-type="radio"', 'type="radio"', ...$labels);
	})->with([
		['checkoutMode', ["On the provider's page", 'On this site']],
		['taxMode', ['No tax', 'Added to prices', 'Included in prices']],
		['shippingMode', ['No shipping', 'Flat rate', 'By zone']],
	]);

	// One under the other, so each choice is read on its own line.
	test('the choices of a radio setting are stacked', function (string $name): void {
		expect(($this->field)($name))->toContain('data-type="radio"')->not->toContain('--fieldset-grid-size');
	})->with(['checkoutMode', 'taxMode', 'shippingMode']);

	// A setting that does nothing with the choice made above it is not shown.
	test('the tax and shipping settings show only what the choice above them uses', function (string $name, string $watch, string $operator, string|array $value): void {
		$schema     = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/resources/schemas/settings/commerce.json'), true);
		$visibility = $schema['properties'][$name]['settings']['visibility'] ?? null;

		expect($visibility)->toBe(['watch' => $watch, 'value' => $value, 'operator' => $operator])
			// And the form is told, so it can show and hide the field as the choice changes.
			->and(($this->field)($name))->toContain('&quot;visibility&quot;');
	})->with([
		'Tax Shipping, when tax is charged'      => ['taxShipping', 'taxMode', '!=', 'none'],
		// Always there with tax on: the rates are the store's way, or what a provider that cannot work out tax falls back on.
		'Tax Rates, when tax is charged'         => ['taxRates', 'taxMode', '!=', 'none'],
		'Tax Worked Out By, when tax is charged' => ['taxBy', 'taxMode', '!=', 'none'],
		'Flat Rate, for a flat rate'             => ['shippingFlatRate', 'shippingMode', '==', 'flat'],
		'Free Shipping Over, for a flat rate'    => ['shippingFreeOver', 'shippingMode', '==', 'flat'],
		'Shipping Zones, by zone'                => ['shippingZones', 'shippingMode', '==', 'zones'],
	]);

	// Whether there is tax, and who works it out, are two questions.
	test('tax can be left to the payment provider, as a setting of its own', function (): void {
		expect(($this->field)('taxBy'))->toContain('data-type="radio"', 'value="table"', 'value="provider"', 'The payment provider, when it can')
			->and(($this->field)('taxMode'))->not->toContain('value="provider"');
	});

	test('an amount of money is a price field', function (string $name): void {
		expect(($this->field)($name))->toContain('data-type="price"');
	})->with(['shippingFlatRate', 'shippingFreeOver']);

	// It was the address in one refusal message. Nobody needs to change it.
	test('there is no link to add Commerce to set', function (): void {
		expect($this->html)->not->toContain('name="purchaseUrl"');
	});

	test('the payment page is offered only when checkout is on this site, where it is used', function (): void {
		$schema = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/resources/schemas/settings/commerce.json'), true);

		expect($schema['properties']['payPage']['settings']['visibility'] ?? null)->toBe(['watch' => 'checkoutMode', 'value' => 'embedded', 'operator' => '==']);
	});

	// A store sells in one currency, and its amounts are typed in it.
	test('the amounts are in the store\'s currency', function (string $name): void {
		$config           = $this->c->get(Config::class);
		$config->commerce = ['currency' => 'GBP'] + $config->commerce;
		$html             = (string)get('/admin/settings/commerce')->getBody();

		preg_match('/<div class="form-field[^>]*--grid-area: ' . $name . ';.*?(?=<div class="form-field|<h3 |<\/form>)/s', $html, $found);

		expect($found[0] ?? '')->toContain('&quot;currency&quot;:&quot;GBP&quot;', '£');
	})->with(['shippingFlatRate', 'shippingFreeOver']);

	// Order Page first: it is always there. Payment Page comes and goes with
	// the Checkout choice, and on the right its going leaves no gap.
	test('the two pages a store can replace sit side by side, Order Page first', function (): void {
		$schema = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/resources/schemas/settings/commerce.json'), true);

		expect($schema['formgrid'])->toContain("returnPage payPage\n");
	});
});

test('a shipping zone\'s amounts are price fields', function (string $name): void {
	expect($this->c->get(SchemaFetcher::class)->fetchSchema('commerce-shipping-zone')->properties[$name]['field'])->toBe('price');
})->with(['rate', 'freeOver']);

// The store goes by the site's name (Settings → General). A second name to
// keep in step with it would be one more thing to get wrong.
test('there is no store name to set: the store goes by the site\'s name', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	expect((string)get('/admin/settings/commerce')->getBody())->not->toContain('name="storeName"');
});

// Who a reply goes to is set where every other email's is: on the email's
// own template, under Mailer.
test('there is no store email to set: a receipt\'s reply-to is set on its template', function (): void {
	signInAs($this->app, 'admin-user-test-com', 'auth');

	expect((string)get('/admin/settings/commerce')->getBody())->not->toContain('name="storeEmail"');
});

test('every locale names the section', function (): void {
	foreach (glob(dirname(__DIR__, 3) . '/resources/translations/admin.*.php') ?: [] as $file) {
		$strings = require $file;

		expect($strings)->toHaveKeys(['settings.commerce', 'settings.commerce_desc'], basename($file));
	}
});
