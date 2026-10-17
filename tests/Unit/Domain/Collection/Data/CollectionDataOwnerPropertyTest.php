<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFactory;

/*
 * `ownerProperty` is the collection-level switch for owned collections: the
 * property whose value is the user id of the member who created the object.
 * It must round-trip through the serializer like every other setting, and an
 * ordinary collection must carry an empty value so nothing downstream scopes.
 */
describe('CollectionData ownerProperty', function (): void {
	it('defaults to empty and is always serialised', function (): void {
		$collection         = new CollectionData();
		$collection->id     = 'blog';
		$collection->schema = 'blog';

		expect($collection->ownerProperty)->toBe('');
		expect($collection->toArray()['ownerProperty'])->toBe('');
	});

	it('round-trips through the factory', function (): void {
		$collection = (new CollectionFactory())->generateCollection([
			'id'            => 'showcase',
			'schema'        => 'showcase',
			'ownerProperty' => 'owner',
		]);

		expect($collection->ownerProperty)->toBe('owner');
		expect($collection->toArray()['ownerProperty'])->toBe('owner');
	});
});

/*
 * `ownerCollection` narrows ownership to one auth collection. Empty means any
 * auth collection; like `ownerProperty` it must always be serialised.
 */
describe('CollectionData ownerCollection', function (): void {
	it('defaults to empty and is always serialised', function (): void {
		$collection         = new CollectionData();
		$collection->id     = 'blog';
		$collection->schema = 'blog';

		expect($collection->ownerCollection)->toBe('');
		expect($collection->toArray()['ownerCollection'])->toBe('');
	});

	it('round-trips through the factory', function (): void {
		$collection = (new CollectionFactory())->generateCollection([
			'id'              => 'showcase',
			'schema'          => 'showcase',
			'ownerProperty'   => 'owner',
			'ownerCollection' => 'designers',
		]);

		expect($collection->ownerCollection)->toBe('designers');
		expect($collection->toArray()['ownerCollection'])->toBe('designers');
	});
});
