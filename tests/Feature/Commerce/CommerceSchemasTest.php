<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLinter;
use TotalCMS\Support\Config;

/*
 * The schemas Commerce ships. What they hold is the contract every later part
 * of the engine writes against, and `serverOnly` is what stops anyone but the
 * engine from setting a total or a payment status.
 */

const COMMERCE_SCHEMA_IDS = [
	'commerce-product', 'commerce-variant', 'commerce-order', 'commerce-line-item', 'commerce-address',
	'commerce-transaction', 'commerce-cart', 'commerce-discount', 'commerce-subscription',
];

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->c = $this->app->getContainer();
});

/** @return array<string,array<string,mixed>> */
function commerceProperties(string $schema): array
{
	return test()->c->get(SchemaFetcher::class)->fetchSchema($schema)->properties;
}

/** @return list<string> the properties of $schema flagged serverOnly */
function commerceServerOnly(string $schema): array
{
	return array_keys(array_filter(commerceProperties($schema), fn (array $p): bool => ($p['serverOnly'] ?? false) === true));
}

test('the nine commerce schemas are named in one place, and all are reserved', function (): void {
	expect(SchemaData::COMMERCE_SCHEMAS)->toBe(COMMERCE_SCHEMA_IDS)
		->and(array_diff(SchemaData::COMMERCE_SCHEMAS, SchemaData::RESERVED_SCHEMAS))->toBe([])
		->and(SchemaData::COMMERCE_PRIVATE_SCHEMAS)->toBe(['commerce-order', 'commerce-transaction', 'commerce-cart', 'commerce-subscription'])
		->and(SchemaData::COMMERCE_SECRET_SCHEMAS)->toBe(['commerce-discount']);
});

test('every commerce schema loads', function (string $id): void {
	$schema = $this->c->get(SchemaFetcher::class)->fetchSchema($id);

	expect($schema->id)->toBe($id)
		->and($schema->category)->toBe('Commerce')
		->and($schema->description)->not->toBe('');
})->with(COMMERCE_SCHEMA_IDS);

test('every commerce schema passes the schema linter, with help on every property', function (string $id): void {
	$report = $this->c->get(SchemaLinter::class)->lint($id);

	expect($report['errors'])->toBe([])
		->and($report['warnings'])->toBe([]);
})->with(array_values(array_diff(COMMERCE_SCHEMA_IDS, ['commerce-address'])));

test('the address card requires nothing, as every shipped card schema does', function (): void {
	// A card has no id of its own to require, and an order that ships nowhere
	// has an empty address. The linter's one complaint is that empty list, the
	// same one it makes about the seo and feed cards.
	$report = $this->c->get(SchemaLinter::class)->lint('commerce-address');

	expect($report['errors'])->toBe(['Schema Validation Failed. (/required) Array should have at least 1 items, 0 found'])
		->and($report['warnings'])->toBe([])
		->and($this->c->get(SchemaLinter::class)->lint('seo')['errors'])->toBe($report['errors']);
});

test('every property has a label, so no form or table shows a raw key', function (string $id): void {
	foreach (commerceProperties($id) as $name => $property) {
		if (in_array($name, ['id', 'created', 'updated'], true)) {
			continue;
		}
		expect(trim((string)($property['label'] ?? '')))->not->toBe('', "{$id}.{$name} has no label");
	}
})->with(COMMERCE_SCHEMA_IDS);

test('only the engine sets money, status and identity on an order', function (): void {
	$serverOnly = commerceServerOnly('commerce-order');

	foreach ([
		'number', 'status', 'paymentStatus', 'fulfillmentStatus', 'needsAttention', 'currency', 'email', 'customerCollection', 'customerId',
		'lines', 'subtotal', 'discountTotal', 'shippingTotal', 'taxTotal', 'grandTotal', 'refundedTotal', 'taxMode', 'taxLines', 'shippingLine',
		'shippingAddress', 'billingAddress', 'provider', 'providerSessionRef', 'providerPaymentRef', 'attributes', 'metadata', 'flags',
		'accessToken', 'created', 'paidAt', 'updated',
		// Added 2026-11-20.
		'kind', 'subscriptionId', 'discountCode', 'discountLine', 'providerClientSecret',
	] as $key) {
		expect($serverOnly)->toContain($key);
	}

	// What is left: the id, which a schema must require and a server-only
	// property cannot be, and what staff write. None of it changes what was charged.
	expect(array_values(array_diff(array_keys(commerceProperties('commerce-order')), $serverOnly)))
		->toEqualCanonicalizing(['id', 'notes', 'trackingNumber', 'trackingUrl', 'tags']);
});

test('every amount on an order is a whole number of minor units', function (): void {
	$props = commerceProperties('commerce-order');

	foreach (['number', 'subtotal', 'discountTotal', 'shippingTotal', 'taxTotal', 'grandTotal', 'refundedTotal'] as $key) {
		expect($props[$key]['type'] ?? null)->toBe('integer', "{$key} must be an integer");
	}
});

test('only the engine writes any field of these schemas', function (string $id): void {
	// All but the id: see the order test above.
	expect(commerceServerOnly($id))->toEqualCanonicalizing(array_values(array_diff(array_keys(commerceProperties($id)), ['id'])));
})->with(['commerce-transaction', 'commerce-cart', 'commerce-subscription']);

test('staff write a discount code, and only the engine counts its uses', function (): void {
	expect(commerceServerOnly('commerce-discount'))->toBe(['usedCount']);
});

test('the indexes hold what the engine and the admin look orders up by', function (): void {
	$fetch = fn (string $id): array => $this->c->get(SchemaFetcher::class)->fetchSchema($id)->index;

	expect($fetch('commerce-order'))->toContain('number', 'email', 'paymentStatus', 'kind', 'subscriptionId', 'discountCode', 'providerPaymentRef')
		->and($fetch('commerce-product'))->toContain('price', 'status', 'billing')
		->and($fetch('commerce-transaction'))->toContain('orderId', 'providerEventId')
		->and($fetch('commerce-cart'))->toContain('customerId', 'status', 'orderId')
		->and($fetch('commerce-subscription'))->toContain('status', 'providerSubscriptionRef', 'currentPeriodEnd')
		->and($fetch('commerce-discount'))->toContain('active', 'usedCount', 'endsAt');
});

test('a product and a variant carry what a subscription and a membership need', function (): void {
	expect(array_keys(commerceProperties('commerce-product')))->toContain('billing', 'interval', 'intervalCount', 'trialDays', 'accessGroup')
		->and(array_keys(commerceProperties('commerce-variant')))->toContain('interval', 'intervalCount')
		->and(array_keys(commerceProperties('commerce-line-item')))->toContain('billing', 'interval', 'intervalCount', 'trialDays', 'discountTotal', 'accessGroup')
		->and(array_keys(commerceProperties('commerce-cart')))->toContain('discountCode')
		->and(array_keys(commerceProperties('commerce-address')))->toBe(['id', 'name', 'company', 'line1', 'line2', 'city', 'region', 'postalCode', 'country', 'phone']);
});

test('the product form offers exactly the groups Settings allows a product to grant', function (): void {
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'products', 'name' => 'Products', 'schema' => 'commerce-product']);
	$config = $this->c->get(Config::class);
	$config->commerce = array_merge($config->commerce, ['grantableGroups' => ['members', 'vip', 'admin']]);

	$html = $this->c->get(TotalFormFactory::class)->builder('products')->autoBuild();

	expect(preg_match('/<select[^>]*name="accessGroup"[^>]*>(.*?)<\/select>/s', $html, $select))->toBe(1);
	preg_match_all('/<option value="([^"]*)"/', $select[1], $options);

	// The admin group is never grantable, whatever the setting says.
	expect(array_values(array_filter($options[1])))->toBe(['members', 'vip']);
});

test('with no group allowed, the product form offers none', function (): void {
	$this->c->get(CollectionSaver::class)->saveCollection(['id' => 'products', 'name' => 'Products', 'schema' => 'commerce-product']);

	$html = $this->c->get(TotalFormFactory::class)->builder('products')->autoBuild();

	expect(preg_match('/<select[^>]*name="accessGroup"[^>]*>(.*?)<\/select>/s', $html, $select))->toBe(1);
	preg_match_all('/<option value="([^"]*)"/', $select[1], $options);

	expect(array_values(array_filter($options[1])))->toBe([]);
});
