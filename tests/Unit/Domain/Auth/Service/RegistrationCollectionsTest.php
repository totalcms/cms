<?php

declare(strict_types=1);

use TotalCMS\Domain\Auth\Service\RegistrationCollections;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;

/** @param list<string> $groups */
function registrationCollection(string $id, bool $toggle = false, array $groups = [], string $schema = 'auth'): CollectionData
{
	$collection                     = new CollectionData();
	$collection->id                 = $id;
	$collection->schema             = $schema;
	$collection->publicRegistration = $toggle;
	$collection->registrationGroups = $groups;

	return $collection;
}

/**
 * @param list<string>                       $configured  `auth.publicRegistration`
 * @param array<string, CollectionData|null> $collections id => record returned by the fetcher
 */
function makeRegistrationCollections(array $configured = [], string $globalGroup = '', array $collections = [], ?Throwable $fetchThrows = null): RegistrationCollections
{
	$config       = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->auth = ['collection' => 'auth', 'publicRegistration' => $configured, 'publicRegistrationGroup' => $globalGroup];

	$fetcher = test()->createMock(CollectionFetcher::class);
	if ($fetchThrows !== null) {
		$fetcher->method('fetchCollection')->willThrowException($fetchThrows);
	} else {
		$fetcher->method('fetchCollection')->willReturnCallback(static fn (string $id): ?CollectionData => $collections[$id] ?? null);
	}

	// The real isOrInherits() over stubbed raw schemas: `member-profile`
	// inherits auth, `blog` does not, anything else cannot be read.
	$schemas = test()->createPartialMock(SchemaFetcher::class, ['fetchRawSchema']);
	$schemas->method('fetchRawSchema')->willReturnCallback(static function (string $id): SchemaData {
		$schema              = new SchemaData();
		$schema->inheritFrom = match ($id) {
			'member-profile' => ['auth'],
			'blog'           => [],
			default          => throw new RuntimeException("no schema {$id}"),
		};

		return $schema;
	});

	return new RegistrationCollections($config, $fetcher, $schemas);
}

describe('isEnabled', function (): void {
	it('is enabled by the config list alone', function (): void {
		$service = makeRegistrationCollections(['members'], collections: ['members' => registrationCollection('members')]);
		expect($service->isEnabled('members'))->toBeTrue();
	});

	it('is enabled by the collection toggle alone', function (): void {
		$service = makeRegistrationCollections([], collections: ['members' => registrationCollection('members', toggle: true)]);
		expect($service->isEnabled('members'))->toBeTrue();
	});

	it('ignores the toggle on a collection whose schema is not auth', function (): void {
		$service = makeRegistrationCollections([], collections: ['blog' => registrationCollection('blog', toggle: true, schema: 'blog')]);
		expect($service->isEnabled('blog'))->toBeFalse();
	});

	it('honours the toggle on a collection whose schema inherits auth', function (): void {
		$service = makeRegistrationCollections([], collections: ['members' => registrationCollection('members', toggle: true, schema: 'member-profile')]);
		expect($service->isEnabled('members'))->toBeTrue();
	});

	it('keeps a configured non-auth collection open: the config list never checked the schema', function (): void {
		$service = makeRegistrationCollections(['blog'], collections: ['blog' => registrationCollection('blog', schema: 'blog')]);
		expect($service->isEnabled('blog'))->toBeTrue();
	});

	it('isEnabledFor applies the same rule to a record in hand, without fetching', function (): void {
		$service = makeRegistrationCollections(['listed']);
		expect($service->isEnabledFor(registrationCollection('members', toggle: true)))->toBeTrue();
		expect($service->isEnabledFor(registrationCollection('members')))->toBeFalse();
		expect($service->isEnabledFor(registrationCollection('blog', toggle: true, schema: 'blog')))->toBeFalse();
		expect($service->isEnabledFor(registrationCollection('listed', schema: 'blog')))->toBeTrue();
	});

	it('is disabled when neither the config list nor the toggle opens it', function (): void {
		$service = makeRegistrationCollections(['customers'], collections: ['members' => registrationCollection('members')]);
		expect($service->isEnabled('members'))->toBeFalse();
	});

	it('keeps a configured collection open when its record is missing', function (): void {
		$service = makeRegistrationCollections(['members']);
		expect($service->isEnabled('members'))->toBeTrue();
	});

	it('treats a fetch that throws as toggle off', function (): void {
		$service = makeRegistrationCollections(['members'], fetchThrows: new RuntimeException('corrupt'));
		expect($service->isEnabled('members'))->toBeTrue();
		expect($service->isEnabled('broken'))->toBeFalse();
	});
});

describe('groupsFor', function (): void {
	it('gives only the global group when the collection adds none', function (): void {
		$service = makeRegistrationCollections(['members'], 'member', ['members' => registrationCollection('members')]);
		expect($service->groupsFor('members'))->toBe(['member']);
	});

	it('gives only the collection groups when no global group is set', function (): void {
		$service = makeRegistrationCollections([], '', ['members' => registrationCollection('members', true, ['vip', 'newsletter'])]);
		expect($service->groupsFor('members'))->toBe(['vip', 'newsletter']);
	});

	it('deduplicates the union of the global and collection groups', function (): void {
		$service = makeRegistrationCollections([], 'member', ['members' => registrationCollection('members', true, ['vip', 'member', 'vip'])]);
		expect($service->groupsFor('members'))->toBe(['member', 'vip']);
	});

	it('skips registration groups that are not non-empty strings', function (): void {
		$collection                     = registrationCollection('members', true);
		$collection->registrationGroups = ['vip', 42, '', null, 'newsletter']; // @phpstan-ignore assign.propertyType
		$service                        = makeRegistrationCollections([], 'member', ['members' => $collection]);
		expect($service->groupsFor('members'))->toBe(['member', 'vip', 'newsletter']);
	});

	it('never grants admin from the collection registration groups', function (): void {
		$service = makeRegistrationCollections([], '', ['members' => registrationCollection('members', true, ['admin', 'member'])]);
		expect($service->groupsFor('members'))->toBe(['member']);

		$withGlobal = makeRegistrationCollections([], 'subscriber', ['members' => registrationCollection('members', true, ['admin', 'member'])]);
		expect($withGlobal->groupsFor('members'))->toBe(['subscriber', 'member']);
	});

	it('gives no groups when neither is set', function (): void {
		$service = makeRegistrationCollections(['members'], '', ['members' => registrationCollection('members')]);
		expect($service->groupsFor('members'))->toBe([]);
	});
});
