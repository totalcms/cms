<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Service\CollectionFetcher;

beforeEach(function (): void {
	recursiveDelete(cmsDataDir());

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
	$this->setUpApp(bootstrap());
});

it('creates the reserved automations collection on demand', function (): void {
	$fetcher = $this->app->getContainer()->get(CollectionFetcher::class);

	expect($fetcher->collectionExists('automations'))->toBeFalse();

	$fetcher->fetchOrCreateReserved('automations');

	expect($fetcher->collectionExists('automations'))->toBeTrue();
});

it('labels the automations collection Automation / Automations', function (): void {
	$fetcher = $this->app->getContainer()->get(CollectionFetcher::class);
	$collection = $fetcher->fetchOrCreateReserved('automations');

	$data = $collection->toArray();
	expect($data['labelSingular'])->toBe('Automation');
	expect($data['labelPlural'])->toBe('Automations');
});

it('loads the bundled automations schema with the externalized handler field', function (): void {
	$fetcher = $this->app->getContainer()->get(CollectionFetcher::class);
	$fetcher->fetchOrCreateReserved('automations');

	$schema = $this->app->getContainer()
		->get(\TotalCMS\Domain\Schema\Service\SchemaFetcher::class)
		->fetchSchemaForCollection('automations');

	expect($schema->properties)->toHaveKey('handler');
	expect($schema->properties['handler']['settings']['external'])->toBeTrue();
	expect($schema->properties)->toHaveKey('triggers');
});
