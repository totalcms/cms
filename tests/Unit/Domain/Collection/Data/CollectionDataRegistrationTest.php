<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFactory;

/*
 * `publicRegistration` lets visitors register into the collection and
 * `registrationGroups` names the access groups each registrant receives.
 * Both are off/empty on an ordinary collection, always serialised, and must
 * round-trip like every other setting.
 */
describe('CollectionData registration settings', function (): void {
	it('defaults to off and no groups, and is always serialised', function (): void {
		$collection         = new CollectionData();
		$collection->id     = 'members';
		$collection->schema = 'auth';

		expect($collection->publicRegistration)->toBeFalse();
		expect($collection->registrationGroups)->toBe([]);
		expect($collection->toArray())->toHaveKey('publicRegistration', false)
			->toHaveKey('registrationGroups', []);
	});

	it('round-trips through toArray()', function (): void {
		$collection                     = new CollectionData();
		$collection->id                 = 'members';
		$collection->schema             = 'auth';
		$collection->publicRegistration = true;
		$collection->registrationGroups = ['members', 'newsletter'];

		$again = (new CollectionFactory())->generateCollection($collection->toArray());

		expect($again->publicRegistration)->toBeTrue();
		expect($again->registrationGroups)->toBe(['members', 'newsletter']);
	});

	it('reads both from input through the factory', function (): void {
		$collection = (new CollectionFactory())->generateCollection([
			'id'                 => 'members',
			'schema'             => 'auth',
			'publicRegistration' => true,
			'registrationGroups' => ['members'],
		]);

		expect($collection->publicRegistration)->toBeTrue();
		expect($collection->registrationGroups)->toBe(['members']);
		expect($collection->toArray()['publicRegistration'])->toBeTrue();
		expect($collection->toArray()['registrationGroups'])->toBe(['members']);
	});

	it('keeps the defaults for a payload that omits them', function (): void {
		$collection = (new CollectionFactory())->generateCollection(['id' => 'blog', 'schema' => 'blog']);

		expect($collection->publicRegistration)->toBeFalse();
		expect($collection->registrationGroups)->toBe([]);
	});
});
