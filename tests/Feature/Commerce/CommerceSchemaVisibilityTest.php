<?php

declare(strict_types=1);

use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;

/*
 * Commerce is part of core, so its schemas are on every install. A site
 * without the Commerce add-on has no use for them: they are left out of every
 * list a person picks a schema from. They are hidden, not removed: a schema is
 * still read by its id, so nothing that already uses one breaks.
 */

const HIDDEN_COMMERCE_SCHEMAS = [
	'commerce-product', 'commerce-variant', 'commerce-order', 'commerce-line-item', 'commerce-address', 'commerce-transaction',
	'commerce-cart', 'commerce-discount', 'commerce-subscription', 'commerce-tax-rate', 'commerce-shipping-zone', 'commerce-collections',
	'commerce-product-base', 'commerce-product-subscription', 'commerce-product-inventory', 'commerce-product-variants', 'commerce-product-digital',
];

/** @param array<SchemaData> $schemas */
function commerceIdsIn(array $schemas): array
{
	$ids = array_map(static fn (SchemaData $schema): string => $schema->id, $schemas);

	return array_values(array_intersect(HIDDEN_COMMERCE_SCHEMAS, $ids));
}

test('every commerce schema is named as one', function (string $id): void {
	expect(SchemaData::isCommerceSchema($id))->toBeTrue();
})->with(HIDDEN_COMMERCE_SCHEMAS);

test('no other schema is', function (string $id): void {
	expect(SchemaData::isCommerceSchema($id))->toBeFalse();
})->with(['blog', 'auth', 'preset-item', 'commerce', '']);

describe('on a site without the Commerce add-on', function (): void {
	beforeEach(function (): void {
		bootCommerceStore(open: false);
		$this->lister = $this->app->getContainer()->get(SchemaLister::class);
	});

	test('no list of schemas shows one', function (): void {
		expect(commerceIdsIn($this->lister->listReservedSchemas()))->toBe([])
			->and(commerceIdsIn($this->lister->listAllSchemas()))->toBe([])
			->and($this->lister->listCategories())->not->toContain('Commerce');
	});

	test('the other reserved schemas are all still listed', function (): void {
		expect(count($this->lister->listReservedSchemas()))->toBe(count($this->lister->listShippedSchemas()) - count(HIDDEN_COMMERCE_SCHEMAS));
	});

	test('a commerce schema is still read by its id, and its name is still taken', function (): void {
		expect($this->app->getContainer()->get(SchemaFetcher::class)->fetchSchema('commerce-order')->id)->toBe('commerce-order')
			->and($this->lister->isReservedSchema('commerce-order'))->toBeTrue();
	});

	test('the integrity check still covers every schema file that ships', function (): void {
		expect(commerceIdsIn($this->lister->listShippedSchemas()))->toBe(HIDDEN_COMMERCE_SCHEMAS);
	});
});

describe('on a site with the Commerce add-on', function (): void {
	beforeEach(function (): void {
		bootCommerceStore(open: true);
		$this->lister = $this->app->getContainer()->get(SchemaLister::class);
	});

	test('every list shows them', function (): void {
		expect(commerceIdsIn($this->lister->listReservedSchemas()))->toBe(HIDDEN_COMMERCE_SCHEMAS)
			->and(commerceIdsIn($this->lister->listAllSchemas()))->toBe(HIDDEN_COMMERCE_SCHEMAS)
			->and($this->lister->listCategories())->toContain('Commerce');
	});
});
